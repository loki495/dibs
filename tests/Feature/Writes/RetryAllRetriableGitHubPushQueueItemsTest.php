<?php

declare(strict_types=1);

use App\Actions\RetryAllRetriableGitHubPushQueueItems;
use App\Models\GitHubPushQueueItem;

it('re-queues every failed and needs_attention row, leaving other statuses alone', function (): void {
    $failed = GitHubPushQueueItem::factory()->create(['status' => 'failed', 'attempts' => 2, 'last_error' => 'GitHub HTTP 500']);
    $needsAttention = GitHubPushQueueItem::factory()->create(['status' => 'needs_attention', 'attempts' => 3, 'last_error' => 'Field id does not exist']);
    $pending = GitHubPushQueueItem::factory()->create(['status' => 'pending', 'attempts' => 0]);
    $pushed = GitHubPushQueueItem::factory()->create(['status' => 'pushed', 'attempts' => 1]);

    $retried = app(RetryAllRetriableGitHubPushQueueItems::class)->handle();

    expect($retried)->toBe(2)
        ->and($failed->refresh())->status->toBe('pending')->attempts->toBe(0)->last_error->toBeNull()
        ->and($needsAttention->refresh())->status->toBe('pending')->attempts->toBe(0)->last_error->toBeNull()
        ->and($pending->refresh())->status->toBe('pending')->attempts->toBe(0)
        ->and($pushed->refresh())->status->toBe('pushed')->attempts->toBe(1);
});

it('does nothing when there is nothing retriable', function (): void {
    GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    expect(app(RetryAllRetriableGitHubPushQueueItems::class)->handle())->toBe(0);
});
