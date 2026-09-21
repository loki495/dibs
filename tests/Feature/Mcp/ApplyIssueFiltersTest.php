<?php

declare(strict_types=1);

use App\Actions\ApplyIssueFilters;
use App\Actions\ListTodoIssues;
use App\Actions\SearchTodoIssues;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Support\IssueFilters;

/** @param  list<string>  $labels */
function issueWith(array $labels = [], ?GitHubProject $project = null, ?ProjectFieldOption $group = null, array $attributes = []): Issue
{
    $issue = Issue::factory()->create($attributes);
    foreach ($labels as $name) {
        $issue->labels()->attach(Label::query()->firstWhere('name', $name) ?? Label::factory()->create(['name' => $name]));
    }
    if ($project instanceof GitHubProject) {
        ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group?->id]);
    }

    return $issue;
}

function groupOf(GitHubProject $project, string $name): ProjectFieldOption
{
    $field = ProjectField::query()->where('project_id', $project->id)->where('semantic_key', 'group')->first()
        ?? ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

    return ProjectFieldOption::factory()->for($field, 'field')->create(['name' => $name]);
}

/** @return array{ids: list<int>, unresolved: array<string, list<string>>} */
function filtered(IssueFilters $filters): array
{
    $query = Issue::query()->where('is_available', true)->orderBy('id');
    $unresolved = app(ApplyIssueFilters::class)->handle($query, $filters);

    return ['ids' => $query->pluck('id')->all(), 'unresolved' => $unresolved];
}

it('requires every label in `labels`, at least one of `anyLabels`, and none of `excludeLabels`', function (): void {
    $both = issueWith(['bug', 'ui']);
    $bugOnly = issueWith(['bug']);
    $uiOnly = issueWith(['ui']);
    $neither = issueWith(['docs']);
    $blocked = issueWith(['bug', 'ui', 'wontfix']);

    expect(filtered(new IssueFilters(labels: ['bug', 'ui']))['ids'])->toBe([$both->id, $blocked->id])
        ->and(filtered(new IssueFilters(anyLabels: ['bug', 'ui']))['ids'])->toBe([$both->id, $bugOnly->id, $uiOnly->id, $blocked->id])
        ->and(filtered(new IssueFilters(excludeLabels: ['wontfix']))['ids'])->toBe([$both->id, $bugOnly->id, $uiOnly->id, $neither->id])
        ->and(filtered(new IssueFilters(labels: ['bug'], anyLabels: ['ui', 'docs'], excludeLabels: ['wontfix']))['ids'])->toBe([$both->id]);
});

it('matches label names case-insensitively and with normalized spacing, including labels stored with capitals', function (): void {
    $stored = issueWith(['agent task']);
    $legacy = issueWith(['Resume']);

    expect(filtered(new IssueFilters(labels: ['  Agent   TASK ']))['ids'])->toBe([$stored->id])
        ->and(filtered(new IssueFilters(labels: ['resume']))['ids'])->toBe([$legacy->id])
        ->and(filtered(new IssueFilters(labels: ['Resume']))['ids'])->toBe([$legacy->id]);
});

it('ignores a label that no longer exists on GitHub', function (): void {
    $issue = Issue::factory()->create();
    $issue->labels()->attach(Label::factory()->create(['name' => 'gone', 'is_available' => false]));

    $result = filtered(new IssueFilters(labels: ['gone']));

    expect($result['ids'])->toBe([])
        ->and($result['unresolved']['labels'])->toBe(['gone']);
});

it('matches several areas by id or by case-insensitive name, as alternatives', function (): void {
    $work = GitHubProject::factory()->create(['title' => 'Work']);
    $personal = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $random = GitHubProject::factory()->create(['title' => 'Random Tasks']);
    $inWork = issueWith([], $work);
    $inPersonal = issueWith([], $personal);
    issueWith([], $random);

    expect(filtered(new IssueFilters(areas: [$work->id, $personal->id]))['ids'])->toBe([$inWork->id, $inPersonal->id])
        ->and(filtered(new IssueFilters(areaNames: ['personal PROJECTS']))['ids'])->toBe([$inPersonal->id])
        ->and(filtered(new IssueFilters(areas: [$work->id], areaNames: ['Personal Projects']))['ids'])->toBe([$inWork->id, $inPersonal->id]);
});

it('matches groups by id, or by name across every area that has a group of that name', function (): void {
    $work = GitHubProject::factory()->create();
    $personal = GitHubProject::factory()->create();
    $careerWork = groupOf($work, 'Career');
    $careerPersonal = groupOf($personal, 'career');
    $homelab = groupOf($personal, 'Homelab');
    $a = issueWith([], $work, $careerWork);
    $b = issueWith([], $personal, $careerPersonal);
    $c = issueWith([], $personal, $homelab);

    expect(filtered(new IssueFilters(groups: [$homelab->id]))['ids'])->toBe([$c->id])
        ->and(filtered(new IssueFilters(groupNames: ['CAREER']))['ids'])->toBe([$a->id, $b->id])
        ->and(filtered(new IssueFilters(groupNames: ['Career'], areas: [$personal->id]))['ids'])->toBe([$b->id]);
});

it('requires area and group to hold on the same Project membership', function (): void {
    $projectA = GitHubProject::factory()->create();
    $projectB = GitHubProject::factory()->create();
    $groupA = groupOf($projectA, 'Career');
    $issue = issueWith([], $projectA, $groupA);
    ProjectItem::factory()->for($projectB, 'project')->for($issue, 'issue')->create();

    expect(filtered(new IssueFilters(areas: [$projectA->id], groups: [$groupA->id]))['ids'])->toBe([$issue->id])
        ->and(filtered(new IssueFilters(areas: [$projectB->id], groups: [$groupA->id]))['ids'])->toBe([]);
});

it('ignores memberships in a Project that is no longer available', function (): void {
    $project = GitHubProject::factory()->create(['is_available' => false]);
    issueWith([], $project);

    expect(filtered(new IssueFilters(areas: [$project->id]))['ids'])->toBe([]);
});

it('narrows to the direct children of a parent, or the whole tree beneath it with descendants', function (): void {
    $root = Issue::factory()->create();
    $child = Issue::factory()->create(['parent_issue_id' => $root->id]);
    $grandchild = Issue::factory()->create(['parent_issue_id' => $child->id]);
    $greatGrandchild = Issue::factory()->create(['parent_issue_id' => $grandchild->id]);
    Issue::factory()->create();

    expect(filtered(new IssueFilters(parentId: $root->id))['ids'])->toBe([$child->id])
        ->and(filtered(new IssueFilters(parentId: $root->id, descendants: true))['ids'])->toBe([$child->id, $grandchild->id, $greatGrandchild->id])
        ->and(filtered(new IssueFilters(parentId: $child->id, descendants: true))['ids'])->toBe([$grandchild->id, $greatGrandchild->id])
        ->and(filtered(new IssueFilters(parentId: $greatGrandchild->id, descendants: true))['ids'])->toBe([]);
});

it('terminates on a corrupt parent cycle instead of looping', function (): void {
    $a = Issue::factory()->create();
    $b = Issue::factory()->create(['parent_issue_id' => $a->id]);
    $a->forceFill(['parent_issue_id' => $b->id])->save();

    expect(filtered(new IssueFilters(parentId: $a->id, descendants: true))['ids'])->toEqualCanonicalizing([$a->id, $b->id]);
});

it('combines every kind of filter with one another', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $group = groupOf($project, 'Dibs');
    $root = Issue::factory()->create();
    $match = issueWith(['bug', 'agent task'], $project, $group, ['parent_issue_id' => $root->id]);
    issueWith(['bug'], $project, $group, ['parent_issue_id' => $root->id]);
    issueWith(['bug', 'agent task'], $project, $group);
    issueWith(['bug', 'agent task', 'wontfix'], $project, $group, ['parent_issue_id' => $root->id]);

    $result = filtered(new IssueFilters(
        areaNames: ['personal projects'], groupNames: ['dibs'], labels: ['bug', 'agent task'], excludeLabels: ['wontfix'],
        parentId: $root->id, descendants: true,
    ));

    expect($result['ids'])->toBe([$match->id]);
});

it('reports names it could not resolve instead of silently returning an empty page', function (): void {
    issueWith(['bug']);

    $result = filtered(new IssueFilters(areaNames: ['Nope'], groupNames: ['Nada'], labels: ['bug', 'agnt task'], anyLabels: ['bug', 'typo one'], excludeLabels: ['typo two']));

    expect($result['unresolved'])->toBe(['areas' => ['Nope'], 'groups' => ['Nada'], 'labels' => ['agnt task', 'typo one', 'typo two']])
        ->and($result['ids'])->toBe([]);
});

it('treats an unknown name in `anyLabels` or `excludeLabels` as having no effect, and returns nothing when none of `anyLabels` exist', function (): void {
    $issue = issueWith(['bug']);

    expect(filtered(new IssueFilters(anyLabels: ['bug', 'typo']))['ids'])->toBe([$issue->id])
        ->and(filtered(new IssueFilters(excludeLabels: ['typo']))['ids'])->toBe([$issue->id])
        ->and(filtered(new IssueFilters(anyLabels: ['typo']))['ids'])->toBe([]);
});

it('reports nothing unresolved when every name resolves', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Work']);
    issueWith(['bug'], $project);

    expect(filtered(new IssueFilters(areaNames: ['work'], labels: ['bug']))['unresolved'])->toBe(['areas' => [], 'groups' => [], 'labels' => []]);
});

it('merges the legacy single-value arguments into the lists', function (): void {
    $filters = (new IssueFilters(areas: [1], labels: ['bug']))->withLegacy(area: 2, group: 3, label: 'ui', parentId: 9);

    expect($filters->areas)->toBe([1, 2])->and($filters->groups)->toBe([3])->and($filters->labels)->toBe(['bug', 'ui'])->and($filters->parentId)->toBe(9);
    expect((new IssueFilters)->withLegacy(null, null, null, null))->toEqual(new IssueFilters);
});

it('lets todo_list combine the shared filters with its own search text, and surfaces unresolved names', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Work']);
    $match = issueWith(['bug'], $project, null, ['title' => 'Fix the sink']);
    issueWith(['bug'], $project, null, ['title' => 'Water the plants']);
    issueWith(['docs'], $project, null, ['title' => 'Fix the docs']);

    $result = app(ListTodoIssues::class)->handle(search: 'fix', filters: new IssueFilters(areaNames: ['work'], labels: ['bug']));
    $lenient = app(ListTodoIssues::class)->handle(filters: new IssueFilters(labels: ['agnt task']));

    expect(collect($result['items'])->pluck('id')->all())->toBe([$match->id])
        ->and($result)->not->toHaveKey('unresolved')
        ->and($lenient['items'])->toBe([])
        ->and($lenient['unresolved'])->toBe(['labels' => ['agnt task']]);
});

it('lets todo_search combine the shared filters with its keyword query, and surfaces unresolved names', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Work']);
    $match = issueWith(['bug'], $project, null, ['title' => 'Fix the sink', 'body' => 'It drips']);
    issueWith(['docs'], $project, null, ['title' => 'Fix the docs', 'body' => 'It drips']);

    $result = app(SearchTodoIssues::class)->handle('drips', filters: new IssueFilters(areaNames: ['work'], labels: ['bug']));
    $lenient = app(SearchTodoIssues::class)->handle('drips', filters: new IssueFilters(areaNames: ['Nope']));

    expect(collect($result['items'])->pluck('id')->all())->toBe([$match->id])
        ->and($lenient['items'])->toBe([])
        ->and($lenient['unresolved'])->toBe(['areas' => ['Nope']]);
});

it('knows whether any filter is set; `descendants` alone is not a filter', function (): void {
    expect((new IssueFilters)->isEmpty())->toBeTrue()
        ->and((new IssueFilters(descendants: true))->isEmpty())->toBeTrue()
        ->and((new IssueFilters(labels: ['bug']))->isEmpty())->toBeFalse()
        ->and((new IssueFilters(parentId: 3))->isEmpty())->toBeFalse()
        ->and((new IssueFilters(areaNames: ['Work']))->isEmpty())->toBeFalse();
});
