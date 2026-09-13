<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

/**
 * Prunes every already-delivered row so the queue table doesn't grow without bound. Safe: a
 * `pushed` row already reached GitHub, so it's pure history at that point — deleting it never
 * touches actual task data or anything still waiting to sync.
 */
class ClearPushedGitHubPushQueueItems
{
    public function handle(): int
    {
        return GitHubPushQueueItem::query()->where('status', 'pushed')->delete();
    }
}
