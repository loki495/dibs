<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ListTodoTasks;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectItem;

it('exposes the tool under the todo_list name', function (): void {
    expect(app(ListTodoTasks::class)->name())->toBe('todo_list');
});

it('lists tasks through the todo_list tool with default view and state', function (): void {
    Issue::factory()->create(['title' => 'Visible task']);
    $knowledge = Issue::factory()->create();
    $knowledge->labels()->attach(Label::factory()->create(['name' => 'decision']));

    TodoServer::tool(ListTodoTasks::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Visible task')
        ->assertDontSee($knowledge->title);
});

it('respects the view argument when called through the tool', function (): void {
    Issue::factory()->create();
    $lesson = Issue::factory()->create(['title' => 'A learned lesson']);
    $lesson->labels()->attach(Label::factory()->create(['name' => 'lesson']));

    TodoServer::tool(ListTodoTasks::class, ['view' => 'knowledge'])
        ->assertOk()
        ->assertSee('A learned lesson');
});

it('rejects an invalid view value before calling the handler', function (): void {
    TodoServer::tool(ListTodoTasks::class, ['view' => 'not-a-real-view'])
        ->assertHasErrors();
});

it('combines the shared filters and its own search text through todo_list', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Work']);
    $match = Issue::factory()->create(['title' => 'Fix the sink']);
    $match->labels()->attach(Label::factory()->create(['name' => 'bug']));
    ProjectItem::factory()->for($project, 'project')->for($match, 'issue')->create();
    $other = Issue::factory()->create(['title' => 'Fix the docs']);
    $other->labels()->attach(Label::factory()->create(['name' => 'docs']));

    TodoServer::tool(ListTodoTasks::class, ['search' => 'fix', 'areaNames' => ['WORK'], 'labels' => ['bug'], 'excludeLabels' => ['docs']])
        ->assertOk()->assertHasNoErrors()->assertSee('Fix the sink')->assertDontSee('Fix the docs');
});

it('lists the whole tree under a parent through todo_list with descendants', function (): void {
    $root = Issue::factory()->create(['title' => 'Root plan']);
    $child = Issue::factory()->create(['title' => 'Direct child', 'parent_issue_id' => $root->id]);
    Issue::factory()->create(['title' => 'Nested grandchild', 'parent_issue_id' => $child->id]);

    TodoServer::tool(ListTodoTasks::class, ['parentId' => $root->id])->assertSee('Direct child')->assertDontSee('Nested grandchild');
    TodoServer::tool(ListTodoTasks::class, ['parentId' => $root->id, 'descendants' => true])->assertSee('Direct child')->assertSee('Nested grandchild');
});

it('reports names it could not resolve in the todo_list response', function (): void {
    TodoServer::tool(ListTodoTasks::class, ['areaNames' => ['Nope'], 'label' => 'agnt task'])
        ->assertOk()->assertSee('unresolved')->assertSee('Nope')->assertSee('agnt task');
});

it('rejects malformed filter arguments on todo_list with a validation error', function (array $arguments, string $field): void {
    TodoServer::tool(ListTodoTasks::class, $arguments)->assertHasErrors([$field]);
})->with([
    'labels not an array' => [['labels' => 'bug'], 'labels'],
    'label not a string' => [['anyLabels' => [['bug']]], 'anyLabels.0'],
    'label too long' => [['excludeLabels' => [str_repeat('x', 101)]], 'excludeLabels.0'],
    'too many area ids' => [['areas' => range(1, 21)], 'areas'],
    'area id zero' => [['areas' => [0]], 'areas.0'],
    'group id a string' => [['groups' => ['dibs']], 'groups.0'],
    'group name not a string' => [['groupNames' => [3]], 'groupNames.0'],
    'descendants not a boolean' => [['descendants' => 'yes'], 'descendants'],
]);
