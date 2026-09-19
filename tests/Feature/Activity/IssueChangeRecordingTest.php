<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Actions\ReviseTodoIssue;
use App\Actions\UpdateTodoIssue;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\ChangeLog;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\Activity\ActivityContext;

function activityRepository(): GitHubRepository
{
    return GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
}

function activityOption(GitHubProject $project, string $semanticKey, string $name): ProjectFieldOption
{
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => $semanticKey]);

    return ProjectFieldOption::factory()->for($field, 'field')->create(['name' => $name]);
}

function activityIssue(array $attributes = []): Issue
{
    return Issue::factory()->for(activityRepository(), 'repository')->create(['title' => 'Old title', 'body' => 'Old body', 'revision' => 3, ...$attributes]);
}

/** @return array<string, ChangeLog> keyed by action */
function changesByAction(): array
{
    return ChangeLog::query()->orderBy('id')->get()->keyBy('action')->all();
}

beforeEach(function (): void {
    app()->forgetScopedInstances();
    app(ActivityContext::class)->beginMcp('claude-code');
});

describe('CreateTodoIssue', function (): void {
    it('records one row for a created issue with every assigned field folded into its diff', function (): void {
        $repository = activityRepository();
        $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
        $group = activityOption($project, 'group', 'Dibs');
        $priority = activityOption($project, 'priority', 'High');
        $parent = Issue::factory()->for($repository, 'repository')->create(['title' => 'The parent']);
        $label = Label::factory()->for($repository, 'repository')->create(['name' => 'bug']);

        $issue = app(CreateTodoIssue::class)->handle(
            title: 'Child task', body: 'Details', area: $project->id, parentId: $parent->id,
            groupId: $group->id, priorityId: $priority->id, labelIds: [$label->id],
        );

        $log = ChangeLog::query()->sole();

        expect($log->action)->toBe('CreateTodoIssue')
            ->and($log->summary)->toBe('Created issue')
            ->and($log->category)->toBe(ChangeLog::CATEGORY_CHANGE)
            ->and($log->source)->toBe(ChangeLog::SOURCE_MCP)
            ->and($log->actor_label)->toBe('claude-code')
            ->and($log->subject_type)->toBe('issue')
            ->and($log->subject_id)->toBe($issue->id)
            ->and($log->subject_label)->toBe('Child task')
            ->and($log->changes)->toEqual([
                'title' => ['from' => null, 'to' => 'Child task'],
                'body' => ['from' => null, 'to' => 'Details'],
                'state' => ['from' => null, 'to' => 'OPEN'],
                'area' => ['from' => null, 'to' => 'Personal Projects'],
                'group' => ['from' => null, 'to' => 'Dibs'],
                'priority' => ['from' => null, 'to' => 'High'],
                'labels' => ['from' => null, 'to' => ['bug']],
                'parent' => ['from' => null, 'to' => "The parent (#{$parent->id})"],
            ]);
    });

    it('records only the fields a bare create actually set', function (): void {
        activityRepository();

        app(CreateTodoIssue::class)->handle(title: 'Just a title');

        expect(ChangeLog::query()->sole()->changes)->toEqual([
            'title' => ['from' => null, 'to' => 'Just a title'],
            'state' => ['from' => null, 'to' => 'OPEN'],
        ]);
    });

    it('also records the label and Group it had to create, under one request id', function (): void {
        activityRepository();
        $project = GitHubProject::factory()->create();
        ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

        app(CreateTodoIssue::class)->handle(title: 'New things', area: $project->id, newGroupName: 'Fresh Group', newLabelNames: ['Fresh']);

        $rows = changesByAction();

        expect($rows)->toHaveKeys(['CreateTodoIssue', 'ResolveLabels', 'ResolveGroupOption'])
            ->and(ChangeLog::count())->toBe(3)
            ->and($rows['ResolveLabels']->summary)->toBe('Created label')
            ->and($rows['ResolveLabels']->subject_type)->toBe('label')
            ->and($rows['ResolveLabels']->subject_label)->toBe('fresh')
            ->and($rows['ResolveGroupOption']->summary)->toBe('Created Group')
            ->and($rows['ResolveGroupOption']->subject_label)->toBe('Fresh Group')
            ->and($rows['CreateTodoIssue']->changes['labels']['to'])->toBe(['fresh'])
            ->and($rows['CreateTodoIssue']->changes['group']['to'])->toBe('Fresh Group')
            ->and(ChangeLog::query()->pluck('request_id')->unique())->toHaveCount(1);
    });

    it('records nothing when the create is rejected', function (): void {
        activityRepository();

        expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Orphan', parentId: 999_999))->toThrow(TodoValidationException::class);

        expect(ChangeLog::count())->toBe(0);
    });

    it('records one row, not two, when an idempotent create is retried', function (): void {
        activityRepository();

        $first = app(CreateTodoIssue::class)->handle(title: 'Once', idempotencyKey: 'key-1');
        $second = app(CreateTodoIssue::class)->handle(title: 'Once', idempotencyKey: 'key-1');

        expect($second->id)->toBe($first->id)
            ->and(ChangeLog::count())->toBe(1);
    });
});

describe('UpdateTodoIssue', function (): void {
    it('records each chained step against its own subject, all under one request id', function (): void {
        $issue = activityIssue();
        $parent = Issue::factory()->for($issue->repository, 'repository')->create(['title' => 'The parent']);
        $keep = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'keep']);
        $project = GitHubProject::factory()->create(['title' => 'Work']);
        $group = activityOption($project, 'group', 'Site A');
        $priority = activityOption($project, 'priority', 'Low');

        app(UpdateTodoIssue::class)->handle(
            id: $issue->id, expectedRevision: 3, title: 'New title', body: 'New body', areaId: $project->id, parentId: $parent->id,
            groupId: $group->id, priorityId: $priority->id, labelIds: [$keep->id], newLabelNames: ['Fresh'],
        );

        $rows = changesByAction();

        expect(array_keys($rows))->toEqualCanonicalizing([
            'ResolveLabels', 'UpdateTodoIssue', 'SyncIssueLabels', 'MoveIssueUnderParent', 'AssignIssueToProject', 'ApplyProjectItemFields',
        ])
            ->and($rows['UpdateTodoIssue']->summary)->toBe('Updated issue: title, body')
            ->and($rows['UpdateTodoIssue']->changes)->toBe([
                'title' => ['from' => 'Old title', 'to' => 'New title'],
                'body' => ['from' => 'Old body', 'to' => 'New body'],
            ])
            ->and($rows['SyncIssueLabels']->summary)->toBe('Changed labels: +fresh, +keep')
            ->and($rows['SyncIssueLabels']->changes)->toBe(['labels' => ['from' => [], 'to' => ['fresh', 'keep']]])
            ->and($rows['MoveIssueUnderParent']->summary)->toBe('Set parent')
            ->and($rows['MoveIssueUnderParent']->changes)->toBe(['parent' => ['from' => null, 'to' => "The parent (#{$parent->id})"]])
            ->and($rows['AssignIssueToProject']->summary)->toBe('Changed area')
            ->and($rows['AssignIssueToProject']->changes)->toBe(['area' => ['from' => null, 'to' => 'Work']])
            ->and($rows['ApplyProjectItemFields']->summary)->toBe('Changed group and priority')
            ->and($rows['ApplyProjectItemFields']->changes)->toBe([
                'group' => ['from' => null, 'to' => 'Site A'],
                'priority' => ['from' => null, 'to' => 'Low'],
            ])
            ->and(collect($rows)->except('ResolveLabels')->every(fn (ChangeLog $row): bool => $row->subject_id === $issue->id))->toBeTrue()
            ->and(ChangeLog::query()->pluck('request_id')->unique())->toHaveCount(1);
    });

    it('records nothing for a save that changes nothing, even though the revision still bumps', function (): void {
        $issue = activityIssue();

        $updated = app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title', body: 'Old body');

        expect($updated->revision)->toBe(4)
            ->and(ChangeLog::count())->toBe(0);
    });

    it('records a label-only change as a single label row', function (): void {
        $issue = activityIssue();
        $old = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'old']);
        $new = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'new']);
        $issue->labels()->attach($old->id);

        app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title', body: 'Old body', labelIds: [$new->id]);

        $log = ChangeLog::query()->sole();

        expect($log->action)->toBe('SyncIssueLabels')
            ->and($log->summary)->toBe('Changed labels: +new, -old')
            ->and($log->changes)->toBe(['labels' => ['from' => ['old'], 'to' => ['new']]]);
    });

    it('records clearing a parent and moving to another area', function (): void {
        $parent = Issue::factory()->for(activityRepository(), 'repository')->create(['title' => 'Old parent']);
        $issue = Issue::factory()->for($parent->repository, 'repository')->create(['title' => 'Old title', 'body' => 'Old body', 'revision' => 3, 'parent_issue_id' => $parent->id]);
        $oldProject = GitHubProject::factory()->create(['title' => 'Work']);
        $newProject = GitHubProject::factory()->create(['title' => 'Random Tasks']);
        ProjectItem::factory()->for($oldProject, 'project')->for($issue, 'issue')->create();

        app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title', body: 'Old body', areaId: $newProject->id);

        $rows = changesByAction();

        expect(array_keys($rows))->toEqualCanonicalizing(['MoveIssueUnderParent', 'AssignIssueToProject'])
            ->and($rows['MoveIssueUnderParent']->summary)->toBe('Cleared parent')
            ->and($rows['MoveIssueUnderParent']->changes)->toBe(['parent' => ['from' => "Old parent (#{$parent->id})", 'to' => null]])
            ->and($rows['AssignIssueToProject']->changes)->toBe(['area' => ['from' => 'Work', 'to' => 'Random Tasks']]);
    });

    it('records nothing for a stale revision or a rejected field', function (): void {
        $issue = activityIssue();

        expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 2, title: 'Nope'))->toThrow(TodoStaleRevisionException::class);
        expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Nope', parentId: 999_999))->toThrow(TodoValidationException::class);
        expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: '   '))->toThrow(TodoValidationException::class);

        expect(ChangeLog::count())->toBe(0);
    });

    it('rolls its earlier log rows back when a later step is rejected', function (): void {
        $issue = activityIssue();
        $child = Issue::factory()->for($issue->repository, 'repository')->create(['parent_issue_id' => $issue->id]);

        expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Renamed', body: 'Old body', parentId: $child->id))
            ->toThrow(TodoValidationException::class, 'hierarchy cycle');

        expect($issue->fresh()->title)->toBe('Old title')
            ->and(ChangeLog::count())->toBe(0);
    });
});

describe('ReviseTodoIssue', function (): void {
    it('records a title and body revision', function (): void {
        $issue = activityIssue();

        app(ReviseTodoIssue::class)->handle($issue->id, 3, title: 'Revised title');

        $log = ChangeLog::query()->sole();

        expect($log->action)->toBe('ReviseTodoIssue')
            ->and($log->summary)->toBe('Revised issue: title')
            ->and($log->changes)->toBe(['title' => ['from' => 'Old title', 'to' => 'Revised title']]);
    });

    it('records a Group change against the issue', function (): void {
        $issue = activityIssue();
        $project = GitHubProject::factory()->create();
        $old = activityOption($project, 'group', 'Old group');
        $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $old->id]);
        $new = ProjectFieldOption::factory()->for($old->field, 'field')->create(['name' => 'New group']);

        app(ReviseTodoIssue::class)->handle($issue->id, 3, groupId: $new->id);

        $log = ChangeLog::query()->sole();

        expect($log->action)->toBe('ReviseTodoIssue')
            ->and($log->subject_id)->toBe($issue->id)
            ->and($log->summary)->toBe('Changed group')
            ->and($log->changes)->toBe(['group' => ['from' => 'Old group', 'to' => 'New group']])
            ->and($item->fresh()->group_option_id)->toBe($new->id);
    });

    it('records nothing when the revision changes no field', function (): void {
        $issue = activityIssue();

        app(ReviseTodoIssue::class)->handle($issue->id, 3, title: 'Old title');

        expect(ChangeLog::count())->toBe(0);
    });

    it('records nothing for a stale revision or an empty revise', function (): void {
        $issue = activityIssue();

        expect(fn () => app(ReviseTodoIssue::class)->handle($issue->id, 2, title: 'Nope'))->toThrow(TodoStaleRevisionException::class);
        expect(fn () => app(ReviseTodoIssue::class)->handle($issue->id, 3))->toThrow(TodoValidationException::class);

        expect(ChangeLog::count())->toBe(0);
    });
});
