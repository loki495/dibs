<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

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

describe('parent project and Group defaults', function (): void {
    beforeEach(function (): void {
        $this->project = GitHubProject::factory()->create();
        $field = ProjectField::factory()->for($this->project, 'project')->create(['semantic_key' => 'group']);
        $this->groupOption = ProjectFieldOption::factory()->for($field, 'field')->create();
        $priorityField = ProjectField::factory()->for($this->project, 'project')->create(['semantic_key' => 'priority']);
        $this->priorityOption = ProjectFieldOption::factory()->for($priorityField, 'field')->create();
        $this->parentIssue = Issue::factory()->for(GitHubRepository::query()->sole(), 'repository')->create();
        $this->parentItem = ProjectItem::factory()->for($this->project, 'project')->for($this->parentIssue, 'issue')->create([
            'group_option_id' => $this->groupOption->id, 'priority_option_id' => $this->priorityOption->id,
        ]);
    });

    it('inherits missing project and Group but leaves Priority unset, with matching mirror writes', function (?int $empty): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Inherited', parentId: $this->parentIssue->id, area: $empty, groupId: $empty);
        $item = $issue->projectItems->sole();

        expect($item->project_id)->toBe($this->project->id)
            ->and($item->group_option_id)->toBe($this->groupOption->id)
            ->and($item->priority_option_id)->toBeNull()
            ->and($issue->parent_issue_id)->toBe($this->parentIssue->id)
            ->and(GitHubPushQueueItem::query()->where('operation', 'add_project_membership')->where('target_id', $item->id)->exists())->toBeTrue()
            ->and(GitHubPushQueueItem::query()->where('operation', 'set_project_item_group')->where('target_id', $item->id)->sole()->payload)
            ->toBe(['group_option_id' => $this->groupOption->id])
            ->and(GitHubPushQueueItem::query()->where('operation', 'set_project_item_priority')->exists())->toBeFalse();
    })->with([null, 0]);

    it('inherits the Group with an explicit matching project and Priority', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Same project', parentId: $this->parentIssue->id, area: $this->project->id, priorityId: $this->priorityOption->id);

        expect($issue->projectItems->sole()->group_option_id)->toBe($this->groupOption->id)
            ->and($issue->projectItems->sole()->priority_option_id)->toBe($this->priorityOption->id);
    });

    it('honors an explicit Group within the inherited project', function (): void {
        $other = ProjectFieldOption::factory()->for($this->groupOption->field, 'field')->create();
        $issue = app(CreateTodoIssue::class)->handle(title: 'Override', parentId: $this->parentIssue->id, groupId: $other->id);

        expect($issue->projectItems->sole()->group_option_id)->toBe($other->id);
    });

    it('creates an explicitly named Group within the inherited project', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Named override', parentId: $this->parentIssue->id, newGroupName: 'New child group');

        expect($issue->projectItems->sole()->project_id)->toBe($this->project->id)
            ->and($issue->projectItems->sole()->groupOption->name)->toBe('New child group');
    });

    it('does not carry the inherited Group into an explicit different project', function (): void {
        $other = GitHubProject::factory()->create();
        $issue = app(CreateTodoIssue::class)->handle(title: 'Other project', parentId: $this->parentIssue->id, area: $other->id);

        expect($issue->projectItems->sole()->project_id)->toBe($other->id)
            ->and($issue->projectItems->sole()->group_option_id)->toBeNull();
    });

    it('rejects a Group from a different project after resolving defaults without writing anything', function (): void {
        $other = GitHubProject::factory()->create();
        expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Invalid override', parentId: $this->parentIssue->id, area: $other->id, groupId: $this->groupOption->id))
            ->toThrow(TodoValidationException::class, 'Group is no longer available');

        expect(Issue::query()->count())->toBe(1)->and(GitHubPushQueueItem::query()->count())->toBe(0);
    });

    it('requires an explicit project when the parent has several active memberships', function (): void {
        ProjectItem::factory()->for($this->parentIssue, 'issue')->create();

        expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Ambiguous', parentId: $this->parentIssue->id))
            ->toThrow(TodoValidationException::class, 'Choose an area explicitly');
        expect(Issue::query()->count())->toBe(1)->and(GitHubPushQueueItem::query()->count())->toBe(0);

        $issue = app(CreateTodoIssue::class)->handle(title: 'Explicit project', parentId: $this->parentIssue->id, area: $this->project->id);
        expect($issue->projectItems->sole()->group_option_id)->toBe($this->groupOption->id);
    });

    it('ignores unavailable or archived memberships and unavailable projects', function (string $retired): void {
        match ($retired) {
            'membership' => $this->parentItem->update(['is_available' => false]),
            'archived' => $this->parentItem->update(['archived_at' => now()]),
            'project' => $this->project->update(['is_available' => false]),
        };

        $issue = app(CreateTodoIssue::class)->handle(title: 'No active defaults', parentId: $this->parentIssue->id);
        expect($issue->projectItems)->toHaveCount(0);
    })->with(['membership', 'archived', 'project']);

    it('does not inherit a Group whose field is unavailable', function (): void {
        $this->groupOption->field->update(['is_available' => false]);
        $issue = app(CreateTodoIssue::class)->handle(title: 'No stale group', parentId: $this->parentIssue->id);
        expect($issue->projectItems->sole()->group_option_id)->toBeNull();
    });

    it('keeps parentless creation unassigned and accepts a parent with no project', function (): void {
        $plain = app(CreateTodoIssue::class)->handle(title: 'No parent');
        $unassignedParent = Issue::factory()->for($this->parentIssue->repository, 'repository')->create();
        $child = app(CreateTodoIssue::class)->handle(title: 'No parent project', parentId: $unassignedParent->id);
        expect($plain->projectItems)->toHaveCount(0)->and($child->projectItems)->toHaveCount(0);
    });

    it('replays idempotent creates without recalculating changed parent defaults', function (): void {
        $first = app(CreateTodoIssue::class)->handle(title: 'Retry', parentId: $this->parentIssue->id, idempotencyKey: 'inherit-retry');
        $this->parentItem->update(['group_option_id' => null]);
        $retry = app(CreateTodoIssue::class)->handle(title: 'Retry', parentId: $this->parentIssue->id, idempotencyKey: 'inherit-retry');
        expect($retry->id)->toBe($first->id)
            ->and($retry->projectItems->sole()->group_option_id)->toBe($this->groupOption->id);
    });

    it('preserves project and Group when an existing issue is reparented', function (): void {
        $other = GitHubProject::factory()->create();
        $field = ProjectField::factory()->for($other, 'project')->create(['semantic_key' => 'group']);
        $group = ProjectFieldOption::factory()->for($field, 'field')->create();
        $existing = app(CreateTodoIssue::class)->handle(title: 'Move parent only', area: $other->id, groupId: $group->id);

        app(ReviseTodoIssue::class)->handle(id: $existing->id, expectedRevision: 1, parentId: $this->parentIssue->id);
        expect($existing->fresh()->parent_issue_id)->toBe($this->parentIssue->id)
            ->and($existing->fresh()->projectItems->sole()->project_id)->toBe($other->id)
            ->and($existing->fresh()->projectItems->sole()->group_option_id)->toBe($group->id);
    });
});
