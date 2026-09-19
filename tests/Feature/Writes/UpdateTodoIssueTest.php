<?php

declare(strict_types=1);

use App\Actions\UpdateTodoIssue;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

function updatableIssue(array $attributes = []): Issue
{
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);

    return Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title', 'body' => 'Old body', 'revision' => 3, ...$attributes]);
}

function updateOptionFor(GitHubProject $project, string $semanticKey): ProjectFieldOption
{
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => $semanticKey]);

    return ProjectFieldOption::factory()->for($field, 'field')->create();
}

function expectUntouched(Issue $issue): void
{
    expect($issue->refresh())->title->toBe('Old title')->body->toBe('Old body')->revision->toBe(3);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
}

it('applies every field in one save, bumps the revision once, and queues each GitHub change', function (): void {
    $issue = updatableIssue();
    $parent = Issue::factory()->for($issue->repository, 'repository')->create();
    $existingLabel = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'keep']);
    $project = GitHubProject::factory()->create();
    $group = updateOptionFor($project, 'group');
    $priority = updateOptionFor($project, 'priority');

    $updated = app(UpdateTodoIssue::class)->handle(
        id: $issue->id, expectedRevision: 3, title: '  New title ', body: 'New body', areaId: $project->id, parentId: $parent->id,
        groupId: $group->id, priorityId: $priority->id, labelIds: [$existingLabel->id], newLabelNames: ['Fresh'],
    );

    expect($updated)->title->toBe('New title')->body->toBe('New body')->revision->toBe(4)->parent_issue_id->toBe($parent->id);
    expect($updated->labels()->pluck('name')->sort()->values()->all())->toBe(['fresh', 'keep']);
    $item = ProjectItem::query()->where('issue_id', $issue->id)->where('project_id', $project->id)->sole();
    expect($item)->group_option_id->toBe($group->id)->priority_option_id->toBe($priority->id);
    $operations = GitHubPushQueueItem::query()->pluck('operation')->all();
    expect($operations)->toContain('update_issue_body', 'set_issue_labels', 'set_issue_parent', 'add_project_membership', 'set_project_item_group', 'set_project_item_priority', 'create_label');
});

it('creates a new Group in the chosen Area and assigns it', function (): void {
    $issue = updatableIssue();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

    app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title', areaId: $project->id, newGroupName: 'Fresh Group');

    $option = ProjectFieldOption::query()->where('project_field_id', $field->id)->sole();
    expect($option->name)->toBe('Fresh Group');
    expect(ProjectItem::query()->where('issue_id', $issue->id)->sole()->group_option_id)->toBe($option->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'create_group_option')->count())->toBe(1);
});

it('stores an empty body as null and always bumps the revision by exactly one', function (): void {
    $issue = updatableIssue();

    $updated = app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title', body: '');

    expect($updated)->body->toBeNull()->revision->toBe(4);
});

it('rejects a stale revision with the current record id and changes nothing', function (): void {
    $issue = updatableIssue();

    try {
        app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 2, title: 'Should not save');
        $this->fail('Expected a stale revision.');
    } catch (TodoStaleRevisionException $exception) {
        expect($exception->currentId)->toBe($issue->id);
    }

    expectUntouched($issue);
});

it('refuses an unavailable or unknown issue', function (): void {
    $gone = updatableIssue(['is_available' => false]);

    expect(fn () => app(UpdateTodoIssue::class)->handle(id: $gone->id, expectedRevision: 3, title: 'x'))->toThrow(TodoRecordUnavailableException::class);
    expect(fn () => app(UpdateTodoIssue::class)->handle(id: 999999, expectedRevision: 1, title: 'x'))->toThrow(TodoRecordUnavailableException::class);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('refuses to save when the repository is not configured locally', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old title', 'body' => 'Old body', 'revision' => 3]);

    expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'New'))
        ->toThrow(TodoRecordNotFoundException::class, 'The repository is not configured or not available locally. Refresh and try again.');

    expectUntouched($issue);
});

it('rejects a blank title', function (): void {
    $issue = updatableIssue();

    expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: '   '))
        ->toThrow(TodoValidationException::class, 'A title is required.');

    expectUntouched($issue);
});

/** @return array{0: array<string, mixed>, 1: string} the arguments to pass and the message expected */
function staleSelection(string $case, Issue $issue): array
{
    $gone = 'One or more selected task fields are no longer available. Refresh and try again.';
    $groupMessage = 'The selected Group is not available in this Area. Refresh and try again.';
    $priorityMessage = 'The selected Priority is not available in this Area. Refresh and try again.';

    return match ($case) {
        'unavailable area' => [['areaId' => GitHubProject::factory()->create(['is_available' => false])->id], $gone],
        'unknown area' => [['areaId' => 999999], $gone],
        'unavailable parent' => [['parentId' => Issue::factory()->for($issue->repository, 'repository')->create(['is_available' => false])->id], $gone],
        'unknown label' => [['labelIds' => [999999]], $gone],
        'group of another area' => [['areaId' => GitHubProject::factory()->create()->id, 'groupId' => updateOptionFor(GitHubProject::factory()->create(), 'group')->id], $groupMessage],
        'unknown group' => [['areaId' => GitHubProject::factory()->create()->id, 'groupId' => 999999], $groupMessage],
        'group without an area' => [['groupId' => updateOptionFor(GitHubProject::factory()->create(), 'group')->id], $groupMessage],
        'priority that is not a priority option' => [(function (): array {
            $project = GitHubProject::factory()->create();

            return ['areaId' => $project->id, 'priorityId' => updateOptionFor($project, 'group')->id];
        })(), $priorityMessage],
        'unknown priority' => [['areaId' => GitHubProject::factory()->create()->id, 'priorityId' => 999999], $priorityMessage],
        'priority without an area' => [['priorityId' => updateOptionFor(GitHubProject::factory()->create(), 'priority')->id], $priorityMessage],
        'new group without an area' => [['newGroupName' => 'Fresh'], 'Choose an area before creating a Group.'],
    };
}

it('rejects a stale field selection with the exact message and changes nothing', function (string $case): void {
    $issue = updatableIssue();
    [$arguments, $message] = staleSelection($case, $issue);

    expect(fn () => app(UpdateTodoIssue::class)->handle(...['id' => $issue->id, 'expectedRevision' => 3, 'title' => 'Should not save', ...$arguments]))
        ->toThrow(TodoValidationException::class, $message);

    expectUntouched($issue);
    expect(Label::query()->count())->toBe(0);
})->with([
    'unavailable area', 'unknown area', 'unavailable parent', 'unknown label', 'group of another area', 'unknown group',
    'group without an area', 'priority that is not a priority option', 'unknown priority', 'priority without an area', 'new group without an area',
]);

it('rolls everything back, including newly created labels, when creating a new Group fails', function (): void {
    $issue = updatableIssue();
    $project = GitHubProject::factory()->create();

    expect(fn () => app(UpdateTodoIssue::class)->handle(
        id: $issue->id, expectedRevision: 3, title: 'Should not save', areaId: $project->id, newGroupName: 'Fresh', newLabelNames: ['brand new'],
    ))->toThrow(TodoValidationException::class, 'This area has no Group field. Refresh and try again.');

    expectUntouched($issue);
    expect(Label::query()->count())->toBe(0);
});

it('clears the parent, Group and Priority when none are given', function (): void {
    $issue = updatableIssue();
    $parent = Issue::factory()->for($issue->repository, 'repository')->create();
    $issue->update(['parent_issue_id' => $parent->id]);
    $project = GitHubProject::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create([
        'group_option_id' => updateOptionFor($project, 'group')->id, 'priority_option_id' => updateOptionFor($project, 'priority')->id,
    ]);

    app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title', areaId: $project->id);

    expect($issue->refresh()->parent_issue_id)->toBeNull();
    expect($item->refresh())->group_option_id->toBeNull()->priority_option_id->toBeNull();
});

it('removes the issue from its area when none is given', function (): void {
    $issue = updatableIssue();
    $item = ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => null]);

    app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Old title');

    expect($item->refresh()->is_available)->toBeFalse();
});

it('rolls the whole edit back, including new labels, when the new parent would create a cycle', function (): void {
    $issue = updatableIssue();
    $child = Issue::factory()->for($issue->repository, 'repository')->create(['parent_issue_id' => $issue->id]);

    expect(fn () => app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: 3, title: 'Should not save', parentId: $child->id, newLabelNames: ['brand new']))
        ->toThrow(TodoValidationException::class, 'This parent would create a hierarchy cycle: it is already a sub-task of this task.');

    expectUntouched($issue);
    expect($issue->parent_issue_id)->toBeNull();
    expect(Label::query()->count())->toBe(0);
});
