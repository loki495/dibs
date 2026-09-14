<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Deletion is local-first and reversible at any time, not just immediately after: this marks the
 * issue (and, for cascadeChildren, every descendant) unavailable right away and enqueues the real
 * GitHub deletion for the scheduled push worker, rather than calling GitHub's deleteIssue mutation
 * synchronously and irreversibly from the request itself. See RestoreTodoIssue for the reversal --
 * it works whether the push is still pending or has already reached GitHub.
 */
class DeleteTodoIssue
{
    /** @return list<int> ids of every issue marked deleted (the target, plus cascaded children) */
    public function handle(Issue $issue, bool $cascadeChildren): array
    {
        if (! $issue->is_available) {
            throw new TodoValidationException('The selected task is not available in the local snapshot. Refresh and try again.');
        }

        return DB::transaction(function () use ($issue, $cascadeChildren): array {
            if (! $cascadeChildren) {
                $this->promoteChildren($issue);
            }
            $deletedIds = $cascadeChildren ? $this->cascadeDelete($issue) : [];

            $issue->update(['is_available' => false]);
            app(EnqueueGitHubPush::class)->handle('delete_issue', 'issue', $issue->id, [], 'issue:delete:'.$issue->id.':'.now()->timestamp);

            return [...$deletedIds, $issue->id];
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

    private function promoteChildren(Issue $issue): void
    {
        $newParent = $issue->parent_issue_id !== null ? Issue::query()->find($issue->parent_issue_id) : null;
        foreach ($issue->children()->where('is_available', true)->get() as $child) {
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
    }
}
