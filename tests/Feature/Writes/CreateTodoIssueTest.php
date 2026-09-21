<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('creates a local issue and enqueues a GitHub push without calling GitHub synchronously', function (): void {
    $issue = app(CreateTodoIssue::class)->handle(title: 'Ship the thing', body: 'Details');

    expect($issue->title)->toBe('Ship the thing')
        ->and($issue->body)->toBe('Details')
        ->and($issue->github_node_id)->toBeNull()
        ->and($issue->is_available)->toBeTrue()
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('assigns area, group, priority, parent, and labels', function (): void {
    $project = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($groupField, 'field')->create();
    $priorityField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($priorityField, 'field')->create();
    $parent = Issue::factory()->create();
    $label = Label::factory()->for($parent->repository, 'repository')->create();

    $issue = app(CreateTodoIssue::class)->handle(
        title: 'Child task',
        area: $project->id,
        parentId: $parent->id,
        groupId: $group->id,
        priorityId: $priority->id,
        labelIds: [$label->id],
    );

    expect($issue->parent_issue_id)->toBe($parent->id)
        ->and($issue->labels->pluck('id')->all())->toBe([$label->id])
        ->and($issue->projectItems->sole()->group_option_id)->toBe($group->id)
        ->and($issue->projectItems->sole()->priority_option_id)->toBe($priority->id);
});

it('creates a new Group by name when requested', function (): void {
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

    $issue = app(CreateTodoIssue::class)->handle(title: 'Task', area: $project->id, newGroupName: 'dotfiles');

    expect(ProjectFieldOption::query()->where('name', 'dotfiles')->exists())->toBeTrue()
        ->and($issue->projectItems->sole()->groupOption->name)->toBe('dotfiles');
});

it('reuses an existing Group with a case-insensitive name match instead of duplicating it', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $existing = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Dotfiles']);

    app(CreateTodoIssue::class)->handle(title: 'Task', area: $project->id, newGroupName: 'dotfiles');

    expect(ProjectFieldOption::query()->count())->toBe(1)
        ->and(ProjectFieldOption::query()->sole()->id)->toBe($existing->id);
});

it('creates a new label by name and reuses an existing one case-insensitively', function (): void {
    $repository = GitHubRepository::query()->sole();
    Label::factory()->for($repository, 'repository')->create(['name' => 'Next']);

    app(CreateTodoIssue::class)->handle(title: 'Task A', newLabelNames: ['next']);
    app(CreateTodoIssue::class)->handle(title: 'Task B', newLabelNames: ['brand-new']);

    expect(Label::query()->count())->toBe(2);
});

it('creates several new labels at once, deduplicating against selections and each other', function (): void {
    $repository = GitHubRepository::query()->sole();
    $existing = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);

    $issue = app(CreateTodoIssue::class)->handle(title: 'Task A', labelIds: [$existing->id], newLabelNames: ['Urgent', 'brand-new', 'brand-new', '']);

    expect(Label::query()->count())->toBe(2)
        ->and($issue->labels()->count())->toBe(2)
        ->and($issue->labels()->pluck('name')->all())->toEqualCanonicalizing(['urgent', 'brand-new']);
});

it('rejects creating a new Group without an area', function (): void {
    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Task', newGroupName: 'dotfiles'))
        ->toThrow(TodoValidationException::class, 'Choose an area');
});

it('rejects an unavailable area', function (): void {
    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Task', area: 999_999))
        ->toThrow(TodoValidationException::class, 'area is no longer available');
});

it('rejects an unavailable parent', function (): void {
    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Task', parentId: 999_999))
        ->toThrow(TodoValidationException::class, 'parent is no longer available');
});

it('rejects a Group from another Project', function (): void {
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($otherProject, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();

    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Task', area: $project->id, groupId: $group->id))
        ->toThrow(TodoValidationException::class, 'Group is no longer available');
});

it('rejects a Priority from another Project', function (): void {
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($otherProject, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();

    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Task', area: $project->id, priorityId: $priority->id))
        ->toThrow(TodoValidationException::class, 'Priority is no longer available');
});

it('rejects labels from a different repository than the chosen parent', function (): void {
    $parent = Issue::factory()->create();
    $otherRepository = GitHubRepository::factory()->create();
    $label = Label::factory()->for($otherRepository, 'repository')->create();

    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Task', parentId: $parent->id, labelIds: [$label->id]))
        ->toThrow(TodoValidationException::class, 'labels are no longer available');
});

it('is idempotent: retrying the same key returns the original issue without creating a duplicate', function (): void {
    $first = app(CreateTodoIssue::class)->handle(title: 'Once only', idempotencyKey: 'agent-call-1');
    $second = app(CreateTodoIssue::class)->handle(title: 'Once only', idempotencyKey: 'agent-call-1');

    expect($second->id)->toBe($first->id)
        ->and(Issue::query()->count())->toBe(1)
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->count())->toBe(1);
});
