<?php

declare(strict_types=1);

use App\Actions\CreateTodoComment;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('creates a comment and enqueues its GitHub push', function (): void {
    $issue = Issue::factory()->create();

    $comment = app(CreateTodoComment::class)->handle($issue->id, '  A helpful note  ');

    expect($comment->body)->toBe('A helpful note')
        ->and($comment->issue_id)->toBe($issue->id)
        ->and($comment->revision)->toBe(1)
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_comment')->where('target_id', $comment->id)->exists())->toBeTrue();
});

it('rejects a blank comment body', function (): void {
    $issue = Issue::factory()->create();

    expect(fn () => app(CreateTodoComment::class)->handle($issue->id, '   '))
        ->toThrow(TodoValidationException::class, 'comment body is required');
});

it('throws a distinct not-found error for a nonexistent issue', function (): void {
    expect(fn () => app(CreateTodoComment::class)->handle(999_999, 'Body'))
        ->toThrow(TodoRecordNotFoundException::class);
});

it('throws a distinct unavailable error for a soft-removed issue', function (): void {
    $issue = Issue::factory()->create(['is_available' => false]);

    expect(fn () => app(CreateTodoComment::class)->handle($issue->id, 'Body'))
        ->toThrow(TodoRecordUnavailableException::class);
});

it('is idempotent: retrying the same key returns the original comment without duplicating it', function (): void {
    $issue = Issue::factory()->create();

    $first = app(CreateTodoComment::class)->handle($issue->id, 'Once only', idempotencyKey: 'comment-1');
    $second = app(CreateTodoComment::class)->handle($issue->id, 'Once only', idempotencyKey: 'comment-1');

    expect($second->id)->toBe($first->id)
        ->and(Comment::query()->count())->toBe(1);
});
