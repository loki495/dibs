<?php

declare(strict_types=1);

use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

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

it('rejects a revision with neither title, body, nor groupId', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1))
        ->toThrow(TodoValidationException::class, 'Provide a title, a body, a groupId');
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

it('moves an issue into a different Group within its existing area', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create(['revision' => 1]);
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, groupId: $group->id);

    expect($revised->revision)->toBe(2)
        ->and($item->fresh()->group_option_id)->toBe($group->id)
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_project_item_group')->where('target_id', $item->id)->exists())->toBeTrue();
});

it('rejects setting a Group on an issue with no area assigned yet', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create(['revision' => 1]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, groupId: $group->id))
        ->toThrow(TodoValidationException::class, 'no area assigned yet');

    expect(Issue::query()->find($issue->id)->revision)->toBe(1);
});

it('rejects a Group that belongs to a different area than the issue', function (): void {
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($otherProject, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create(['revision' => 1]);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, groupId: $group->id))
        ->toThrow(TodoValidationException::class, 'not available in this area');
});

it('rejects a nonexistent groupId', function (): void {
    $project = GitHubProject::factory()->create();
    $issue = Issue::factory()->create(['revision' => 1]);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, groupId: 999_999))
        ->toThrow(TodoValidationException::class, 'not available in this area');
});
