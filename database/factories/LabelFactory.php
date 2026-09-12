<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubRepository;
use App\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Label> */
class LabelFactory extends Factory
{
    protected $model = Label::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['repository_id' => GitHubRepository::factory(), 'github_node_id' => 'LA_'.fake()->uuid(), 'name' => fake()->unique()->word(), 'color' => '008672', 'is_available' => true];
    }
}
