<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

class RetryGitHubPushQueueItem
{
    /** Re-queues a failed/needs-attention row for another drain attempt without duplicating it. */
    public function handle(GitHubPushQueueItem $item): GitHubPushQueueItem
    {
        if (! in_array($item->status, ['failed', 'needs_attention'], true)) {
            return $item;
        }
        $item->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null]);

        return $item->refresh();
    }
}
