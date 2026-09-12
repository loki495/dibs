<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Comment> */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['issue_id' => Issue::factory(), 'github_node_id' => 'IC_'.fake()->uuid(), 'body' => fake()->paragraph(), 'author_login' => fake()->userName(), 'url' => fake()->url(), 'is_available' => true];
    }
}
