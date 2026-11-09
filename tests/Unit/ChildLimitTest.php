<?php

declare(strict_types=1);

use App\Exceptions\TodoValidationException;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Support\ChildLimit;
use App\Support\WriteWarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/../Support/ChildLimitFixtures.php';

uses(TestCase::class, RefreshDatabase::class);

// Intent: ChildLimit::check is the single place that counts a parent's direct children (open and closed,
// available, non-temporary) plus the ones being added, and decides warn / allow / refuse.
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create();
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id]);
});

it('defaults the thresholds to 80 and 100', function (): void {
    expect(config('dibs.child_limit.warn_at'))->toBe(80)
        ->and(config('dibs.child_limit.refuse_above'))->toBe(100);
});

it('returns null below the warning threshold', function (): void {
    insertChildren($this->parent, 78);

    expect(app(ChildLimit::class)->check($this->parent))->toBeNull();
});

it('warns when the new child is the 80th and names the parent and the count', function (): void {
    insertChildren($this->parent, 79);

    $message = app(ChildLimit::class)->check($this->parent);

    expect($message)->toBeString()->toContain('80')->toContain($this->parent->title);
});

it('allows the 100th child with a warning', function (): void {
    insertChildren($this->parent, 99);

    expect(app(ChildLimit::class)->check($this->parent))->toBeString()->toContain('100');
});

it('refuses the 101st child with a validation exception naming parent and counts', function (): void {
    insertChildren($this->parent, 100);

    expect(fn () => app(ChildLimit::class)->check($this->parent))
        ->toThrow(TodoValidationException::class, $this->parent->title);
    expect(childCount($this->parent))->toBe(100);
});

it('counts several added children against the limits', function (): void {
    insertChildren($this->parent, 70);

    expect(app(ChildLimit::class)->check($this->parent, 10))->toBeString()->toContain('80')
        ->and(fn () => app(ChildLimit::class)->check($this->parent, 31))->toThrow(TodoValidationException::class);
});

it('counts closed children', function (): void {
    insertChildren($this->parent, 79, ['state' => 'CLOSED']);

    expect(app(ChildLimit::class)->check($this->parent))->toBeString();
});

it('does not count unavailable children', function (): void {
    insertChildren($this->parent, 79, ['is_available' => false]);

    expect(app(ChildLimit::class)->check($this->parent))->toBeNull();
});

it('does not count temporary children', function (): void {
    insertChildren($this->parent, 79, ['is_temporary' => true]);

    expect(app(ChildLimit::class)->check($this->parent))->toBeNull();
});

it('does not count grandchildren', function (): void {
    $child = Issue::factory()->create(['repository_id' => $this->repository->id, 'parent_issue_id' => $this->parent->id]);
    insertChildren($child, 90);

    expect(app(ChildLimit::class)->check($this->parent))->toBeNull();
});

it('returns null and never refuses when the instance is not mirrored', function (): void {
    GitHubRepository::query()->update(['is_local' => true]);
    insertChildren($this->parent, 150);

    expect(app(ChildLimit::class)->check($this->parent))->toBeNull();
});

it('honours configured thresholds', function (): void {
    config(['dibs.child_limit.warn_at' => 3, 'dibs.child_limit.refuse_above' => 5]);
    insertChildren($this->parent, 1);

    expect(app(ChildLimit::class)->check($this->parent))->toBeNull()
        ->and(app(ChildLimit::class)->check($this->parent, 2))->toBeString()
        ->and(fn () => app(ChildLimit::class)->check($this->parent, 5))->toThrow(TodoValidationException::class);
});

it('exempts a temporary parent', function (): void {
    $temporaryParent = Issue::factory()->temporary()->create(['repository_id' => $this->repository->id]);
    insertChildren($temporaryParent, 150, ['is_temporary' => true]);

    expect(app(ChildLimit::class)->check($temporaryParent))->toBeNull();
});

it('does not write anything itself', function (): void {
    insertChildren($this->parent, 99);
    $before = Issue::query()->count();

    app(ChildLimit::class)->check($this->parent);

    expect(Issue::query()->count())->toBe($before);
});

// Intent: WriteWarnings is the per-request collector actions push warnings into; flush returns and clears.
it('collects warnings in order and flush returns then clears them', function (): void {
    $warnings = app(WriteWarnings::class);
    $warnings->add('first');
    $warnings->add('second');

    expect($warnings->all())->toBe(['first', 'second'])
        ->and($warnings->flush())->toBe(['first', 'second'])
        ->and($warnings->all())->toBe([])
        ->and($warnings->flush())->toBe([]);
});

it('resolves one shared collector within a container scope', function (): void {
    app(WriteWarnings::class)->add('shared');

    expect(app(WriteWarnings::class)->all())->toBe(['shared']);
});

it('starts empty in a new scope', function (): void {
    app(WriteWarnings::class)->add('leftover');
    app()->forgetScopedInstances();

    expect(app(WriteWarnings::class)->all())->toBe([]);
});
