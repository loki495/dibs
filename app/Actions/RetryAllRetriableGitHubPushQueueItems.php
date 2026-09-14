<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

/**
 * Re-queues every failed/needs_attention row for another drain attempt, without duplicating any
 * of them -- the bulk equivalent of RetryGitHubPushQueueItem, for clearing a backlog left by a
 * since-fixed bug in one call instead of retrying rows one at a time.
 */
class RetryAllRetriableGitHubPushQueueItems
{
    public function handle(): int
    {
        return GitHubPushQueueItem::query()->whereIn('status', ['failed', 'needs_attention'])
            ->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null]);
    }
}
