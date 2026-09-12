<?php

declare(strict_types=1);

use App\Actions\BuildIssueTree;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('preserves nested sibling order and includes standalone tasks', function (): void {
    $root = Issue::factory()->create(['title' => 'Website']);
    $later = Issue::factory()->for($root, 'parent')->create(['title' => 'Later', 'sibling_position' => 2]);
    $first = Issue::factory()->for($root, 'parent')->create(['title' => 'First', 'sibling_position' => 1]);
    $standalone = Issue::factory()->create(['title' => 'Standalone']);
    $rows = app(BuildIssueTree::class)->handle()['rows'];
    $ids = array_column($rows, 'id');
    expect(array_search($first->id, $ids))->toBeLessThan(array_search($later->id, $ids))
        ->and($ids)->toContain($standalone->id)
        ->and(collect($rows)->firstWhere('id', $first->id)['ancestors'])->toBe([$root->id]);
});

it('keeps ancestor context for search and cross-area parents without including unrelated children', function (): void {
    $project = GitHubProject::factory()->create();
    $root = Issue::factory()->create(['title' => 'Website']);
    $child = Issue::factory()->for($root, 'parent')->create(['title' => 'Find this task']);
    $other = Issue::factory()->for($root, 'parent')->create(['title' => 'Unrelated']);
    ProjectItem::factory()->for($project, 'project')->for($child, 'issue')->create();
    $result = app(BuildIssueTree::class)->handle(area: $project->id, search: 'Find this');
    expect(array_column($result['rows'], 'id'))->toBe([$root->id, $child->id])
        ->and($result['rows'][0]['context'])->toBeTrue()
        ->and($result['rows'][0]['outsideArea'])->toBeTrue()
        ->and($result['rows'][0]['hasChildren'])->toBeTrue();
});

it('separates knowledge from tasks and excludes containers from actionable counts', function (): void {
    $parentLabel = Label::factory()->create(['name' => 'parent']);
    $lessonLabel = Label::factory()->create(['name' => 'lesson']);
    $root = Issue::factory()->create();
    $root->labels()->attach($parentLabel);
    $lesson = Issue::factory()->for($root, 'parent')->create();
    $lesson->labels()->attach($lessonLabel);
    $task = Issue::factory()->for($root, 'parent')->create();
    $action = app(BuildIssueTree::class);
    expect(array_column($action->handle()['rows'], 'id'))->toContain($task->id)->not->toContain($lesson->id)
        ->and($action->handle()['taskCount'])->toBe(1)
        ->and(array_column($action->handle(view: 'knowledge')['rows'], 'id'))->toBe([$root->id, $lesson->id]);
});

it('builds daily picks using local dates without treating knowledge, closed tasks or unscheduled tasks as due', function (): void {
    $this->travelTo(Carbon::parse('2026-09-09T01:00:00Z'));
    $due = Issue::factory()->create();
    ProjectItem::factory()->for($due, 'issue')->create(['due_on' => '2026-09-08']);
    $future = Issue::factory()->create();
    ProjectItem::factory()->for($future, 'issue')->create(['planned_on' => '2026-09-09']);
    $pick = Issue::factory()->create();
    $pick->labels()->attach(Label::factory()->create(['name' => 'today']));
    $unscheduled = Issue::factory()->create();
    $closed = Issue::factory()->create(['state' => 'CLOSED']);
    ProjectItem::factory()->for($closed, 'issue')->create(['due_on' => '2026-09-07']);
    $ids = array_column(app(BuildIssueTree::class)->handle(view: 'daily')['rows'], 'id');
    expect($ids)->toContain($due->id, $pick->id)->not->toContain($future->id, $unscheduled->id, $closed->id);
});

it('terminates malformed cycles and keeps each issue discoverable once', function (): void {
    $one = Issue::factory()->create();
    $two = Issue::factory()->for($one, 'parent')->create();
    $one->update(['parent_issue_id' => $two->id]);
    $ids = array_column(app(BuildIssueTree::class)->handle()['rows'], 'id');
    expect($ids)->toHaveCount(2)->toContain($one->id, $two->id);
});

it('does not issue a query per descendant', function (): void {
    $root = Issue::factory()->create();
    Issue::factory()->count(25)->for($root, 'parent')->create();
    DB::enableQueryLog();
    app(BuildIssueTree::class)->handle();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
    DB::disableQueryLog();
});

it('uses Group as a virtual root in an area and omits that root when filtering by the Group', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $root = Issue::factory()->create(['title' => 'Resume']);
    $child = Issue::factory()->for($root, 'parent')->for($root->repository, 'repository')->create(['title' => 'Photos']);
    ProjectItem::factory()->for($project, 'project')->for($root, 'issue')->create(['group_option_id' => $group->id]);
    ProjectItem::factory()->for($project, 'project')->for($child, 'issue')->create(['group_option_id' => $group->id]);

    $rows = app(BuildIssueTree::class)->handle(area: $project->id)['rows'];
    $groupRoot = collect($rows)->firstWhere('id', 'group-'.$group->id);
    expect($groupRoot['virtual'])->toBeTrue()
        ->and($groupRoot['title'])->toBe('Career')
        ->and(collect($rows)->firstWhere('id', $root->id)['ancestors'])->toBe(['group-'.$group->id])
        ->and(collect($rows)->firstWhere('id', $child->id)['ancestors'])->toBe(['group-'.$group->id, $root->id]);

    $filteredRows = app(BuildIssueTree::class)->handle(area: $project->id, group: $group->id)['rows'];
    expect(array_column($filteredRows, 'id'))->not->toContain('group-'.$group->id)
        ->and(collect($filteredRows)->firstWhere('id', $root->id)['ancestors'])->toBe([]);
});

it('requires every selected label while leaving all issues visible without selected labels', function (): void {
    $first = Label::factory()->create(['name' => 'next']);
    $second = Label::factory()->for($first->repository, 'repository')->create(['name' => 'waiting']);
    $matching = Issue::factory()->for($first->repository, 'repository')->create(['title' => 'Both']);
    $matching->labels()->attach([$first->id, $second->id]);
    $one = Issue::factory()->for($first->repository, 'repository')->create(['title' => 'Only next']);
    $one->labels()->attach($first);

    expect(array_column(app(BuildIssueTree::class)->handle(labels: ['next', 'waiting'])['rows'], 'id'))->toBe([$matching->id])
        ->and(array_column(app(BuildIssueTree::class)->handle()['rows'], 'id'))->toContain($matching->id, $one->id);
});

it('offers every available repository label and the virtual parent filter even before a task uses them', function (): void {
    $unassigned = Label::factory()->create(['name' => 'someday']);
    $assigned = Label::factory()->for($unassigned->repository, 'repository')->create(['name' => 'next']);
    $issue = Issue::factory()->for($unassigned->repository, 'repository')->create();
    $issue->labels()->attach($assigned);

    expect(app(BuildIssueTree::class)->handle()['labelOptions'])->toBe(['next' => 'next', 'parent' => 'parent', 'someday' => 'someday']);
});

it('uses Group-only virtual roots in the All Projects view', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $issue = Issue::factory()->create(['title' => 'Resume']);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group->id]);

    $rows = app(BuildIssueTree::class)->handle()['rows'];

    expect(collect($rows)->firstWhere('id', 'group-'.$group->id)['title'])->toBe('Career')
        ->and(collect($rows)->firstWhere('id', 'group-'.$group->id)['projectTitle'])->toBe('Personal Projects')
        ->and(collect($rows)->firstWhere('id', 'group-'.$group->id)['projectColor'])->toBe('#'.$project->color)
        ->and(collect($rows)->firstWhere('id', $issue->id)['projectColor'])->toBe('#'.$project->color)
        ->and(collect($rows)->firstWhere('id', $issue->id)['ancestors'])->toBe(['group-'.$group->id]);
});

it('derives container state and the parent filter from native child links', function (): void {
    $parent = Issue::factory()->create(['title' => 'Project']);
    $child = Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create(['title' => 'Task']);

    $all = app(BuildIssueTree::class)->handle();
    $filtered = app(BuildIssueTree::class)->handle(labels: ['parent']);

    expect(collect($all['rows'])->firstWhere('id', $parent->id)['container'])->toBeTrue()
        ->and(array_column($filtered['rows'], 'id'))->toBe([$parent->id])
        ->and(array_column($filtered['rows'], 'id'))->not->toContain($child->id);
});

it('filters and ranks sibling tasks by their Project Priority without flattening the tree', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $one = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => '1', 'position' => 0]);
    $five = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => '5', 'position' => 4]);
    $later = Issue::factory()->create(['title' => 'Later task']);
    $first = Issue::factory()->create(['title' => 'First task']);
    ProjectItem::factory()->for($project, 'project')->for($later, 'issue')->create(['priority_option_id' => $five->id]);
    ProjectItem::factory()->for($project, 'project')->for($first, 'issue')->create(['priority_option_id' => $one->id]);

    $ranked = app(BuildIssueTree::class)->handle(area: $project->id, rankPriority: true);
    $filtered = app(BuildIssueTree::class)->handle(area: $project->id, priority: 1);

    expect(array_column($ranked['rows'], 'title'))->toBe(['First task', 'Later task'])
        ->and(array_column($filtered['rows'], 'title'))->toBe(['First task']);
});
