<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

/**
 * Drops one queue row, whatever its status — a pending/failed/needs_attention change nobody cares
 * about reaching GitHub anymore, or an already-pushed row that's pure history at this point. Safe:
 * local SQLite already holds the confirmed result for whatever this row represents, so discarding
 * the queue entry never touches actual task data. For a not-yet-pushed row it also stops that one
 * change from ever syncing outward. See ClearPushedGitHubPushQueueItems for the bulk equivalent.
 */
class DiscardGitHubPushQueueItem
{
    public function handle(GitHubPushQueueItem $item): void
    {
        $item->delete();
    }
}
