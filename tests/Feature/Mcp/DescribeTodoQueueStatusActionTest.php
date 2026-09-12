<?php

declare(strict_types=1);

use App\Actions\DescribeTodoQueueStatus;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('reports queue counts with no actionable items when the queue is empty', function (): void {
    $result = app(DescribeTodoQueueStatus::class)->handle();

    expect($result['counts'])->toBe(['pending' => 0, 'failed' => 0, 'needsAttention' => 0, 'actionable' => 0])
        ->and($result['actionable'])->toBe([]);
});

it('lists failed and needs_attention items with a human target description', function (): void {
    $issue = Issue::factory()->create(['title' => 'Fix the widget']);
    GitHubPushQueueItem::factory()->create(['status' => 'pending', 'target_type' => 'issue', 'target_id' => $issue->id]);
    $failed = GitHubPushQueueItem::factory()->create(['status' => 'failed', 'target_type' => 'issue', 'target_id' => $issue->id, 'last_error' => 'GitHub HTTP 500']);
    $needsAttention = GitHubPushQueueItem::factory()->create(['status' => 'needs_attention', 'target_type' => 'issue', 'target_id' => $issue->id]);

    $result = app(DescribeTodoQueueStatus::class)->handle();

    expect($result['counts'])->toBe(['pending' => 1, 'failed' => 1, 'needsAttention' => 1, 'actionable' => 2])
        ->and($result['actionable'])->toHaveCount(2)
        ->and(collect($result['actionable'])->pluck('id')->sort()->values()->all())->toBe([$failed->id, $needsAttention->id])
        ->and(collect($result['actionable'])->firstWhere('id', $failed->id)['lastError'])->toBe('GitHub HTTP 500')
        ->and(collect($result['actionable'])->firstWhere('id', $failed->id)['target'])->toContain('Fix the widget');
});

it('caps the actionable item list at the maximum', function (): void {
    GitHubPushQueueItem::factory()->count(60)->create(['status' => 'needs_attention']);

    $result = app(DescribeTodoQueueStatus::class)->handle(limit: 1000);

    expect($result['actionable'])->toHaveCount(DescribeTodoQueueStatus::MAX_ITEMS)
        ->and($result['counts']['needsAttention'])->toBe(60);
});
