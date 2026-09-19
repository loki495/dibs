<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use App\Services\Activity\ActivityRecorder;

class MoveIssueUnderParent
{
    public function __construct(
        private readonly EnqueueGitHubPush $enqueue,
        private readonly ActivityRecorder $recorder,
    ) {}

    /** Set or clear an issue's parent locally and queue the matching GitHub change. */
    public function handle(Issue $issue, ?Issue $parent): void
    {
        $previousParentId = $issue->parent_issue_id;

        if ($parent instanceof Issue) {
            if ($previousParentId === $parent->id) {
                return;
            }
            $this->assertNoCycle($issue, $parent);
            $position = (int) Issue::query()->where('parent_issue_id', $parent->id)->max('sibling_position') + 1;
            $issue->update(['parent_issue_id' => $parent->id, 'sibling_position' => $position]);
            $this->enqueue->handle('set_issue_parent', 'issue', $issue->id, ['parent_issue_id' => $parent->id], 'issue:parent:'.$issue->id.':'.now()->timestamp);
            $this->recorder->change(
                'MoveIssueUnderParent',
                $issue,
                $previousParentId === null ? 'Set parent' : 'Changed parent',
                ['parent' => ['from' => $this->recorder->issueRef($previousParentId), 'to' => $this->recorder->issueRef($parent->id)]],
            );

            return;
        }

        if ($previousParentId === null) {
            return;
        }
        $previousParentNodeId = $issue->github_parent_node_id;
        $issue->update(['parent_issue_id' => null, 'github_parent_node_id' => null, 'sibling_position' => 0]);
        if ($previousParentNodeId !== null) {
            $this->enqueue->handle('remove_issue_parent', 'issue', $issue->id, ['parent_github_node_id' => $previousParentNodeId], 'issue:remove_parent:'.$issue->id.':'.now()->timestamp);
        }
        $this->recorder->change('MoveIssueUnderParent', $issue, 'Cleared parent', ['parent' => ['from' => $this->recorder->issueRef($previousParentId), 'to' => null]]);
    }

    /**
     * Walks up from the proposed parent: reaching the issue itself means the parent is one of its own
     * descendants. Follows every ancestor, available or not, since a hidden one still forms the cycle.
     *
     * @throws TodoValidationException
     */
    private function assertNoCycle(Issue $issue, Issue $parent): void
    {
        if ($issue->is($parent)) {
            throw new TodoValidationException('A task cannot be its own parent.');
        }

        $seen = [$parent->id => true];
        $currentId = $parent->parent_issue_id;
        while ($currentId !== null) {
            if ($currentId === $issue->id) {
                throw new TodoValidationException('This parent would create a hierarchy cycle: it is already a sub-task of this task.');
            }
            if (isset($seen[$currentId])) {
                throw new TodoValidationException('The local hierarchy already contains a cycle. Refresh before editing it.');
            }
            $seen[$currentId] = true;
            $next = Issue::query()->whereKey($currentId)->value('parent_issue_id');
            $currentId = $next === null ? null : (int) $next;
        }
    }
}
