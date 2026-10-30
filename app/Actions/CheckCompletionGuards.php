<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\Label;
use App\Support\RealReason;
use App\Support\StepTemplate;
use App\Support\TrustedAuthors;

/**
 * What an agent's completion still lacks under the task's guard profile (see config/dibs.php
 * `guards`). Read-only: CompleteTodoTask refuses on a non-empty list unless the caller forces it.
 */
class CheckCompletionGuards
{
    public function __construct(private readonly TrustedAuthors $trust) {}

    public const array PROFILES = ['floor', 'standard', 'strict'];

    private const string SHA = '/^[0-9a-f]{7,40}$/i';

    /**
     * @param  array<string, mixed>  $result  {commit, tests, docs, done_when_held}, see docs/agent-interface.md
     * @return array{profile: string, missing: list<string>}
     */
    public function handle(Issue $issue, ?string $reason, ?string $summary, array $result): array
    {
        $issue->loadMissing(['labels', 'parent.labels', 'projectItems.groupOption']);
        $profile = $this->profile($issue);
        $missing = [];

        if (! in_array($profile, self::PROFILES, true)) {
            return ['profile' => $profile, 'missing' => ["guard profile \"{$profile}\" is unknown; use one of ".implode(', ', self::PROFILES).' in the Guards: line or DIBS_GUARDS_*']];
        }
        if (trim((string) $summary) === '') {
            $missing[] = 'summary: say what was done (or, for NOT_PLANNED, why not); it becomes the closing note';
        }
        if ($reason === CloseTodoIssue::REASON_NOT_PLANNED || $profile === 'floor') {
            return ['profile' => $profile, 'missing' => $missing];
        }

        $labels = $issue->labels->where('is_available', true)->map(fn (Label $label): string => $label->name)->all();
        $isStep = $issue->parent instanceof Issue && $issue->parent->labels->contains(fn (Label $label): bool => $label->name === 'plan' && $label->is_available);
        $strict = $profile === 'strict';

        $commit = $result['commit'] ?? null;
        $hasSha = is_string($commit) && preg_match(self::SHA, $commit) === 1;
        if ($commit === null) {
            if ($isStep || in_array('agent task', $labels, true) || in_array('bug', $labels, true)) {
                $missing[] = 'result.commit: pass the commit SHA, or "none: <why no commit>"';
            }
        } elseif (! $hasSha) {
            $missing = [...$missing, ...$this->none('result.commit', $commit, 'a 7-40 character commit SHA')];
        }

        $tests = $result['tests'] ?? null;
        if ($tests === null) {
            if ($hasSha) {
                $missing[] = 'result.tests: pass {command, exit_code} for the test run, or "none: <why no tests>"';
            }
        } elseif (is_array($tests)) {
            if (! is_string($tests['command'] ?? null) || trim($tests['command']) === '' || ! is_int($tests['exit_code'] ?? null)) {
                $missing[] = 'result.tests: needs a non-empty command and an integer exit_code';
            } elseif ($tests['exit_code'] !== 0) {
                $missing[] = "result.tests: exit_code {$tests['exit_code']} means the tests failed; fix them and rerun";
            }
        } else {
            $missing = [...$missing, ...$this->none('result.tests', $tests, '{command, exit_code}', $strict)];
        }

        $docs = $result['docs'] ?? null;
        if ($docs === null) {
            if ($hasSha) {
                $missing[] = 'result.docs: list the docs updated, or "none: <why no doc changed>"';
            }
        } elseif (is_array($docs)) {
            if ($docs === [] || array_filter($docs, fn ($doc): bool => ! is_string($doc) || trim($doc) === '') !== []) {
                $missing[] = 'result.docs: list at least one doc path, or "none: <why no doc changed>"';
            }
        } else {
            $missing = [...$missing, ...$this->none('result.docs', $docs, 'a list of doc paths', $strict)];
        }

        if ($isStep) {
            if (in_array('Done-when', StepTemplate::missing($issue->body, $issue), true)) {
                $missing[] = 'the step has no Done-when (field or body line); set step.doneWhen with todo_revise before completing';
            }
            if (($result['done_when_held'] ?? null) !== true) {
                $missing[] = 'result.done_when_held: pass true once the step\'s Done-when holds';
            }
        }

        return ['profile' => $profile, 'missing' => $missing];
    }

    /** @return list<string> */
    private function none(string $field, mixed $value, string $expected, bool $refuse = false): array
    {
        if (! is_string($value) || preg_match('/^\s*none\s*:(.*)$/is', $value, $match) !== 1) {
            return ["{$field}: expected {$expected}, or \"none: <why>\""];
        }
        if ($refuse) {
            return ["{$field}: the strict profile does not accept \"none\"; give {$expected}"];
        }
        $problem = RealReason::problem($match[1]);

        return $problem === null ? [] : ["{$field}: \"none\" needs a real reason: {$problem}"];
    }

    /** Most specific wins: the task's Guards: line, its parent's, its Group's setting, the default. A body by an untrusted GitHub author is ignored, so it cannot lower the guard. */
    private function profile(Issue $issue): string
    {
        foreach ([$issue, $issue->parent] as $source) {
            if ($source instanceof Issue && $this->trust->allows($source->author_login)
                && preg_match('/^[\s>#*_-]*guards[^:\n]*:[ \t]*([a-z]+)/mi', (string) $source->body, $match) === 1) {
                return mb_strtolower($match[1]);
            }
        }
        $group = $issue->projectItems->where('is_available', true)->first()?->groupOption?->name;
        $groups = (array) config('dibs.guards.groups', []);

        return (string) ($group !== null && isset($groups[mb_strtolower($group)]) ? $groups[mb_strtolower($group)] : config('dibs.guards.default', 'standard'));
    }
}
