<?php

declare(strict_types=1);

use App\Actions\DescribeGitHubPushQueue;
use App\Models\GitHubPushQueueItem;

it('counts each status bucket independently and totals actionable items', function (): void {
    GitHubPushQueueItem::factory()->count(2)->create(['status' => 'pending']);
    GitHubPushQueueItem::factory()->create(['status' => 'failed']);
    GitHubPushQueueItem::factory()->count(3)->create(['status' => 'needs_attention']);
    GitHubPushQueueItem::factory()->create(['status' => 'pushed']);

    $counts = app(DescribeGitHubPushQueue::class)->counts();

    expect($counts)->toBe(['pending' => 2, 'failed' => 1, 'needsAttention' => 3, 'actionable' => 4]);
});
