<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GitHubProject> */
class GitHubProjectFactory extends Factory
{
    protected $model = GitHubProject::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $owner = fake()->userName();
        $number = fake()->unique()->numberBetween(1, 999_999);

        return ['github_node_id' => 'PVT_'.fake()->uuid(), 'owner' => $owner, 'github_number' => $number, 'title' => fake()->sentence(3), 'color' => ltrim(fake()->hexColor(), '#'), 'url' => 'https://github.com/users/'.$owner.'/projects/'.$number, 'is_closed' => false, 'is_public' => false, 'is_available' => true];
    }
}
