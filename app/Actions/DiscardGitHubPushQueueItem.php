<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

/**
 * Drops a queue row without ever pushing it to GitHub — for a pending/failed/needs_attention change
 * nobody cares about reaching GitHub anymore. Safe: local SQLite already holds the confirmed result
 * for whatever this row represents, so discarding the queue entry never touches actual task data,
 * it just stops that one change from ever syncing outward.
 */
class DiscardGitHubPushQueueItem
{
    public function handle(GitHubPushQueueItem $item): void
    {
        $item->delete();
    }
}
