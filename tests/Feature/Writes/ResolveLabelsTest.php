<?php

declare(strict_types=1);

use App\Actions\ResolveLabels;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Label;

it('returns the selected labels unchanged when there are no new names', function (): void {
    $repository = GitHubRepository::factory()->create();
    $selected = Label::factory()->for($repository, 'repository')->count(2)->create();

    $resolved = app(ResolveLabels::class)->handle($repository, $selected, []);

    expect($resolved->pluck('id')->all())->toBe($selected->pluck('id')->all());
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('reuses an existing label case-insensitively within the same repository, without duplicating it', function (): void {
    $repository = GitHubRepository::factory()->create();
    $existing = Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);

    $resolved = app(ResolveLabels::class)->handle($repository, collect([$existing]), ['URGENT', ' Urgent ']);

    expect($resolved->pluck('id')->all())->toBe([$existing->id]);
    expect(Label::query()->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('creates a missing label lowercased and enqueues its creation', function (): void {
    $repository = GitHubRepository::factory()->create();

    $resolved = app(ResolveLabels::class)->handle($repository, collect(), ['  Blocked ']);

    $label = Label::query()->sole();
    expect($label)->name->toBe('blocked')->color->toBe('6B7280')->github_node_id->toBeNull()->is_available->toBeTrue();
    expect($resolved->pluck('id')->all())->toBe([$label->id]);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'create_label')->where('target_id', $label->id)->sole();
    expect($enqueued->payload)->toBe(['name' => 'blocked', 'color' => '6B7280', 'description' => null]);
});

it('skips blank names and collapses duplicates created within one call', function (): void {
    $repository = GitHubRepository::factory()->create();

    $resolved = app(ResolveLabels::class)->handle($repository, collect(), ['', '   ', 'New', 'new']);

    expect(Label::query()->count())->toBe(1)->and($resolved)->toHaveCount(1);
    expect(GitHubPushQueueItem::query()->where('operation', 'create_label')->count())->toBe(1);
});

it('does not reuse a same-named label from a different repository', function (): void {
    $repository = GitHubRepository::factory()->create();
    Label::factory()->for(GitHubRepository::factory()->create(), 'repository')->create(['name' => 'urgent']);

    app(ResolveLabels::class)->handle($repository, collect(), ['urgent']);

    expect(Label::query()->where('repository_id', $repository->id)->where('name', 'urgent')->count())->toBe(1);
    expect(Label::query()->count())->toBe(2);
});

it('merges selected and new labels without duplicating one that is both', function (): void {
    $repository = GitHubRepository::factory()->create();
    $kept = Label::factory()->for($repository, 'repository')->create(['name' => 'keep']);

    $resolved = app(ResolveLabels::class)->handle($repository, collect([$kept]), ['keep', 'extra']);

    expect($resolved->pluck('name')->sort()->values()->all())->toBe(['extra', 'keep']);
});

it('matches an existing label whatever the case or spacing of the requested name', function (): void {
    $repository = GitHubRepository::factory()->create();
    $existing = Label::factory()->for($repository, 'repository')->create(['name' => 'agent task']);

    $resolved = app(ResolveLabels::class)->handle($repository, collect(), ['  Agent    TASK ']);

    expect($resolved->pluck('id')->all())->toBe([$existing->id])
        ->and(Label::query()->count())->toBe(1)
        ->and(GitHubPushQueueItem::query()->count())->toBe(0);
});
