<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GitHubRepository> */
class GitHubRepositoryFactory extends Factory
{
    protected $model = GitHubRepository::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $owner = fake()->userName();
        $name = fake()->unique()->slug(2);

        return ['github_node_id' => 'R_'.fake()->uuid(), 'owner' => $owner, 'name' => $name, 'full_name' => $owner.'/'.$name, 'url' => 'https://github.com/'.$owner.'/'.$name, 'is_private' => fake()->boolean(), 'visibility' => 'PRIVATE', 'is_available' => true];
    }
}
