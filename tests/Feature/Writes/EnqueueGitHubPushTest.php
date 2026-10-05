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

it('gives a row brought back from needs attention a fresh retry budget', function (): void {
    GitHubPushQueueItem::factory()->create(['idempotency_key' => 'issue:create:5', 'status' => 'needs_attention', 'attempts' => 8, 'next_attempt_at' => now()->addHour(), 'last_error' => 'GitHub HTTP 502']);

    $item = app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', 5, ['title' => 'Again'], 'issue:create:5');

    expect($item->status)->toBe('pending')
        ->and($item->attempts)->toBe(0)
        ->and($item->next_attempt_at)->toBeNull()
        ->and($item->last_error)->toBeNull();
});

it('keeps a still-pending row\'s attempts and backoff when its payload is refreshed', function (): void {
    $due = now()->addMinutes(5)->startOfSecond();
    GitHubPushQueueItem::factory()->create(['idempotency_key' => 'issue:create:5', 'status' => 'pending', 'attempts' => 2, 'next_attempt_at' => $due]);

    $item = app(EnqueueGitHubPush::class)->handle('create_issue', 'issue', 5, ['title' => 'Corrected'], 'issue:create:5');

    expect($item->attempts)->toBe(2)
        ->and($item->next_attempt_at->equalTo($due))->toBeTrue()
        ->and($item->payload)->toBe(['title' => 'Corrected']);
});
