<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\SearchTodoIssues;
use App\Models\Issue;

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
