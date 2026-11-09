<?php

declare(strict_types=1);

use App\Actions\BulkSetIssuesParent;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Support\WriteWarnings;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: a bulk parent change is all-or-nothing against the 100-child limit. Only issues that would
// actually become new children count (already-children and the parent itself do not). A resulting
// count of 80 or more gives one warning per parent, not one per moved issue. Clearing a parent is
// never checked, and nothing is enforced when the instance is not mirrored.
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Crowded parent']);
    $this->movers = Issue::factory()->count(3)->create(['repository_id' => $this->repository->id]);
    $this->ids = $this->movers->pluck('id')->all();
});

it('moves silently when the result stays below the warning threshold', function (): void {
    insertChildren($this->parent, 10);

    $updated = app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id);

    expect($updated)->toBe(3)
        ->and(childCount($this->parent))->toBe(13)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('emits exactly one warning for the parent when the result reaches 80', function (): void {
    insertChildren($this->parent, 77);

    app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id);

    expect(childCount($this->parent))->toBe(80)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1)
        ->and(app(WriteWarnings::class)->all()[0])->toContain('Crowded parent');
});

it('does not warn when the result is 79', function (): void {
    insertChildren($this->parent, 76);

    app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id);

    expect(childCount($this->parent))->toBe(79)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('allows a move that reaches exactly 100 children and warns once', function (): void {
    insertChildren($this->parent, 97);

    app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id);

    expect(childCount($this->parent))->toBe(100)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1);
});

it('refuses the whole move when the result would be 101 and changes nothing', function (): void {
    insertChildren($this->parent, 98);
    $queue = GitHubPushQueueItem::query()->count();

    expect(fn () => app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id))
        ->toThrow(TodoValidationException::class, 'Crowded parent');

    expect(childCount($this->parent))->toBe(98)
        ->and(Issue::query()->whereIn('id', $this->ids)->whereNotNull('parent_issue_id')->count())->toBe(0)
        ->and(GitHubPushQueueItem::query()->count())->toBe($queue)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('refuses a batch into an already full parent', function (): void {
    insertChildren($this->parent, 100);

    expect(fn () => app(BulkSetIssuesParent::class)->handle([$this->ids[0]], $this->parent->id))
        ->toThrow(TodoValidationException::class, 'Crowded parent');

    expect($this->movers[0]->fresh()->parent_issue_id)->toBeNull();
});

it('does not count selected issues that are already children of the parent', function (): void {
    insertChildren($this->parent, 98);
    $this->movers[0]->update(['parent_issue_id' => $this->parent->id]);
    $this->movers[1]->update(['parent_issue_id' => $this->parent->id]);
    // 100 children now; only the third is new, which would make 101.
    expect(fn () => app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id))
        ->toThrow(TodoValidationException::class);

    // With one slot free the same selection (two already children, one new) fits.
    Issue::query()->where('parent_issue_id', $this->parent->id)->whereNotIn('id', $this->ids)->limit(1)->delete();

    $updated = app(BulkSetIssuesParent::class)->handle($this->ids, $this->parent->id);

    expect($updated)->toBe(1)
        ->and(childCount($this->parent))->toBe(100);
});

it('does not count the parent itself when it is among the selected issues', function (): void {
    insertChildren($this->parent, 98);

    $updated = app(BulkSetIssuesParent::class)->handle([$this->parent->id, $this->ids[0], $this->ids[1]], $this->parent->id);

    expect($updated)->toBe(2)
        ->and(childCount($this->parent))->toBe(100)
        ->and($this->parent->fresh()->parent_issue_id)->toBeNull();
});

it('never checks clearing the parent', function (): void {
    insertChildren($this->parent, 150);
    Issue::query()->whereIn('id', $this->ids)->update(['parent_issue_id' => $this->parent->id]);

    $updated = app(BulkSetIssuesParent::class)->handle($this->ids, null);

    expect($updated)->toBe(3)
        ->and(Issue::query()->whereIn('id', $this->ids)->whereNotNull('parent_issue_id')->count())->toBe(0)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('does not check when the instance is not mirrored', function (): void {
    $parent = localOnlyParent();
    $movers = Issue::factory()->count(3)->create(['repository_id' => $parent->repository_id]);
    insertChildren($parent, 150);

    $updated = app(BulkSetIssuesParent::class)->handle($movers->pluck('id')->all(), $parent->id);

    expect($updated)->toBe(3)
        ->and(childCount($parent))->toBe(153)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('exempts moves under a temporary parent', function (): void {
    $temporaryParent = Issue::factory()->temporary()->create(['repository_id' => $this->repository->id]);
    insertChildren($temporaryParent, 120, ['is_temporary' => true]);
    $temporaryChildren = Issue::factory()->temporary()->count(2)->create(['repository_id' => $this->repository->id]);

    $updated = app(BulkSetIssuesParent::class)->handle($temporaryChildren->pluck('id')->all(), $temporaryParent->id);

    expect($updated)->toBe(2)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});
