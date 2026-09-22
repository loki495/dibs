<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoMetadata;
use App\Models\GitHubProject;
use App\Models\Label;

it('is registered on the server under the todo_metadata name', function (): void {
    expect(app(DescribeTodoMetadata::class)->name())->toBe('todo_metadata');
});

it('declares a placeholder argument as required so a client never sends an empty input object', function (): void {
    $schema = app(DescribeTodoMetadata::class)->toArray()['inputSchema'];

    expect(array_keys((array) $schema['properties']))->toContain('noop', 'query', 'kinds', 'area')
        ->and($schema['required'])->toBe(['noop']);
});

it('describes everything through the todo_metadata tool, with or without the placeholder', function (array $arguments): void {
    GitHubProject::factory()->create(['title' => 'Personal Projects']);
    Label::factory()->create(['name' => 'agent task']);

    TodoServer::tool(DescribeTodoMetadata::class, $arguments)
        ->assertOk()->assertHasNoErrors()->assertSee('Personal Projects')->assertSee('agent task')->assertSee('labelNames');
})->with([
    'with the placeholder' => [['noop' => true]],
    'without it' => [[]],
]);

it('narrows by query, kinds and area through the tool', function (): void {
    $work = GitHubProject::factory()->create(['title' => 'Work']);
    Label::factory()->create(['name' => 'agent task']);
    Label::factory()->create(['name' => 'bug']);

    TodoServer::tool(DescribeTodoMetadata::class, ['query' => 'agent', 'kinds' => ['labels']])
        ->assertOk()->assertSee('agent task')->assertDontSee('bug');
    TodoServer::tool(DescribeTodoMetadata::class, ['area' => $work->id, 'kinds' => ['areas']])
        ->assertOk()->assertSee('Work');
});

it('rejects malformed arguments with a validation error naming them', function (array $arguments, string $field): void {
    TodoServer::tool(DescribeTodoMetadata::class, $arguments)->assertHasErrors([$field]);
})->with([
    'query not a string' => [['query' => ['x']], 'query'],
    'query too long' => [['query' => str_repeat('q', 101)], 'query'],
    'kinds not an array' => [['kinds' => 'labels'], 'kinds'],
    'unknown kind' => [['kinds' => ['labels', 'widgets']], 'kinds.1'],
    'kind not a string' => [['kinds' => [3]], 'kinds.0'],
    'area zero' => [['area' => 0], 'area'],
    'area not an integer' => [['area' => 'work'], 'area'],
]);

it('reports an unknown area as an error instead of an empty result', function (): void {
    TodoServer::tool(DescribeTodoMetadata::class, ['area' => 999_999])->assertHasErrors(['No available area has id 999999.']);
});
