<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChangeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChangeLog> */
class ChangeLogFactory extends Factory
{
    protected $model = ChangeLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'request_id' => fake()->uuid(),
            'source' => ChangeLog::SOURCE_UI,
            'category' => ChangeLog::CATEGORY_CHANGE,
            'action' => 'UpdateTodoIssue',
            'summary' => fake()->sentence(),
            'actor_type' => ChangeLog::ACTOR_USER,
            'actor_label' => fake()->name(),
            'created_at' => now(),
        ];
    }
}
