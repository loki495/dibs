<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ScaffoldTodoPlan;
use App\Models\GitHubRepository;
use App\Models\Issue;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('exposes the tool under the todo_scaffold_plan name', function (): void {
    expect(app(ScaffoldTodoPlan::class)->name())->toBe('todo_scaffold_plan');
});

it('scaffolds a plan with children through the tool', function (): void {
    TodoServer::tool(ScaffoldTodoPlan::class, [
        'title' => 'Plan: ship it',
        'children' => [['title' => 'Step one'], ['title' => 'Step two']],
    ])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Plan: ship it')
        ->assertSee('Step one');

    expect(Issue::query()->count())->toBe(3);
});

it('creates nothing through the tool if a child is invalid', function (): void {
    TodoServer::tool(ScaffoldTodoPlan::class, [
        'title' => 'Plan',
        'children' => [['title' => 'ok'], ['title' => 'bad', 'groupId' => 999_999]],
    ])->assertHasErrors();

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a call missing the required title', function (): void {
    TodoServer::tool(ScaffoldTodoPlan::class, ['children' => [['title' => 'orphaned child']]])
        ->assertHasErrors();
});
