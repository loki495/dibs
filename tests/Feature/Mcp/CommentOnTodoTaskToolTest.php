<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CommentOnTodoTask;
use App\Models\Comment;
use App\Models\Issue;

it('exposes the tool under the todo_comment name', function (): void {
    expect(app(CommentOnTodoTask::class)->name())->toBe('todo_comment');
});

it('adds a comment when issueId is given', function (): void {
    $issue = Issue::factory()->create();

    TodoServer::tool(CommentOnTodoTask::class, ['issueId' => $issue->id, 'body' => 'A helpful note'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('A helpful note')
        ->assertSee('"conflict":false');

    expect(Comment::query()->where('issue_id', $issue->id)->sole()->body)->toBe('A helpful note');
});

it('edits a comment when commentId and expectedRevision are given', function (): void {
    $comment = Comment::factory()->create(['body' => 'Old', 'revision' => 1]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'body' => 'Edited'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Edited');

    expect($comment->fresh()->body)->toBe('Edited');
});

it('returns a non-error conflict response with current data on a stale edit', function (): void {
    $comment = Comment::factory()->create(['body' => 'Original', 'revision' => 1]);
    $comment->update(['body' => 'Changed elsewhere', 'revision' => 2]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'body' => 'My edit'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"conflict":true')
        ->assertSee('Changed elsewhere');
});

it('rejects a call with neither issueId nor commentId', function (): void {
    TodoServer::tool(CommentOnTodoTask::class, ['body' => 'Orphaned'])->assertHasErrors();
});

it('rejects editing a comment without expectedRevision', function (): void {
    $comment = Comment::factory()->create(['revision' => 1]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'body' => 'Edited'])->assertHasErrors();
});

it('rejects a call missing the required body', function (): void {
    $issue = Issue::factory()->create();

    TodoServer::tool(CommentOnTodoTask::class, ['issueId' => $issue->id])->assertHasErrors();
});
