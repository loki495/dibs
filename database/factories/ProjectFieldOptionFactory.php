<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectFieldOption> */
class ProjectFieldOptionFactory extends Factory
{
    protected $model = ProjectFieldOption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['project_field_id' => ProjectField::factory(), 'github_option_id' => 'opt_'.fake()->uuid(), 'name' => fake()->word(), 'color' => 'BLUE', 'position' => 0];
    }
}
