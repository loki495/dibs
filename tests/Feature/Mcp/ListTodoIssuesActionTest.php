<?php

declare(strict_types=1);

use App\Actions\ListTodoIssues;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

it('lists open tasks, excluding knowledge-labeled issues, in a bounded page', function (): void {
    $task = Issue::factory()->create(['title' => 'Ship the thing']);
    $knowledge = Issue::factory()->create(['title' => 'How the thing works']);
    $knowledge->labels()->attach(Label::factory()->create(['name' => 'research']));
    Issue::factory()->create(['state' => 'CLOSED']);

    $result = app(ListTodoIssues::class)->handle();

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['id'])->toBe($task->id)
        ->and($result['total'])->toBe(1);
});

it('lists only knowledge-labeled issues in the knowledge view', function (): void {
    Issue::factory()->create();
    $lesson = Issue::factory()->create();
    $lesson->labels()->attach(Label::factory()->create(['name' => 'lesson']));

    $result = app(ListTodoIssues::class)->handle(view: 'knowledge');

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['id'])->toBe($lesson->id)
        ->and($result['items'][0]['knowledge'])->toBeTrue();
});

it('filters by area, group, label, and search', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'dotfiles']);
    $match = Issue::factory()->create(['title' => 'Update dotfiles config']);
    ProjectItem::factory()->for($project, 'project')->for($match, 'issue')->create(['group_option_id' => $group->id]);
    $match->labels()->attach(Label::factory()->create(['name' => 'next']));
    $otherProject = Issue::factory()->create(['title' => 'Unrelated']);
    ProjectItem::factory()->for($otherProject, 'issue')->create();

    $byArea = app(ListTodoIssues::class)->handle(area: $project->id);
    $byGroup = app(ListTodoIssues::class)->handle(group: $group->id);
    $byLabel = app(ListTodoIssues::class)->handle(label: 'next');
    $bySearch = app(ListTodoIssues::class)->handle(search: 'dotfiles');

    expect($byArea['items'])->toHaveCount(1)->and($byArea['items'][0]['id'])->toBe($match->id)
        ->and($byGroup['items'])->toHaveCount(1)->and($byGroup['items'][0]['id'])->toBe($match->id)
        ->and($byLabel['items'])->toHaveCount(1)->and($byLabel['items'][0]['id'])->toBe($match->id)
        ->and($bySearch['items'])->toHaveCount(1)->and($bySearch['items'][0]['id'])->toBe($match->id);
});

it('lists direct children of a given parent', function (): void {
    $parent = Issue::factory()->create();
    $child = Issue::factory()->for($parent, 'parent')->create();
    Issue::factory()->create();

    $result = app(ListTodoIssues::class)->handle(parentId: $parent->id);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['id'])->toBe($child->id);
});

it('marks an issue with available children as a container', function (): void {
    $parent = Issue::factory()->create();
    Issue::factory()->for($parent, 'parent')->create();

    $result = app(ListTodoIssues::class)->handle(parentId: null, search: '', view: 'tasks');
    $node = collect($result['items'])->firstWhere('id', $parent->id);

    expect($node['container'])->toBeTrue();
});

it('caps perPage at the maximum and paginates deterministically', function (): void {
    Issue::factory()->count(5)->create();

    $result = app(ListTodoIssues::class)->handle(perPage: 1000);
    expect($result['perPage'])->toBe(ListTodoIssues::MAX_PER_PAGE);

    $page1 = app(ListTodoIssues::class)->handle(perPage: 2, page: 1);
    $page2 = app(ListTodoIssues::class)->handle(perPage: 2, page: 2);

    expect($page1['items'])->toHaveCount(2)
        ->and($page2['items'])->toHaveCount(2)
        ->and($page1['lastPage'])->toBe(3)
        ->and(collect($page1['items'])->pluck('id')->intersect(collect($page2['items'])->pluck('id')))->toBeEmpty();
});

it('excludes unavailable issues entirely', function (): void {
    Issue::factory()->create(['is_available' => false]);

    $result = app(ListTodoIssues::class)->handle(state: 'ALL');

    expect($result['items'])->toHaveCount(0)->and($result['total'])->toBe(0);
});
