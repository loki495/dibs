<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('adds a local-first comment and enqueues the GitHub push', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:comment', ['issue' => $issue->id, '--body' => 'Checkpoint'])
        ->expectsOutputToContain('comment_id')
        ->assertSuccessful();

    $comment = Comment::query()->where('issue_id', $issue->id)->sole();
    expect($comment->body)->toBe('Checkpoint')
        ->and($comment->revision)->toBe(1)
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_comment')->where('target_id', $comment->id)->exists())->toBeTrue();
});

it('rejects a blank comment body without crashing', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:comment', ['issue' => $issue->id, '--body' => '   '])->assertFailed();
});

it('rejects a call with no --body at all', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:comment', ['issue' => $issue->id])->assertFailed();
});

it('edits an existing comment when --comment-id and --expected-revision are given', function (): void {
    $comment = Comment::factory()->create(['body' => 'Old', 'revision' => 1]);

    $this->artisan('todo:agent:comment', ['--body' => 'New', '--comment-id' => (string) $comment->id, '--expected-revision' => '1'])
        ->expectsOutputToContain('"conflict": false')
        ->assertSuccessful();

    expect($comment->fresh()->body)->toBe('New')->and($comment->fresh()->revision)->toBe(2);
});

it('returns a non-error conflict payload on a stale edit', function (): void {
    $comment = Comment::factory()->create(['body' => 'Original', 'revision' => 1]);
    $comment->update(['body' => 'Changed elsewhere', 'revision' => 2]);

    $this->artisan('todo:agent:comment', ['--body' => 'My edit', '--comment-id' => (string) $comment->id, '--expected-revision' => '1'])
        ->expectsOutputToContain('"conflict": true')
        ->assertSuccessful();

    expect($comment->fresh()->body)->toBe('Changed elsewhere');
});

it('rejects editing a comment without --expected-revision', function (): void {
    $comment = Comment::factory()->create(['revision' => 1]);

    $this->artisan('todo:agent:comment', ['--body' => 'New', '--comment-id' => (string) $comment->id])->assertFailed();
});

it('rejects a call with neither an issue nor --comment-id', function (): void {
    $this->artisan('todo:agent:comment', ['--body' => 'Orphaned'])->assertFailed();
});
