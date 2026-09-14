<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Restores a deleted issue at any time, not just within a short window after deleting it: if the
 * real GitHub deletion (delete_issue) is still pending, this simply discards that row and restores
 * is_available -- the issue was never actually removed from GitHub, so nothing else changes. If it
 * has already been pushed, GitHub's own deletion cannot be reversed via the API, so this clears the
 * issue's now-dead GitHub identity and enqueues create_issue instead -- the exact same push the
 * issue got the first time it was created, giving it a fresh GitHub identity once the queue drains.
 * Either way the local record, its title/body, and its place in the task tree are unaffected.
 */
class RestoreTodoIssue
{
    public function handle(Issue $issue): Issue
    {
        if ($issue->is_available) {
            throw new TodoValidationException('This task is not deleted.');
        }

        return DB::transaction(function () use ($issue): Issue {
            $deletionStillPending = $this->discardPendingDelete($issue);
            $issue->update(['is_available' => true]);

            if (! $deletionStillPending) {
                $issue->update(['github_node_id' => null, 'github_number' => null, 'url' => null]);
                app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:recreate:'.$issue->id.':'.now()->timestamp);
            }

            return $issue->refresh();
        });
    }

    private function discardPendingDelete(Issue $issue): bool
    {
        $item = GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_type', 'issue')
            ->where('target_id', $issue->id)->whereIn('status', ['pending', 'failed'])->latest('id')->first();
        if (! $item instanceof GitHubPushQueueItem) {
            return false;
        }
        app(DiscardGitHubPushQueueItem::class)->handle($item);

        return true;
    }
}
