<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\Issue;
use App\Support\StepTemplate;
use App\Support\TrustedAuthors;
use Illuminate\Support\Facades\DB;

/**
 * The capped brief a session needs to start one plan step cold: objective, Done-when, Evidence, Files likely,
 * blockers, linked rules, other links and the last checkpoint. With an agent name (from todo_claim), each linked
 * rule's full text is included only if that identity has not yet received the rule at its current revision, and
 * the delivery is recorded; without one (todo_next's preview) rules come as id and title only.
 */
class DescribeWorkOrder
{
    public const int MAX_CHARS = 6000;

    /** Rule statements have their own budget: the rest of the brief stays under MAX_CHARS whatever the rules add. */
    public const int RULE_TEXT_MAX_CHARS = 12000;

    public const int OBJECTIVE_LENGTH = 800;

    public const int CHECKPOINT_LENGTH = 600;

    public const string RULE_LABEL = 'rule';

    private const string RULE_METADATA = '/^(?:Trigger|Check|Applies to|Source|Merged candidates):/m';

    public function __construct(private readonly TrustedAuthors $trust, private readonly DescribeIssueLinks $links) {}

    /** @return array<string, mixed> */
    public function handle(Issue $step, ?string $agentName = null, bool $contextReset = false): array
    {
        $agentName = $agentName === null ? null : trim($agentName);
        if ($agentName !== null && $contextReset) {
            DB::table('delivered_rules')->where('agent_name', $agentName)->delete();
        }

        $body = $this->trust->allows($step->author_login) ? $step->body : null;
        $values = StepTemplate::values($step, $body);
        $plan = $step->parent;

        $rules = [];
        $otherLinks = [];
        foreach ($this->links->outgoing($step) as $link) {
            if ($link['commentId'] === null && in_array(self::RULE_LABEL, $link['labels'], true)) {
                $rules[$link['id']] = ['id' => $link['id'], 'title' => $link['title']];
            } else {
                $otherLinks[] = ['id' => $link['id'], 'commentId' => $link['commentId'], 'title' => $link['title'], 'labels' => $link['labels']];
            }
        }

        $checkpoint = $step->comments()->where('is_available', true)->where(fn ($query) => $query->whereNull('kind')->orWhere('kind', '!=', Comment::KIND_CLOSING))
            ->latest('id')->first();

        $order = [
            'step' => ['id' => $step->id, 'title' => $this->trust->title($step), 'temporary' => (bool) $step->is_temporary, 'revision' => $step->revision, 'state' => $step->state],
            'plan' => $plan instanceof Issue ? ['id' => $plan->id, 'title' => $this->trust->title($plan), 'temporary' => (bool) $plan->is_temporary] : null,
            'objective' => $values['objective'] ?? ($body === null ? null : $this->clip($body, self::OBJECTIVE_LENGTH)),
            'tier' => $values['tier'],
            'repos' => $values['repos'] ?? ($plan instanceof Issue && $this->trust->allows($plan->author_login) ? StepTemplate::inline($plan->body, 'repos?') : null),
            'doneWhen' => $values['doneWhen'],
            'evidence' => $values['evidence'],
            'filesLikely' => $values['filesLikely'],
            'blockedBy' => $step->blockedBy()->where('is_available', true)->get()
                ->map(fn (Issue $blocker): array => ['id' => $blocker->id, 'title' => $this->trust->title($blocker), 'state' => $blocker->state])->all(),
            'rules' => array_values($rules),
            'otherLinks' => $otherLinks,
            'lastCheckpoint' => $checkpoint instanceof Comment && $this->trust->allows($checkpoint->author_login)
                ? ['id' => $checkpoint->id, 'createdAt' => $checkpoint->remote_created_at?->toIso8601String(), 'text' => $this->clip((string) $checkpoint->body, self::CHECKPOINT_LENGTH)]
                : null,
            'truncated' => false,
        ];

        if ($agentName !== null && $rules !== []) {
            $order = $this->withRuleText($order, $agentName, array_keys($rules));
        }

        return $this->capped($order);
    }

    /**
     * Adds the full text of each rule this identity has not yet received at its current revision, while the brief
     * stays under the cap, and records each one sent. Only the rule statement is sent, not its Trigger/Check/Applies
     * to/Source/Merged candidates lines. A rule left out for space stays undelivered and is listed in rulesPending.
     *
     * @param  array<string, mixed>  $order
     * @param  list<int>  $ruleIds
     * @return array<string, mixed>
     */
    private function withRuleText(array $order, string $agentName, array $ruleIds): array
    {
        $delivered = DB::table('delivered_rules')->where('agent_name', $agentName)->whereIn('rule_issue_id', $ruleIds)->pluck('revision', 'rule_issue_id')->all();
        $issues = Issue::query()->whereIn('id', $ruleIds)->get()->keyBy('id');

        $pending = [];
        $used = 0;
        foreach ($order['rules'] as $index => $rule) {
            $issue = $issues->get($rule['id']);
            $order['rules'][$index]['revision'] = $issue?->revision;
            if (! $issue instanceof Issue || ! $this->trust->allows($issue->author_login) || ($delivered[$issue->id] ?? null) === $issue->revision) {
                continue;
            }
            $text = $this->statement((string) $issue->body);
            if ($used + mb_strlen($text) > self::RULE_TEXT_MAX_CHARS) {
                $order['truncated'] = true;
                $pending[] = $issue->id;

                continue;
            }
            $order['rules'][$index]['text'] = $text;
            $used += mb_strlen($text);
            DB::table('delivered_rules')->upsert(
                [['agent_name' => $agentName, 'rule_issue_id' => $issue->id, 'revision' => $issue->revision, 'created_at' => now(), 'updated_at' => now()]],
                ['agent_name', 'rule_issue_id'],
                ['revision', 'updated_at'],
            );
        }

        if ($pending !== []) {
            $order['rulesPending'] = $pending;
            $order['hint'] = 'todo_show these rule ids, or their text arrives on your next todo_claim.';
        }

        return $order;
    }

    private function statement(string $body): string
    {
        $statement = trim((string) preg_split(self::RULE_METADATA, $body, 2)[0]);

        return $statement === '' ? trim($body) : $statement;
    }

    /**
     * Keeps the brief under MAX_CHARS by shortening the longest free-text parts, then dropping other links.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function capped(array $order): array
    {
        foreach ([['lastCheckpoint', 'text'], ['objective'], ['filesLikely'], ['evidence'], ['doneWhen']] as $path) {
            if ($this->length($order) <= self::MAX_CHARS) {
                return $order;
            }
            $value = count($path) === 2 ? ($order[$path[0]][$path[1]] ?? null) : $order[$path[0]];
            if (is_string($value) && mb_strlen($value) > 200) {
                $short = $this->clip($value, 200);
                count($path) === 2 ? $order[$path[0]][$path[1]] = $short : $order[$path[0]] = $short;
                $order['truncated'] = true;
            }
        }
        while ($this->length($order) > self::MAX_CHARS && $order['otherLinks'] !== []) {
            array_pop($order['otherLinks']);
            $order['truncated'] = true;
        }

        return $order;
    }

    /** @param  array<string, mixed>  $order */
    private function length(array $order): int
    {
        $order['rules'] = array_map(fn (array $rule): array => array_diff_key($rule, ['text' => true]), $order['rules']);

        return mb_strlen((string) json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function clip(string $text, int $length): string
    {
        return mb_strimwidth(trim($text), 0, $length, '…');
    }
}
