<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;

class MoveIssueUnderParent
{
    public function __construct(private readonly EnqueueGitHubPush $enqueue) {}

    /** Set or clear an issue's parent locally and queue the matching GitHub change. */
    public function handle(Issue $issue, ?Issue $parent): void
    {
        if ($parent instanceof Issue) {
            if ($issue->parent_issue_id === $parent->id) {
                return;
            }
            $position = (int) Issue::query()->where('parent_issue_id', $parent->id)->max('sibling_position') + 1;
            $issue->update(['parent_issue_id' => $parent->id, 'sibling_position' => $position]);
            $this->enqueue->handle('set_issue_parent', 'issue', $issue->id, ['parent_issue_id' => $parent->id], 'issue:parent:'.$issue->id.':'.now()->timestamp);

            return;
        }

        if ($issue->parent_issue_id === null) {
            return;
        }
        $previousParentNodeId = $issue->github_parent_node_id;
        $issue->update(['parent_issue_id' => null, 'github_parent_node_id' => null, 'sibling_position' => 0]);
        if ($previousParentNodeId !== null) {
            $this->enqueue->handle('remove_issue_parent', 'issue', $issue->id, ['parent_github_node_id' => $previousParentNodeId], 'issue:remove_parent:'.$issue->id.':'.now()->timestamp);
        }
    }
}
