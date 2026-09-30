<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoIssue;
use App\Models\Comment;
use App\Models\Issue;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('exposes the tool under the todo_show name', function (): void {
    expect(app(DescribeTodoIssue::class)->name())->toBe('todo_show');
});

it('declares the expected input schema for tools/list introspection', function (): void {
    expect(array_keys(app(DescribeTodoIssue::class)->schema(new JsonSchemaTypeFactory)))
        ->toBe(['id', 'withComments', 'commentsPage', 'commentsPerPage', 'maxBodyLength']);
});

it('truncates a long body through the tool when maxBodyLength is given', function (): void {
    $issue = Issue::factory()->create(['body' => str_repeat('a', 500)]);

    TodoServer::tool(DescribeTodoIssue::class, ['id' => $issue->id, 'maxBodyLength' => 50])
        ->assertOk()->assertHasNoErrors()
        ->assertSee('"bodyTruncated":true');
});

it('shows full issue detail through the todo_show tool', function (): void {
    $issue = Issue::factory()->create(['title' => 'Ship the thing', 'body' => 'Full body text']);

    TodoServer::tool(DescribeTodoIssue::class, ['id' => $issue->id])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Full body text');
});

it('includes comments only when withComments is requested', function (): void {
    $issue = Issue::factory()->create();
    Comment::factory()->for($issue, 'issue')->create(['body' => 'A helpful comment']);

    TodoServer::tool(DescribeTodoIssue::class, ['id' => $issue->id])
        ->assertOk()->assertDontSee('A helpful comment');

    TodoServer::tool(DescribeTodoIssue::class, ['id' => $issue->id, 'withComments' => true])
        ->assertOk()->assertSee('A helpful comment');
});

it('returns a structured error for a missing issue id', function (): void {
    TodoServer::tool(DescribeTodoIssue::class, ['id' => 999_999])
        ->assertHasErrors();
});

it('returns a structured error for an unavailable issue', function (): void {
    $issue = Issue::factory()->create(['is_available' => false]);

    TodoServer::tool(DescribeTodoIssue::class, ['id' => $issue->id])
        ->assertHasErrors();
});

it('rejects a call missing the required id argument', function (): void {
    TodoServer::tool(DescribeTodoIssue::class, [])
        ->assertHasErrors();
});
