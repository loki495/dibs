<?php

declare(strict_types=1);

use App\Actions\DiscardGitHubPushQueueItem;
use App\Models\GitHubPushQueueItem;

it('deletes a pending row without touching any other queue rows', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['status' => 'pending']);
    $other = GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    app(DiscardGitHubPushQueueItem::class)->handle($item);

    expect(GitHubPushQueueItem::query()->find($item->id))->toBeNull()
        ->and(GitHubPushQueueItem::query()->find($other->id))->not->toBeNull();
});

it('deletes a failed or needs_attention row the same way', function (): void {
    $failed = GitHubPushQueueItem::factory()->create(['status' => 'failed']);
    $needsAttention = GitHubPushQueueItem::factory()->create(['status' => 'needs_attention']);

    app(DiscardGitHubPushQueueItem::class)->handle($failed);
    app(DiscardGitHubPushQueueItem::class)->handle($needsAttention);

    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
