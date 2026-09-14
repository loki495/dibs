<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ScaffoldTodoPlan;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;

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

it('sets the plan issue\'s own Group through the tool, independently of a child\'s groupId', function (): void {
    $project = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $planGroup = ProjectFieldOption::factory()->for($groupField, 'field')->create();
    $childGroup = ProjectFieldOption::factory()->for($groupField, 'field')->create();

    TodoServer::tool(ScaffoldTodoPlan::class, [
        'title' => 'Plan: ship it',
        'area' => $project->id,
        'groupId' => $planGroup->id,
        'children' => [['title' => 'Step one', 'groupId' => $childGroup->id]],
    ])
        ->assertOk()
        ->assertHasNoErrors();

    $plan = Issue::query()->where('title', 'Plan: ship it')->sole();
    expect($plan->projectItems->sole()->group_option_id)->toBe($planGroup->id)
        ->and($plan->children->sole()->projectItems->sole()->group_option_id)->toBe($childGroup->id);
});
