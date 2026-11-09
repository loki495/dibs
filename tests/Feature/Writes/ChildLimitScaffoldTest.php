<?php

declare(strict_types=1);

use App\Actions\ScaffoldTodoPlan;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Support\WriteWarnings;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: a scaffold creates a brand-new plan, so only its own batch of children counts. A batch of
// 80 to 100 warns once; more than 100 refuses the whole scaffold and creates nothing.
beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

function scaffoldChildren(int $count): array
{
    return array_map(fn (int $i): array => ['title' => 'Step '.$i], range(1, $count));
}

it('scaffolds 79 children without a warning', function (): void {
    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Big plan', children: scaffoldChildren(79));

    expect($plan->children)->toHaveCount(79)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('warns once when the batch reaches 80 children', function (): void {
    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Big plan', children: scaffoldChildren(80));

    $warnings = app(WriteWarnings::class)->all();
    expect($plan->children)->toHaveCount(80)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('Big plan')->toContain('80');
});

it('allows exactly 100 children with one warning', function (): void {
    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Max plan', children: scaffoldChildren(100));

    expect($plan->children)->toHaveCount(100)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1);
});

it('refuses the whole scaffold at 101 children and creates nothing', function (): void {
    expect(fn () => app(ScaffoldTodoPlan::class)->handle(title: 'Too big', children: scaffoldChildren(101)))
        ->toThrow(TodoValidationException::class, 'Too big');

    expect(Issue::query()->count())->toBe(0)
        ->and(GitHubPushQueueItem::query()->count())->toBe(0)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('does not check when the instance is not mirrored', function (): void {
    localOnlyParent();

    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Local plan', children: scaffoldChildren(120));

    expect($plan->children)->toHaveCount(120)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});
