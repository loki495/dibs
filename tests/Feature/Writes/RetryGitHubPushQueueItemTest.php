<?php

declare(strict_types=1);

use App\Actions\RetryGitHubPushQueueItem;
use App\Models\GitHubPushQueueItem;

it('re-queues a failed row for another drain attempt', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['status' => 'failed', 'attempts' => 2, 'last_error' => 'GitHub HTTP 500']);

    $result = app(RetryGitHubPushQueueItem::class)->handle($item);

    expect($result->status)->toBe('pending')->and($result->attempts)->toBe(0)->and($result->last_error)->toBeNull();
});

it('re-queues a needs_attention row the same way', function (): void {
    $item = GitHubPushQueueItem::factory()->create(['status' => 'needs_attention', 'attempts' => 3]);

    $result = app(RetryGitHubPushQueueItem::class)->handle($item);

    expect($result->status)->toBe('pending')->and($result->attempts)->toBe(0);
});

it('does not touch a row that is already pending or pushed', function (): void {
    $pending = GitHubPushQueueItem::factory()->create(['status' => 'pending', 'attempts' => 0]);
    $pushed = GitHubPushQueueItem::factory()->create(['status' => 'pushed', 'attempts' => 1]);

    app(RetryGitHubPushQueueItem::class)->handle($pending);
    app(RetryGitHubPushQueueItem::class)->handle($pushed);

    expect($pending->refresh()->status)->toBe('pending')->and($pushed->refresh())->status->toBe('pushed')->attempts->toBe(1);
});
