<?php

declare(strict_types=1);

use App\Actions\CreateClosingComment;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('creates a closing comment with the raw note as its body and enqueues the push', function (): void {
    $issue = Issue::factory()->create();

    $comment = app(CreateClosingComment::class)->handle($issue->id, 'Shipped the fix.');

    expect($comment)->not->toBeNull()
        ->and($comment->body)->toBe('Shipped the fix.')
        ->and($comment->kind)->toBe(Comment::KIND_CLOSING)
        ->and($comment->references)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_comment')->where('target_id', $comment->id)->exists())->toBeTrue();
});

it('trims the note and stores trimmed, non-blank references', function (): void {
    $issue = Issue::factory()->create();

    $comment = app(CreateClosingComment::class)->handle($issue->id, '  Done.  ', ['  #42  ', '', '  ', 'commit abc123']);

    expect($comment->body)->toBe('Done.')
        ->and($comment->references)->toBe(['#42', 'commit abc123']);
});

it('creates nothing when there is no note and no references, the same as an ordinary completion with no summary', function (): void {
    $issue = Issue::factory()->create();

    expect(app(CreateClosingComment::class)->handle($issue->id, null))->toBeNull();
    expect(app(CreateClosingComment::class)->handle($issue->id, '   ', []))->toBeNull();
    expect(Comment::query()->count())->toBe(0);
});

it('creates nothing from references alone, without a note', function (): void {
    $issue = Issue::factory()->create();

    expect(app(CreateClosingComment::class)->handle($issue->id, null, ['#42']))->toBeNull();
    expect(Comment::query()->count())->toBe(0);
});
