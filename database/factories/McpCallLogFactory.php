<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\McpCallLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<McpCallLog> */
class McpCallLogFactory extends Factory
{
    protected $model = McpCallLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'request_id' => fake()->uuid(),
            'tool' => 'todo_list',
            'arguments' => [],
            'status' => McpCallLog::STATUS_OK,
            'duration_ms' => fake()->numberBetween(1, 200),
            'agent_label' => 'claude-code',
            'created_at' => now(),
        ];
    }
}
