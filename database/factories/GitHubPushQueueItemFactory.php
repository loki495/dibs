<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubPushQueueItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GitHubPushQueueItem> */
class GitHubPushQueueItemFactory extends Factory
{
    protected $model = GitHubPushQueueItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operation' => 'create_issue',
            'target_type' => 'issue',
            'target_id' => null,
            'payload' => ['title' => fake()->sentence(4)],
            'status' => 'pending',
            'attempts' => 0,
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }
}
