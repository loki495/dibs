<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\SearchTodoIssues;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectItem;

it('exposes todo_search and returns matching body excerpts through the registered tool', function (): void {
    Issue::factory()->create(['title' => 'Delivery notes', 'body' => 'Retry failed queue jobs', 'state' => 'CLOSED']);

    expect(app(SearchTodoIssues::class)->name())->toBe('todo_search');
    TodoServer::tool(SearchTodoIssues::class, ['query' => 'queue'])
        ->assertOk()->assertHasNoErrors()->assertSee('Delivery notes')->assertSee('Retry failed queue jobs');
});

it('rejects invalid search inputs with a validation error', function (array $arguments, string $field): void {
    TodoServer::tool(SearchTodoIssues::class, $arguments)->assertHasErrors([$field]);
})->with([
    [[], 'query'],
    [['query' => '   '], 'query'],
    [['query' => ['queue']], 'query'],
    [['query' => str_repeat('a', 201)], 'query'],
    [['query' => 'queue', 'state' => 'invalid'], 'state'],
    [['query' => 'queue', 'area' => 0], 'area'],
    [['query' => 'queue', 'group' => -1], 'group'],
    [['query' => 'queue', 'page' => 0], 'page'],
    [['query' => 'queue', 'perPage' => 51], 'perPage'],
]);

it('searches by filters alone through todo_search, with no query', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Work']);
    $match = Issue::factory()->create(['title' => 'Tagged and filed']);
    $match->labels()->attach(Label::factory()->create(['name' => 'bug']));
    ProjectItem::factory()->for($project, 'project')->for($match, 'issue')->create();
    Issue::factory()->create(['title' => 'Unrelated']);

    TodoServer::tool(SearchTodoIssues::class, ['labels' => ['Bug'], 'areaNames' => ['work']])
        ->assertOk()->assertHasNoErrors()->assertSee('Tagged and filed')->assertDontSee('Unrelated');
});

it('narrows todo_search to a whole tree with parentId and descendants, next to a query', function (): void {
    $root = Issue::factory()->create(['title' => 'Root plan']);
    $child = Issue::factory()->create(['title' => 'Child mentions gizmo', 'parent_issue_id' => $root->id]);
    Issue::factory()->create(['title' => 'Grandchild mentions gizmo', 'parent_issue_id' => $child->id]);
    Issue::factory()->create(['title' => 'Elsewhere mentions gizmo']);

    TodoServer::tool(SearchTodoIssues::class, ['query' => 'gizmo', 'parentId' => $root->id, 'descendants' => true])
        ->assertOk()->assertSee('Child mentions gizmo')->assertSee('Grandchild mentions gizmo')->assertDontSee('Elsewhere mentions gizmo');
});

it('reports names it could not resolve in the todo_search response', function (): void {
    TodoServer::tool(SearchTodoIssues::class, ['labels' => ['agnt task'], 'groupNames' => ['Nada']])
        ->assertOk()->assertSee('unresolved')->assertSee('agnt task')->assertSee('Nada');
});

it('rejects malformed filter arguments on todo_search with a validation error', function (array $arguments, string $field): void {
    TodoServer::tool(SearchTodoIssues::class, ['query' => 'queue', ...$arguments])->assertHasErrors([$field]);
})->with([
    'labels not an array' => [['labels' => 'bug'], 'labels'],
    'label not a string' => [['labels' => [['bug']]], 'labels.0'],
    'label too long' => [['labels' => [str_repeat('x', 101)]], 'labels.0'],
    'too many labels' => [['anyLabels' => array_fill(0, 21, 'bug')], 'anyLabels'],
    'excludeLabels not an array' => [['excludeLabels' => 5], 'excludeLabels'],
    'area id zero' => [['areas' => [0]], 'areas.0'],
    'area id a string' => [['areas' => ['work']], 'areas.0'],
    'group id negative' => [['groups' => [-1]], 'groups.0'],
    'area name not a string' => [['areaNames' => [3]], 'areaNames.0'],
    'group name too long' => [['groupNames' => [str_repeat('g', 101)]], 'groupNames.0'],
    'parentId zero' => [['parentId' => 0], 'parentId'],
    'descendants not a boolean' => [['descendants' => 'yes'], 'descendants'],
]);
