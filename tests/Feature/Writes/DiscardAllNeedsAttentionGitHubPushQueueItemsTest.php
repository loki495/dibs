<?php

declare(strict_types=1);

use App\Actions\DiscardAllNeedsAttentionGitHubPushQueueItems;
use App\Models\GitHubPushQueueItem;

it('deletes every needs_attention row and returns the count removed', function (): void {
    GitHubPushQueueItem::factory()->count(3)->create(['status' => 'needs_attention']);
    $pending = GitHubPushQueueItem::factory()->create(['status' => 'pending']);
    $failed = GitHubPushQueueItem::factory()->create(['status' => 'failed']);

    $removed = app(DiscardAllNeedsAttentionGitHubPushQueueItems::class)->handle();

    expect($removed)->toBe(3)
        ->and(GitHubPushQueueItem::query()->where('status', 'needs_attention')->count())->toBe(0)
        ->and(GitHubPushQueueItem::query()->find($pending->id))->not->toBeNull()
        ->and(GitHubPushQueueItem::query()->find($failed->id))->not->toBeNull();
});

it('does nothing when there are no needs_attention rows', function (): void {
    GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    expect(app(DiscardAllNeedsAttentionGitHubPushQueueItems::class)->handle())->toBe(0);
});
