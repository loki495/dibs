<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoContext;
use App\Models\GitHubProject;

it('exposes the tool under the todo_context name', function (): void {
    expect(app(DescribeTodoContext::class)->name())->toBe('todo_context');
});

it('describes context through the todo_context tool', function (): void {
    GitHubProject::factory()->create(['title' => 'Personal Projects']);

    TodoServer::tool(DescribeTodoContext::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Personal Projects');
});
