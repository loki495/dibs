<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubProject;
use App\Models\ProjectItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectItem> */
class ProjectItemFactory extends Factory
{
    protected $model = ProjectItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['project_id' => GitHubProject::factory(), 'github_node_id' => 'PVTI_'.fake()->uuid(), 'content_type' => 'ISSUE', 'is_available' => true];
    }
}
