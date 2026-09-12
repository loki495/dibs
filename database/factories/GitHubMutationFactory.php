<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitHubMutation;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GitHubMutation> */
class GitHubMutationFactory extends Factory
{
    protected $model = GitHubMutation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['kind' => 'issue.update', 'issue_id' => Issue::factory(), 'status' => 'pending', 'intent_json' => ['title' => fake()->sentence()], 'requires_reconciliation' => false];
    }
}
