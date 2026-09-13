<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Reverses DeleteTodoIssue for as long as its queued GitHub writes are still pending: discards
 * the pending delete_issue row and restores is_available for each deleted issue, and discards the
 * pending reparenting row and restores the previous parent for each promoted child. A row that has
 * already been pushed to GitHub by the time undo runs can no longer be cleanly reversed here (that
 * would need enqueuing an inverse write, not just discarding a local queue row) -- those ids come
 * back in `tooLate` so the caller can tell the user what could and couldn't be undone.
 */
class UndoDeleteTodoIssue
{
    /**
     * @param  list<int>  $deletedIds
     * @param  list<array{childId: int, previousParentId: ?int, previousParentGithubNodeId: ?string}>  $reparented
     * @return array{restoredIds: list<int>, tooLate: list<int>}
     */
    public function handle(array $deletedIds, array $reparented): array
    {
        return DB::transaction(function () use ($deletedIds, $reparented): array {
            $restoredIds = [];
            $tooLate = [];

            foreach ($deletedIds as $id) {
                $pending = $this->discardPending('delete_issue', $id);
                $issue = Issue::query()->find($id);
                if (! $pending || ! $issue instanceof Issue) {
                    $tooLate[] = $id;

                    continue;
                }
                $issue->update(['is_available' => true]);
                $restoredIds[] = $id;
            }

            foreach ($reparented as $entry) {
                $childPending = $this->discardPending('set_issue_parent', $entry['childId'])
                    || $this->discardPending('remove_issue_parent', $entry['childId']);
                $child = Issue::query()->find($entry['childId']);
                if (! $childPending || ! $child instanceof Issue) {
                    $tooLate[] = $entry['childId'];

                    continue;
                }
                $child->update(['parent_issue_id' => $entry['previousParentId'], 'github_parent_node_id' => $entry['previousParentGithubNodeId']]);
            }

            return ['restoredIds' => $restoredIds, 'tooLate' => $tooLate];
        });
    }

    private function discardPending(string $operation, int $targetId): bool
    {
        $item = GitHubPushQueueItem::query()->where('operation', $operation)->where('target_type', 'issue')
            ->where('target_id', $targetId)->whereIn('status', ['pending', 'failed'])->latest('id')->first();
        if (! $item instanceof GitHubPushQueueItem) {
            return false;
        }
        app(DiscardGitHubPushQueueItem::class)->handle($item);

        return true;
    }
}
