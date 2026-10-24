<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CommentOnTodoTask;
use App\Mcp\Tools\ReviseTodoTask;
use App\Models\Comment;
use App\Models\Issue;

it('edits a comment in place with replace and leaves the body out of the response', function (): void {
    $comment = Comment::factory()->create(['body' => "Subsystem A\nVerified at: abc1234", 'revision' => 1]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'replace' => [['from' => 'abc1234', 'to' => 'def5678']]])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"revision":2')
        ->assertDontSee('Subsystem A');

    expect($comment->fresh()->body)->toBe("Subsystem A\nVerified at: def5678");
});

it('edits an issue body with replace, all: true replacing every occurrence', function (): void {
    $issue = Issue::factory()->create(['body' => 'at abc1234; again abc1234', 'revision' => 3]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 3, 'replace' => [['from' => 'abc1234', 'to' => 'def5678', 'all' => true]]])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"revision":4');

    expect($issue->fresh()->body)->toBe('at def5678; again def5678');
});

it('refuses a missing or ambiguous from, changing nothing', function (string $tool, string $from, string $error): void {
    $comment = Comment::factory()->create(['body' => 'x abc x abc', 'revision' => 1]);
    $issue = Issue::factory()->create(['body' => 'x abc x abc', 'revision' => 1]);
    $arguments = $tool === 'comment'
        ? [CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'replace' => [['from' => $from, 'to' => 'y']]]]
        : [ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'replace' => [['from' => $from, 'to' => 'y']]]];

    TodoServer::tool(...$arguments)->assertHasErrors([$error]);

    expect($comment->fresh()->body)->toBe('x abc x abc')
        ->and($issue->fresh()->body)->toBe('x abc x abc')
        ->and($issue->fresh()->revision)->toBe(1);
})->with([
    'comment, missing' => ['comment', 'zzz', 'was not found'],
    'comment, ambiguous' => ['comment', 'abc', 'occurs 2 times'],
    'issue, missing' => ['issue', 'zzz', 'was not found'],
    'issue, ambiguous' => ['issue', 'abc', 'occurs 2 times'],
]);

it('returns a conflict for replace under a stale revision', function (): void {
    $comment = Comment::factory()->create(['body' => 'Changed elsewhere abc', 'revision' => 2]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'replace' => [['from' => 'abc', 'to' => 'def']]])
        ->assertHasNoErrors()
        ->assertSee('"conflict":true');

    expect($comment->fresh()->body)->toBe('Changed elsewhere abc');
});

it('refuses body and replace together, and replace without a comment to edit', function (): void {
    $comment = Comment::factory()->create(['body' => 'abc', 'revision' => 1]);
    $issue = Issue::factory()->create(['body' => 'abc', 'revision' => 1]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'body' => 'new', 'replace' => [['from' => 'abc', 'to' => 'd']]])->assertHasErrors(['not both']);
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'body' => 'new', 'replace' => [['from' => 'abc', 'to' => 'd']]])->assertHasErrors(['not both']);
    TodoServer::tool(CommentOnTodoTask::class, ['issueId' => $issue->id, 'replace' => [['from' => 'abc', 'to' => 'd']]])->assertHasErrors(['commentId']);

    expect($comment->fresh()->body)->toBe('abc')->and($issue->fresh()->body)->toBe('abc');
});

it('refuses a replace that would grow the text past the body limit, changing nothing', function (): void {
    $comment = Comment::factory()->create(['body' => str_repeat('a ', 100), 'revision' => 1]);

    TodoServer::tool(CommentOnTodoTask::class, ['commentId' => $comment->id, 'expectedRevision' => 1, 'replace' => [['from' => 'a', 'to' => str_repeat('b', 1000), 'all' => true]]])
        ->assertHasErrors(['longer than 65535']);

    expect($comment->fresh()->body)->toBe(str_repeat('a ', 100))->and($comment->fresh()->revision)->toBe(1);
});
