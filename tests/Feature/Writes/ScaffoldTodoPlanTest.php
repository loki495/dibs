<?php

declare(strict_types=1);

use App\Actions\ScaffoldTodoPlan;
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

it('creates a plan issue with its children in one call', function (): void {
    $plan = app(ScaffoldTodoPlan::class)->handle(
        title: 'Plan: ship the feature',
        body: 'Objective and acceptance criteria.',
        children: [
            ['title' => 'Design the schema'],
            ['title' => 'Write the migration'],
        ],
    );

    expect($plan->title)->toBe('Plan: ship the feature')
        ->and($plan->children)->toHaveCount(2)
        ->and($plan->children->pluck('title')->all())->toBe(['Design the schema', 'Write the migration'])
        ->and($plan->children->every(fn (Issue $child): bool => $child->parent_issue_id === $plan->id))->toBeTrue()
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->count())->toBe(3);
});

it('applies the plan label and area to the plan issue', function (): void {
    $project = GitHubProject::factory()->create();
    $planLabel = Label::factory()->create(['name' => 'plan']);

    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Plan', area: $project->id, labelIds: [$planLabel->id]);

    expect($plan->labels->pluck('name')->all())->toBe(['plan'])
        ->and($plan->projectItems->sole()->project_id)->toBe($project->id);
});

it('scopes each child to the same area as the plan', function (): void {
    $project = GitHubProject::factory()->create();

    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Plan', area: $project->id, children: [['title' => 'Child task']]);

    expect($plan->children->sole()->projectItems->sole()->project_id)->toBe($project->id);
});

it('creates nothing at all if a child fails validation', function (): void {
    $project = GitHubProject::factory()->create();

    expect(fn () => app(ScaffoldTodoPlan::class)->handle(
        title: 'Plan',
        area: $project->id,
        children: [
            ['title' => 'Valid child'],
            ['title' => 'Bad child', 'groupId' => 999_999],
        ],
    ))->toThrow(TodoValidationException::class, 'Group is no longer available');

    expect(Issue::query()->count())->toBe(0)
        ->and(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects a child with a blank title before creating anything', function (): void {
    expect(fn () => app(ScaffoldTodoPlan::class)->handle(title: 'Plan', children: [['title' => '   ']]))
        ->toThrow(TodoValidationException::class, 'needs a title');

    expect(Issue::query()->count())->toBe(0);
});

it('is idempotent: retrying the same key returns the original plan without duplicating it or its children', function (): void {
    $first = app(ScaffoldTodoPlan::class)->handle(title: 'Plan', children: [['title' => 'Child']], idempotencyKey: 'scaffold-1');
    $second = app(ScaffoldTodoPlan::class)->handle(title: 'Plan', children: [['title' => 'Child']], idempotencyKey: 'scaffold-1');

    expect($second->id)->toBe($first->id)
        ->and(Issue::query()->count())->toBe(2);
});

it('sets the plan issue\'s own Group and Priority, independently of each child\'s own Group', function (): void {
    $project = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $priorityField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $planGroup = ProjectFieldOption::factory()->for($groupField, 'field')->create();
    $childGroup = ProjectFieldOption::factory()->for($groupField, 'field')->create();
    $priority = ProjectFieldOption::factory()->for($priorityField, 'field')->create();

    $plan = app(ScaffoldTodoPlan::class)->handle(
        title: 'Plan',
        area: $project->id,
        groupId: $planGroup->id,
        priorityId: $priority->id,
        children: [['title' => 'Child task', 'groupId' => $childGroup->id]],
    );

    expect($plan->projectItems->sole()->group_option_id)->toBe($planGroup->id)
        ->and($plan->projectItems->sole()->priority_option_id)->toBe($priority->id)
        ->and($plan->children->sole()->projectItems->sole()->group_option_id)->toBe($childGroup->id);
});

it('rejects a plan-level groupId that belongs to a different area', function (): void {
    $project = GitHubProject::factory()->create();
    $otherProject = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($otherProject, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($groupField, 'field')->create();

    expect(fn () => app(ScaffoldTodoPlan::class)->handle(title: 'Plan', area: $project->id, groupId: $group->id))
        ->toThrow(TodoValidationException::class, 'Group is no longer available');

    expect(Issue::query()->count())->toBe(0);
});
