<?php

declare(strict_types=1);

use App\Actions\ResolveGroupOption;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;

it('reuses an existing Group case-insensitively without creating or enqueueing anything', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $existing = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    $resolved = app(ResolveGroupOption::class)->handle($project, '  CAREER ');

    expect($resolved->is($existing))->toBeTrue();
    expect(ProjectFieldOption::query()->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('creates a trimmed local Group after the highest position and enqueues its creation', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Existing', 'position' => 4]);

    $created = app(ResolveGroupOption::class)->handle($project, '  Fresh Group ');

    expect($created)->name->toBe('Fresh Group')->github_option_id->toBeNull()->color->toBe('GRAY')->position->toBe(5)
        ->and($created->project_field_id)->toBe($field->id);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'create_group_option')->where('target_id', $created->id)->sole();
    expect($enqueued->payload)->toBe(['name' => 'Fresh Group', 'color' => 'GRAY']);
});

it('is safe to repeat: the second call finds the Group the first one created', function (): void {
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);

    $first = app(ResolveGroupOption::class)->handle($project, 'Fresh');
    $second = app(ResolveGroupOption::class)->handle($project, 'fresh');

    expect($second->is($first))->toBeTrue();
    expect(ProjectFieldOption::query()->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->where('operation', 'create_group_option')->count())->toBe(1);
});

it('does not treat a same-named Group in another project as a match', function (): void {
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $otherField = ProjectField::factory()->for(GitHubProject::factory()->create(), 'project')->create(['semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($otherField, 'field')->create(['name' => 'Career']);

    $created = app(ResolveGroupOption::class)->handle($project, 'Career');

    expect(ProjectFieldOption::query()->count())->toBe(2)->and($created->field->project_id)->toBe($project->id);
});

it('fails with a validation error instead of crashing when the project has no Group field', function (): void {
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'is_available' => false]);

    expect(fn () => app(ResolveGroupOption::class)->handle($project, 'Anything'))
        ->toThrow(TodoValidationException::class, 'This area has no Group field. Refresh and try again.');

    expect(ProjectFieldOption::query()->count())->toBe(0);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
