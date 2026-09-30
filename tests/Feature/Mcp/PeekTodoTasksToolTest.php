<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\PeekTodoTasks;
use App\Models\Issue;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('exposes the tool under the todo_peek name', function (): void {
    expect(app(PeekTodoTasks::class)->name())->toBe('todo_peek');
});

it('declares the expected input schema for tools/list introspection', function (): void {
    expect(array_keys(app(PeekTodoTasks::class)->schema(new JsonSchemaTypeFactory)))->toBe(['ids']);
});

it('reports id, title, revision and state for a batch of issues, and no body', function (): void {
    $a = Issue::factory()->create(['title' => 'First', 'body' => 'Should never appear here', 'revision' => 4, 'state' => 'OPEN']);
    $b = Issue::factory()->create(['title' => 'Second', 'state' => 'CLOSED']);

    TodoServer::tool(PeekTodoTasks::class, ['ids' => [$a->id, $b->id]])
        ->assertOk()->assertHasNoErrors()
        ->assertSee('"revision":4')->assertSee('"state":"OPEN"')->assertSee('"state":"CLOSED"')
        ->assertDontSee('Should never appear here');
});

it('lists an unknown id under unresolved instead of erroring', function (): void {
    $issue = Issue::factory()->create();

    TodoServer::tool(PeekTodoTasks::class, ['ids' => [$issue->id, 999_999]])
        ->assertOk()->assertHasNoErrors()
        ->assertSee('"unresolved":[999999]');
});

it('rejects a call missing the required ids array', function (): void {
    TodoServer::tool(PeekTodoTasks::class, [])->assertHasErrors();
});

it('rejects an empty ids array', function (): void {
    TodoServer::tool(PeekTodoTasks::class, ['ids' => []])->assertHasErrors();
});

it('rejects more ids than the batch limit', function (): void {
    TodoServer::tool(PeekTodoTasks::class, ['ids' => range(1, 101)])->assertHasErrors();
});
