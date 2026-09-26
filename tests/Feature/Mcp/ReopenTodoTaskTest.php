<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReopenTodoTask;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('exposes the tool under the todo_reopen name', function (): void {
    expect(app(ReopenTodoTask::class)->name())->toBe('todo_reopen');
});

it('reopens a closed task, clearing the reason, and returns the fresh detail', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => 'COMPLETED']);
    Comment::factory()->for($issue, 'issue')->closing()->create(['body' => 'Turned out fine.']);

    TodoServer::tool(ReopenTodoTask::class, ['issueId' => $issue->id])
        ->assertOk()->assertHasNoErrors()
        ->assertSee('"state":"OPEN"')->assertSee('"closing":null');

    expect($issue->refresh())->state->toBe('OPEN')->state_reason->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'reopen_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('is not claim-scoped: no capability token is required, unlike todo_complete', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED']);

    TodoServer::tool(ReopenTodoTask::class, ['issueId' => $issue->id])->assertOk()->assertHasNoErrors();

    expect($issue->refresh()->state)->toBe('OPEN');
});

it('returns a structured error for an issue that does not exist, changing nothing', function (): void {
    TodoServer::tool(ReopenTodoTask::class, ['issueId' => 999_999])->assertHasErrors();

    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('returns a structured error for an unavailable issue instead of crashing', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'is_available' => false]);

    TodoServer::tool(ReopenTodoTask::class, ['issueId' => $issue->id])->assertHasErrors();

    expect($issue->refresh()->state)->toBe('CLOSED');
});

it('rejects a call missing the required issueId', function (): void {
    TodoServer::tool(ReopenTodoTask::class, [])->assertHasErrors();
});

it('declares the expected input schema for tools/list introspection', function (): void {
    expect(array_keys(app(ReopenTodoTask::class)->schema(new JsonSchemaTypeFactory)))->toBe(['issueId']);
});
