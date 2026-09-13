<?php

declare(strict_types=1);

use App\Actions\ClearPushedGitHubPushQueueItems;
use App\Models\GitHubPushQueueItem;

it('deletes every pushed row and returns the count removed', function (): void {
    GitHubPushQueueItem::factory()->count(3)->create(['status' => 'pushed']);
    $pending = GitHubPushQueueItem::factory()->create(['status' => 'pending']);
    $failed = GitHubPushQueueItem::factory()->create(['status' => 'failed']);

    $removed = app(ClearPushedGitHubPushQueueItems::class)->handle();

    expect($removed)->toBe(3)
        ->and(GitHubPushQueueItem::query()->where('status', 'pushed')->count())->toBe(0)
        ->and(GitHubPushQueueItem::query()->find($pending->id))->not->toBeNull()
        ->and(GitHubPushQueueItem::query()->find($failed->id))->not->toBeNull();
});

it('does nothing when there are no pushed rows', function (): void {
    GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    expect(app(ClearPushedGitHubPushQueueItems::class)->handle())->toBe(0);
});
