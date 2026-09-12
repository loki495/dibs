<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubRepository;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Issue> */
class IssueFactory extends Factory
{
    protected $model = Issue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['repository_id' => GitHubRepository::factory(), 'github_node_id' => 'I_'.fake()->uuid(), 'github_number' => fake()->unique()->numberBetween(1, 999_999), 'title' => fake()->sentence(4), 'body' => fake()->paragraph(), 'state' => 'OPEN', 'url' => fake()->url(), 'sibling_position' => 0, 'is_available' => true];
    }
}
