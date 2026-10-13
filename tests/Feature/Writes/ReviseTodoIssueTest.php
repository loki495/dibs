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
use App\Models\Label;
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

it('adds labels by name, creating a missing one, and queues only the difference', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $existing = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'bug']);

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['Bug', '  Agent   Task ']);

    expect($revised->revision)->toBe(2)
        ->and($issue->labels()->pluck('name')->sort()->values()->all())->toBe(['agent task', 'bug'])
        ->and(Label::query()->where('name', 'bug')->count())->toBe(1);
    $created = Label::query()->where('name', 'agent task')->sole();
    $push = GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->where('target_id', $issue->id)->sole();
    expect($push->payload['add_label_ids'])->toEqualCanonicalizing([$existing->id, $created->id])
        ->and($push->payload['remove_label_ids'])->toBe([])
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_label')->count())->toBe(1);
});

it('removes labels by name, leaves the others alone, and ignores a label the issue does not have', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $keep = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'next']);
    $drop = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'today']);
    Label::factory()->for($issue->repository, 'repository')->create(['name' => 'unattached']);
    $issue->labels()->attach([$keep->id, $drop->id]);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, removeLabels: ['TODAY', 'unattached', 'never existed']);

    expect($issue->labels()->pluck('labels.id')->all())->toBe([$keep->id])
        ->and(Label::query()->where('name', 'never existed')->exists())->toBeFalse();
    expect(GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->sole()->payload)
        ->toBe(['add_label_ids' => [], 'remove_label_ids' => [$drop->id]]);
});

it('applies labels together with a title change and a knowledge label like any other', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old', 'revision' => 1]);

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'New', addLabels: ['research']);

    expect($revised->title)->toBe('New')->and($issue->labels()->pluck('name')->all())->toBe(['research']);
});

it('queues nothing for a label-only revise that changes nothing, but still bumps the revision', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $label = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'bug']);
    $issue->labels()->attach($label);

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['bug'], removeLabels: ['absent']);

    expect($revised->revision)->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->exists())->toBeFalse();
});

it('rejects a name that is both added and removed, and a blank label name, without changing anything', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['Bug'], removeLabels: ['bug']))
        ->toThrow(TodoValidationException::class, 'both added and removed');
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['   ']))
        ->toThrow(TodoValidationException::class, 'blank');

    expect($issue->fresh()->revision)->toBe(1)->and($issue->labels()->count())->toBe(0);
});

it('rejects a stale revision on a label change without touching labels', function (): void {
    $issue = Issue::factory()->create(['revision' => 2]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['bug']))
        ->toThrow(TodoStaleRevisionException::class);

    expect($issue->labels()->count())->toBe(0)->and(Label::query()->where('name', 'bug')->exists())->toBeFalse();
});

it('returns the original result when a label revise is retried with the same idempotency key', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    $first = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['bug'], idempotencyKey: 'label-once');
    $second = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, addLabels: ['bug'], idempotencyKey: 'label-once');

    expect($second->id)->toBe($first->id)->and($issue->fresh()->revision)->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->count())->toBe(1);
});

function reviseGroupFixture(array $issueAttributes = []): array
{
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $issue = Issue::factory()->create(['revision' => 1, ...$issueAttributes]);
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    return [$issue, $item, $field];
}

it('moves the issue to a Group by name, creating it when missing and reusing it case-insensitively', function (): void {
    [$issue, $item, $field] = reviseGroupFixture();
    $existing = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Homelab']);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, newGroupName: ' homelab ');
    expect($item->fresh()->group_option_id)->toBe($existing->id);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 2, newGroupName: 'Brand New');
    $created = ProjectFieldOption::query()->where('name', 'Brand New')->sole();
    expect($item->fresh()->group_option_id)->toBe($created->id)
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_group_option')->count())->toBe(1)
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_project_item_group')->count())->toBe(1);
});

it('clears the Group and queues the clear only when there was one', function (): void {
    [$issue, $item, $field] = reviseGroupFixture();
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item->update(['group_option_id' => $group->id]);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, clearGroup: true);
    expect($item->fresh()->group_option_id)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_group')->count())->toBe(1);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 2, clearGroup: true);
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_group')->count())->toBe(1);
});

it('rejects conflicting or impossible Group changes without changing anything', function (): void {
    [$issue, $item, $field] = reviseGroupFixture();
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, groupId: $group->id, clearGroup: true))
        ->toThrow(TodoValidationException::class, 'only one of groupId, newGroupName or clearGroup');
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, newGroupName: '   '))
        ->toThrow(TodoValidationException::class, 'blank');

    $noArea = Issue::factory()->create(['revision' => 1]);
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $noArea->id, expectedRevision: 1, newGroupName: 'X'))
        ->toThrow(TodoValidationException::class, 'no area assigned');
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $noArea->id, expectedRevision: 1, clearGroup: true))
        ->toThrow(TodoValidationException::class, 'no area assigned');

    expect($issue->fresh()->revision)->toBe(1)->and($item->fresh()->group_option_id)->toBeNull();
});

it('moves the issue under a parent, queues it, and changes the parent together with a title', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old', 'revision' => 1]);
    $parent = Issue::factory()->create();

    $revised = app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'New', parentId: $parent->id);

    expect($revised->title)->toBe('New')->and($revised->parent_issue_id)->toBe($parent->id)
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('clears the parent, queueing the removal only for a parent that reached GitHub', function (): void {
    $parent = Issue::factory()->create();
    $synced = Issue::factory()->create(['revision' => 1, 'parent_issue_id' => $parent->id, 'github_parent_node_id' => 'PARENT_NODE']);
    $local = Issue::factory()->create(['revision' => 1, 'parent_issue_id' => $parent->id]);

    app(ReviseTodoIssue::class)->handle(id: $synced->id, expectedRevision: 1, clearParent: true);
    app(ReviseTodoIssue::class)->handle(id: $local->id, expectedRevision: 1, clearParent: true);

    expect($synced->fresh()->parent_issue_id)->toBeNull()->and($local->fresh()->parent_issue_id)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->pluck('target_id')->all())->toBe([$synced->id]);
});

it('rejects a self-parent, a descendant as parent, an unavailable parent and parentId with clearParent, rolling everything back', function (): void {
    $issue = Issue::factory()->create(['title' => 'Keep', 'revision' => 1]);
    $child = Issue::factory()->create(['parent_issue_id' => $issue->id]);
    $gone = Issue::factory()->create(['is_available' => false]);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'Changed', parentId: $issue->id))
        ->toThrow(TodoValidationException::class, 'own parent');
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, title: 'Changed', parentId: $child->id))
        ->toThrow(TodoValidationException::class, 'hierarchy cycle');
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, parentId: $gone->id))
        ->toThrow(TodoValidationException::class, 'no longer available');
    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: 1, parentId: $child->id, clearParent: true))
        ->toThrow(TodoValidationException::class, 'both set and cleared');

    expect($issue->fresh()->title)->toBe('Keep')->and($issue->fresh()->revision)->toBe(1)->and($issue->fresh()->parent_issue_id)->toBeNull();
});
