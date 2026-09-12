<?php

declare(strict_types=1);

use App\Actions\ReviseTodoComment;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;

it('revises a comment body when the expected revision matches, and increments the revision', function (): void {
    $comment = Comment::factory()->create(['body' => 'Old body', 'revision' => 1]);

    $revised = app(ReviseTodoComment::class)->handle($comment->id, 'New body', expectedRevision: 1);

    expect($revised->body)->toBe('New body')
        ->and($revised->revision)->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'update_comment')->where('target_id', $comment->id)->exists())->toBeTrue();
});

it('rejects a blank comment body', function (): void {
    $comment = Comment::factory()->create(['revision' => 1]);

    expect(fn () => app(ReviseTodoComment::class)->handle($comment->id, '   ', expectedRevision: 1))
        ->toThrow(TodoValidationException::class, 'comment body is required');
});

it('rejects a stale revision without applying the change', function (): void {
    $comment = Comment::factory()->create(['body' => 'Original', 'revision' => 1]);
    $comment->update(['body' => 'Changed by someone else', 'revision' => 2]);

    expect(fn () => app(ReviseTodoComment::class)->handle($comment->id, 'My change', expectedRevision: 1))
        ->toThrow(TodoStaleRevisionException::class, 'has changed since');

    expect($comment->fresh()->body)->toBe('Changed by someone else');
});

it('throws a distinct not-found error for a nonexistent comment', function (): void {
    expect(fn () => app(ReviseTodoComment::class)->handle(999_999, 'Body', expectedRevision: 1))
        ->toThrow(TodoRecordNotFoundException::class);
});

it('throws a distinct unavailable error for a soft-removed comment', function (): void {
    $comment = Comment::factory()->create(['is_available' => false, 'revision' => 1]);

    expect(fn () => app(ReviseTodoComment::class)->handle($comment->id, 'Body', expectedRevision: 1))
        ->toThrow(TodoRecordUnavailableException::class);
});

it('is idempotent: retrying the same key returns the original result without revising twice', function (): void {
    $comment = Comment::factory()->create(['body' => 'Original', 'revision' => 1]);

    $first = app(ReviseTodoComment::class)->handle($comment->id, 'Revised once', expectedRevision: 1, idempotencyKey: 'revise-comment-1');
    $second = app(ReviseTodoComment::class)->handle($comment->id, 'Revised once', expectedRevision: 1, idempotencyKey: 'revise-comment-1');

    expect($second->body)->toBe('Revised once')
        ->and($second->revision)->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'update_comment')->count())->toBe(1);
});
