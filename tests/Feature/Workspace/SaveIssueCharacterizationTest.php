<?php

declare(strict_types=1);

use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function configuredRepository(): GitHubRepository
{
    return GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
}

function workspaceFor(Issue $issue): Testable
{
    return Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)->call('beginEdit');
}

it('sets and then clears a Priority on a Project membership', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_1', 'priority_option_id' => null]);

    workspaceFor($issue)->set('editPriority', $priority->id)->call('saveIssue')->assertSet('editingIssue', false);

    expect($item->refresh()->priority_option_id)->toBe($priority->id);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'set_project_item_priority')->where('target_id', $item->id)->sole();
    expect($enqueued->payload)->toBe(['priority_option_id' => $priority->id]);

    workspaceFor($issue)->set('editPriority', 0)->call('saveIssue')->assertSet('editingIssue', false);

    expect($item->refresh()->priority_option_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_priority')->where('target_id', $item->id)->count())->toBe(1);
});

it('creates a new Group option in the chosen Area, enqueues it, and assigns it to the task', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

    workspaceFor($issue)->set('editArea', $project->id)->set('editNewGroup', '  Fresh Group  ')->call('saveIssue')->assertSet('editingIssue', false);

    $option = ProjectFieldOption::query()->where('project_field_id', $field->id)->sole();
    expect($option)->name->toBe('Fresh Group')->github_option_id->toBeNull();
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'create_group_option')->where('target_id', $option->id)->sole();
    expect($enqueued->payload)->toBe(['name' => 'Fresh Group', 'color' => 'GRAY']);
    $item = ProjectItem::query()->where('project_id', $project->id)->where('issue_id', $issue->id)->sole();
    expect($item->group_option_id)->toBe($option->id);
});

it('reuses an existing Group case-insensitively instead of creating a duplicate option', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $existing = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    workspaceFor($issue)->set('editArea', $project->id)->set('editNewGroup', 'CAREER')->call('saveIssue')->assertSet('editingIssue', false);

    expect(ProjectFieldOption::query()->where('project_field_id', $field->id)->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->where('operation', 'create_group_option')->count())->toBe(0);
    expect(ProjectItem::query()->where('project_id', $project->id)->where('issue_id', $issue->id)->sole()->group_option_id)->toBe($existing->id);
});

it('rejects a Group that belongs to a different Area and changes nothing', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['title' => 'Old title']);
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $otherField = ProjectField::factory()->for($otherProject, 'project')->create(['semantic_key' => 'group']);
    $foreignGroup = ProjectFieldOption::factory()->for($otherField, 'field')->create();

    workspaceFor($issue)->set('editTitle', 'Should not save')->set('editArea', $project->id)->set('editGroup', $foreignGroup->id)->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editError', 'The selected Group is not available in this Area. Refresh and try again.');

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
    expect(ProjectItem::query()->count())->toBe(0);
});

it('rejects a Priority that is not a priority option of the chosen Area', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['title' => 'Old title']);
    $project = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $notAPriority = ProjectFieldOption::factory()->for($groupField, 'field')->create();

    workspaceFor($issue)->set('editTitle', 'Should not save')->set('editArea', $project->id)->set('editPriority', $notAPriority->id)->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editError', 'The selected Priority is not available in this Area. Refresh and try again.');

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects a Priority when no Area is chosen', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();

    workspaceFor($issue)->set('editArea', 0)->set('editPriority', $priority->id)->call('saveIssue')
        ->assertSet('editError', 'The selected Priority is not available in this Area. Refresh and try again.');

    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects an unavailable parent and changes nothing', function (): void {
    $repository = configuredRepository();
    $issue = Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title']);
    $parent = Issue::factory()->for($repository, 'repository')->create(['is_available' => false]);

    workspaceFor($issue)->set('editTitle', 'Should not save')->set('editParent', $parent->id)->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editError', 'One or more selected task fields are no longer available. Refresh and try again.');

    expect($issue->refresh())->title->toBe('Old title')->parent_issue_id->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects a label id that no longer exists and changes nothing', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['title' => 'Old title']);

    workspaceFor($issue)->set('editTitle', 'Should not save')->set('editLabels', [999999])->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editError', 'One or more selected task fields are no longer available. Refresh and try again.');

    expect($issue->refresh()->title)->toBe('Old title');
    expect($issue->labels()->count())->toBe(0);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects an unavailable label and changes nothing', function (): void {
    $repository = configuredRepository();
    $issue = Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title']);
    $gone = Label::factory()->for($repository, 'repository')->create(['is_available' => false]);

    workspaceFor($issue)->set('editTitle', 'Should not save')->set('editLabels', [$gone->id])->call('saveIssue')
        ->assertSet('editError', 'One or more selected task fields are no longer available. Refresh and try again.');

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects an unavailable Area and changes nothing', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['title' => 'Old title']);
    $project = GitHubProject::factory()->create(['is_available' => false]);

    workspaceFor($issue)->set('editTitle', 'Should not save')->set('editArea', $project->id)->call('saveIssue')
        ->assertSet('editError', 'One or more selected task fields are no longer available. Refresh and try again.');

    expect($issue->refresh()->title)->toBe('Old title');
    expect(ProjectItem::query()->count())->toBe(0);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('closes the edit form and saves nothing when the repository is not configured locally', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old title']);

    workspaceFor($issue)->set('editTitle', 'Should not save')->call('saveIssue')->assertSet('editingIssue', false);

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects a blank title with a validation error and saves nothing', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['title' => 'Old title']);

    workspaceFor($issue)->set('editTitle', '')->call('saveIssue')->assertHasErrors(['editTitle' => 'required']);

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('clears the body to null when the edited body is empty', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['body' => 'Some notes']);

    workspaceFor($issue)->set('editBody', '')->call('saveIssue')->assertSet('editingIssue', false);

    expect($issue->refresh()->body)->toBeNull();
});

it('does nothing and deselects the task when it becomes unavailable before saving', function (): void {
    $issue = Issue::factory()->for(configuredRepository(), 'repository')->create(['title' => 'Old title']);

    $component = workspaceFor($issue);
    $issue->update(['is_available' => false]);
    $component->set('editTitle', 'Should not save')->call('saveIssue')->assertSet('editingIssue', false)->assertSet('selected', 0);

    expect($issue->refresh()->title)->toBe('Old title');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('does not close or enqueue anything for a task that became unavailable', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id);
    $issue->update(['is_available' => false]);
    $component->call('closeIssue')->assertSet('selected', 0);

    expect($issue->refresh()->state)->toBe('OPEN');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
