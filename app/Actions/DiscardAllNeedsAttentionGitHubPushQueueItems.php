<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

/**
 * Drops every needs_attention row in one call -- the bulk equivalent of
 * DiscardGitHubPushQueueItem, for clearing out rows that will never resolve (e.g. queued against
 * fabricated/test data that doesn't exist on GitHub) instead of discarding them one at a time.
 * Safe for the same reason a single discard is: local SQLite already holds the confirmed result
 * for whatever each row represents.
 */
class DiscardAllNeedsAttentionGitHubPushQueueItems
{
    public function handle(): int
    {
        return GitHubPushQueueItem::query()->where('status', 'needs_attention')->delete();
    }
}
