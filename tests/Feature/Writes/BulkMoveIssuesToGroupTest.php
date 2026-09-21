<?php

declare(strict_types=1);

use App\Actions\BulkMoveIssuesToGroup;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\Http;

it('moves several tasks into a Group at once, creating memberships for those without one', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $withMembership = Issue::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($withMembership, 'issue')->create();
    $withoutMembership = Issue::factory()->create();

    $moved = app(BulkMoveIssuesToGroup::class)->handle([$withMembership->id, $withoutMembership->id], $project->id, $group->id);

    expect($moved)->toBe(2);
    expect($item->refresh()->group_option_id)->toBe($group->id);
    $newItem = ProjectItem::query()->where('issue_id', $withoutMembership->id)->where('project_id', $project->id)->sole();
    expect($newItem->group_option_id)->toBe($group->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'add_project_membership')->where('target_id', $newItem->id)->exists())->toBeTrue();
    expect(GitHubPushQueueItem::query()->where('operation', 'set_project_item_group')->count())->toBe(2);
});

it('drops a task\'s membership in another project when moving it, matching the one-project-per-task invariant', function (): void {
    Http::fake();
    $oldProject = GitHubProject::factory()->create();
    $newProject = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($newProject, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create();
    $oldItem = ProjectItem::factory()->for($oldProject, 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_old']);

    app(BulkMoveIssuesToGroup::class)->handle([$issue->id], $newProject->id, $group->id);

    expect(GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->where('target_id', $oldItem->id)->exists())->toBeTrue();
});

it('deletes an obsolete membership locally without a GitHub round trip when it was never synced', function (): void {
    Http::fake();
    $oldProject = GitHubProject::factory()->create();
    $newProject = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($newProject, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create();
    $oldItem = ProjectItem::factory()->for($oldProject, 'project')->for($issue, 'issue')->create(['github_node_id' => null]);

    app(BulkMoveIssuesToGroup::class)->handle([$issue->id], $newProject->id, $group->id);

    expect($oldItem->refresh()->is_available)->toBeFalse()
        ->and(GitHubPushQueueItem::query()->where('target_id', $oldItem->id)->exists())->toBeFalse();
});

it('clears the group and enqueues clear_project_item_group when moving to no group at all', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group->id]);

    $moved = app(BulkMoveIssuesToGroup::class)->handle([$issue->id], $project->id, null);

    expect($moved)->toBe(1)
        ->and($item->refresh()->group_option_id)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_group')->where('target_id', $item->id)->exists())->toBeTrue();
});

it('rejects a Group that does not belong to the target area', function (): void {
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($otherProject, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create();

    expect(fn () => app(BulkMoveIssuesToGroup::class)->handle([$issue->id], $project->id, $group->id))
        ->toThrow(TodoValidationException::class);
});

it('rejects an unavailable area', function (): void {
    $issue = Issue::factory()->create();

    expect(fn () => app(BulkMoveIssuesToGroup::class)->handle([$issue->id], 999999, null))
        ->toThrow(TodoValidationException::class);
});
