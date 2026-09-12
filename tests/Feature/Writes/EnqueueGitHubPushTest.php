<?php

declare(strict_types=1);

use App\Actions\EnqueueGitHubPush;
use App\Models\GitHubPushQueueItem;

it('creates a pending queue row for a new idempotency key', function (): void {
    $item = app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', 5, ['title' => 'Capture this'], 'issue:create:5');

    expect($item->status)->toBe('pending')
        ->and($item->operation)->toBe('create_issue')
        ->and($item->target_id)->toBe(5)
        ->and($item->payload)->toBe(['title' => 'Capture this']);
    expect(GitHubPushQueueItem::query()->count())->toBe(1);
});

it('refreshes a still-pending row instead of duplicating it on retry', function (): void {
    app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', 5, ['title' => 'First'], 'issue:create:5');
    $item = app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', 5, ['title' => 'Corrected'], 'issue:create:5');

    expect(GitHubPushQueueItem::query()->count())->toBe(1)
        ->and($item->payload)->toBe(['title' => 'Corrected']);
});

it('leaves an already-pushed row alone instead of re-queuing it', function (): void {
    GitHubPushQueueItem::factory()->create(['idempotency_key' => 'issue:create:5', 'status' => 'pushed', 'payload' => ['title' => 'Original']]);

    $item = app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', 5, ['title' => 'Ignored'], 'issue:create:5');

    expect($item->status)->toBe('pushed')->and($item->payload)->toBe(['title' => 'Original']);
    expect(GitHubPushQueueItem::query()->count())->toBe(1);
});
