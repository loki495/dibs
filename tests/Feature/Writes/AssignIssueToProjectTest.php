<?php

declare(strict_types=1);

use App\Actions\AssignIssueToProject;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\ProjectItem;

it('adds the issue to a project it was not in and enqueues the membership', function (): void {
    $issue = Issue::factory()->create();
    $project = GitHubProject::factory()->create();

    $item = app(AssignIssueToProject::class)->handle($issue, $project);

    expect($item)->toBeInstanceOf(ProjectItem::class)->project_id->toBe($project->id)->issue_id->toBe($issue->id)->github_node_id->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'add_project_membership')->where('target_id', $item->id)->count())->toBe(1);
});

it('reuses the existing membership in the same project without enqueueing anything', function (): void {
    $issue = Issue::factory()->create();
    $project = GitHubProject::factory()->create();
    $existing = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    $item = app(AssignIssueToProject::class)->handle($issue, $project);

    expect($item->is($existing))->toBeTrue();
    expect(ProjectItem::query()->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('moves off a pushed membership by enqueueing its deletion, and off an unpushed one by retiring it locally', function (): void {
    $issue = Issue::factory()->create();
    $pushed = ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_old']);
    $unpushed = ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => null]);
    $target = GitHubProject::factory()->create();

    app(AssignIssueToProject::class)->handle($issue, $target);

    expect(GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->where('target_id', $pushed->id)->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->where('target_id', $unpushed->id)->count())->toBe(0);
    expect($unpushed->refresh()->is_available)->toBeFalse();
});

it('leaves the issue in no project when given none, retiring or deleting every membership', function (): void {
    $issue = Issue::factory()->create();
    $pushed = ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_old']);
    $unpushed = ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => null]);

    $item = app(AssignIssueToProject::class)->handle($issue, null);

    expect($item)->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->where('target_id', $pushed->id)->count())->toBe(1);
    expect($unpushed->refresh()->is_available)->toBeFalse();
});

it('ignores memberships that are already unavailable or archived', function (): void {
    $issue = Issue::factory()->create();
    ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_gone', 'is_available' => false]);
    ProjectItem::factory()->for(GitHubProject::factory()->create(), 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_arch', 'archived_at' => now()]);

    app(AssignIssueToProject::class)->handle($issue, null);

    expect(GitHubPushQueueItem::query()->where('operation', 'delete_project_item')->count())->toBe(0);
});
