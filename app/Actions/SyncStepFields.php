<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Sets a plan step's fields (Done-when, Evidence, Files likely) and replaces its blocked-by list. The body is
 * never touched. A blocker must be an available sibling (same parent); self, cross-plan and cyclic edges are refused
 * and nothing is changed.
 */
class SyncStepFields
{
    /** @var array<string, string> argument key => issues column */
    public const array FIELDS = ['doneWhen' => 'done_when', 'evidence' => 'evidence', 'filesLikely' => 'files_likely'];

    public function __construct(private readonly ActivityRecorder $recorder) {}

    /**
     * @param  array<string, ?string>  $step  only the keys given are changed; null or blank clears a field
     * @param  list<int>|null  $blockedBy  the whole new list; null leaves it alone
     */
    public function handle(Issue $issue, array $step = [], ?array $blockedBy = null): void
    {
        if ($step === [] && $blockedBy === null) {
            return;
        }

        DB::transaction(function () use ($issue, $step, $blockedBy): void {
            $changes = [];
            foreach (array_intersect_key($step, self::FIELDS) as $key => $value) {
                $column = self::FIELDS[$key];
                $value = trim((string) $value) === '' ? null : trim((string) $value);
                if ($issue->{$column} !== $value) {
                    $changes[$column] = ['from' => $issue->{$column}, 'to' => $value];
                    $issue->{$column} = $value;
                }
            }
            if ($changes !== []) {
                $issue->save();
            }

            if ($blockedBy !== null) {
                $blockedBy = array_values(array_unique($blockedBy));
                $this->assertBlockers($issue, $blockedBy);
                $current = $issue->blockedBy()->pluck('issues.id')->sort()->values()->all();
                $sorted = $blockedBy;
                sort($sorted);
                if ($current !== $sorted) {
                    $issue->blockedBy()->sync($blockedBy);
                    $changes['blocked_by'] = ['from' => $current === [] ? null : implode(', ', $current), 'to' => $sorted === [] ? null : implode(', ', $sorted)];
                }
            }

            if ($changes !== []) {
                $this->recorder->change('SyncStepFields', $issue, 'Changed step fields', $changes);
            }
        });
    }

    /** @param  list<int>  $blockedBy */
    private function assertBlockers(Issue $issue, array $blockedBy): void
    {
        foreach ($blockedBy as $id) {
            if ($id === $issue->id) {
                throw new TodoValidationException("Step {$issue->id} cannot be blocked by itself.");
            }
            $blocker = Issue::query()->where('is_available', true)->find($id);
            if (! $blocker instanceof Issue) {
                throw new TodoValidationException("Cannot block step {$issue->id} on issue {$id}: it does not exist or is no longer available.");
            }
            if ($issue->is_temporary || $blocker->is_temporary) {
                throw new TodoValidationException('Temporary tasks cannot participate in dependency edges.');
            }
            if ($issue->parent_issue_id === null || $blocker->parent_issue_id !== $issue->parent_issue_id) {
                throw new TodoValidationException("Cannot block step {$issue->id} on issue {$id}: blocked-by only links sibling steps of the same plan.");
            }
            if ($this->reaches($id, $issue->id)) {
                throw new TodoValidationException("Cannot block step {$issue->id} on issue {$id}: issue {$id} already waits on step {$issue->id}, which would make a cycle.");
            }
        }
    }

    /** Whether $from waits, directly or through other steps, on $target. */
    private function reaches(int $from, int $target): bool
    {
        $seen = [];
        $stack = [$from];
        while ($stack !== []) {
            $id = array_pop($stack);
            if ($id === $target) {
                return true;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            array_push($stack, ...DB::table('step_blockers')->where('issue_id', $id)->pluck('blocked_by_issue_id')->all());
        }

        return false;
    }
}
