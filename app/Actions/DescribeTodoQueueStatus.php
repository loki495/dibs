<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;

class DescribeTodoQueueStatus
{
    public const MAX_ITEMS = 50;

    public const DEFAULT_ITEMS = 20;

    public function __construct(private readonly DescribeGitHubPushQueue $describe) {}

    /** @return array<string, mixed> */
    public function handle(int $limit = self::DEFAULT_ITEMS): array
    {
        $limit = max(1, min($limit, self::MAX_ITEMS));

        $items = GitHubPushQueueItem::query()->whereIn('status', ['failed', 'needs_attention'])
            ->orderByDesc('updated_at')->limit($limit)->get();
        $targets = $this->describe->describeTargets($items);

        return [
            'counts' => $this->describe->counts(),
            'actionable' => $items->map(fn (GitHubPushQueueItem $item): array => [
                'id' => $item->id,
                'operation' => $item->operation,
                'status' => $item->status,
                'target' => $targets[$item->id],
                'attempts' => $item->attempts,
                'lastError' => $item->last_error,
                'updatedAt' => $item->updated_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
