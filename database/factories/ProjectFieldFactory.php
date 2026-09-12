<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubProject;
use App\Models\ProjectField;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectField> */
class ProjectFieldFactory extends Factory
{
    protected $model = ProjectField::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['project_id' => GitHubProject::factory(), 'github_node_id' => 'PVTF_'.fake()->uuid(), 'name' => fake()->word(), 'data_type' => 'SINGLE_SELECT', 'is_available' => true];
    }
}
