<?php

declare(strict_types=1);

use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('revises the title and body when the expected revision matches, and increments the revision', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old title', 'body' => 'Old body', 'revision' => 1]);

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'New title', body: 'New body');

    expect($revised->title)->toBe('New title')
        ->and($revised->body)->toBe('New body')
        ->and($revised->revision)->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'update_issue_body')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('allows revising only the body, leaving the title untouched', function (): void {
    $issue = Issue::factory()->create(['title' => 'Keep me', 'body' => 'Old body', 'revision' => 1]);

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, body: 'New body only');

    expect($revised->title)->toBe('Keep me')->and($revised->body)->toBe('New body only');
});

it('rejects a revision with neither title nor body', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1))
        ->toThrow(TodoValidationException::class, 'Provide a title, a body, or both');
});

it('rejects a stale revision without applying the change', function (): void {
    $issue = Issue::factory()->create(['title' => 'Original', 'revision' => 1]);
    $issue->update(['title' => 'Changed by someone else', 'revision' => 2]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'My change'))
        ->toThrow(TodoStaleRevisionException::class, 'has changed since');

    expect($issue->fresh()->title)->toBe('Changed by someone else')
        ->and($issue->fresh()->revision)->toBe(2);
});

it('throws a distinct not-found error for a nonexistent issue', function (): void {
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: 999_999, expectedRevision: 1, title: 'x'))
        ->toThrow(TodoRecordNotFoundException::class);
});

it('throws a distinct unavailable error for a soft-removed issue', function (): void {
    $issue = Issue::factory()->create(['is_available' => false, 'revision' => 1]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'x'))
        ->toThrow(TodoRecordUnavailableException::class);
});

it('adds an optional revision-note comment for a material change', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, body: 'New body', note: 'Superseded by new findings');

    expect(Comment::query()->where('issue_id', $issue->id)->sole()->body)->toContain('Superseded by new findings');
});

it('is idempotent: retrying the same key returns the original result without revising twice', function (): void {
    $issue = Issue::factory()->create(['title' => 'Original', 'revision' => 1]);

    $first = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'Revised once', idempotencyKey: 'revise-1');
    $second = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'Revised once', idempotencyKey: 'revise-1');

    expect($second->title)->toBe('Revised once')
        ->and($second->revision)->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'update_issue_body')->count())->toBe(1);
});
