<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Deletion is local-first and reversible for as long as the resulting delete_issue push-queue
 * rows stay pending: this marks the issue (and, for cascadeChildren, every descendant) unavailable
 * immediately and enqueues the real GitHub deletion for the scheduled push worker, rather than
 * calling GitHub's deleteIssue mutation synchronously and irreversibly from the request itself.
 * See UndoDeleteTodoIssue for the reversal.
 */
class DeleteTodoIssue
{
    /**
     * @return array{deletedIds: list<int>, reparented: list<array{childId: int, previousParentId: ?int, previousParentGithubNodeId: ?string}>}
     */
    public function handle(Issue $issue, bool $cascadeChildren): array
    {
        if (! $issue->is_available) {
            throw new TodoValidationException('The selected task is not available in the local snapshot. Refresh and try again.');
        }

        return DB::transaction(function () use ($issue, $cascadeChildren): array {
            $reparented = $cascadeChildren ? [] : $this->promoteChildren($issue);
            $deletedIds = $cascadeChildren ? $this->cascadeDelete($issue) : [];

            $issue->update(['is_available' => false]);
            app(EnqueueGitHubPush::class)->handle('delete_issue', 'issue', $issue->id, [], 'issue:delete:'.$issue->id.':'.now()->timestamp);

            return ['deletedIds' => [...$deletedIds, $issue->id], 'reparented' => $reparented];
        });
    }

    /** @return list<int> */
    private function cascadeDelete(Issue $issue): array
    {
        $deletedIds = [];
        foreach ($issue->children()->where('is_available', true)->get() as $child) {
            $deletedIds = [...$deletedIds, ...$this->cascadeDelete($child)];
            $child->update(['is_available' => false]);
            app(EnqueueGitHubPush::class)->handle('delete_issue', 'issue', $child->id, [], 'issue:delete:'.$child->id.':'.now()->timestamp);
            $deletedIds[] = $child->id;
        }

        return $deletedIds;
    }

    /** @return list<array{childId: int, previousParentId: ?int, previousParentGithubNodeId: ?string}> */
    private function promoteChildren(Issue $issue): array
    {
        $newParent = $issue->parent_issue_id !== null ? Issue::query()->find($issue->parent_issue_id) : null;
        $reparented = [];
        foreach ($issue->children()->where('is_available', true)->get() as $child) {
            $reparented[] = ['childId' => $child->id, 'previousParentId' => $child->parent_issue_id, 'previousParentGithubNodeId' => $child->github_parent_node_id];

            if ($newParent instanceof Issue) {
                $siblingPosition = (int) Issue::query()->where('parent_issue_id', $newParent->id)->max('sibling_position') + 1;
                $child->update(['parent_issue_id' => $newParent->id, 'github_parent_node_id' => $newParent->github_node_id, 'sibling_position' => $siblingPosition]);
                app(EnqueueGitHubPush::class)->handle('set_issue_parent', 'issue', $child->id, ['parent_issue_id' => $newParent->id], 'issue:parent:'.$child->id.':'.now()->timestamp);

                continue;
            }

            $previousParentGithubNodeId = $child->github_parent_node_id;
            $child->update(['parent_issue_id' => null, 'github_parent_node_id' => null, 'sibling_position' => 0]);
            if ($previousParentGithubNodeId !== null) {
                app(EnqueueGitHubPush::class)->handle('remove_issue_parent', 'issue', $child->id, ['parent_github_node_id' => $previousParentGithubNodeId], 'issue:remove_parent:'.$child->id.':'.now()->timestamp);
            }
        }

        return $reparented;
    }
}
