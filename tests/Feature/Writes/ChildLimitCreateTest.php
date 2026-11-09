<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Actions\CreateTodoIssues;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Support\WriteWarnings;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: creating a child checks the parent's child count (open + closed, available, non-temporary)
// when the instance is mirrored; the 80th warns, the 100th is allowed with a warning, the 101st is refused.
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Crowded parent']);
});

it('creates silently below the warning threshold', function (): void {
    insertChildren($this->parent, 78);

    app(CreateTodoIssue::class)->handle(title: '79th', parentId: $this->parent->id);

    expect(app(WriteWarnings::class)->all())->toBe([])
        ->and(childCount($this->parent))->toBe(79);
});

it('warns on the 80th child but creates it', function (): void {
    insertChildren($this->parent, 79);

    $issue = app(CreateTodoIssue::class)->handle(title: '80th', parentId: $this->parent->id);

    $warnings = app(WriteWarnings::class)->all();
    expect($issue)->toBeInstanceOf(Issue::class)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('Crowded parent')->toContain('80')
        ->and(childCount($this->parent))->toBe(80);
});

it('allows the 100th child with a warning', function (): void {
    insertChildren($this->parent, 99);

    app(CreateTodoIssue::class)->handle(title: '100th', parentId: $this->parent->id);

    expect(app(WriteWarnings::class)->all())->toHaveCount(1)
        ->and(childCount($this->parent))->toBe(100);
});

it('refuses the 101st child and leaves the database unchanged', function (): void {
    insertChildren($this->parent, 100);
    $issues = Issue::query()->count();
    $queue = GitHubPushQueueItem::query()->count();

    expect(fn () => app(CreateTodoIssue::class)->handle(title: '101st', parentId: $this->parent->id))
        ->toThrow(TodoValidationException::class, 'Crowded parent');

    expect(Issue::query()->count())->toBe($issues)
        ->and(GitHubPushQueueItem::query()->count())->toBe($queue)
        ->and(Issue::query()->where('title', '101st')->exists())->toBeFalse()
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('counts closed children toward the limit', function (): void {
    insertChildren($this->parent, 100, ['state' => 'CLOSED']);

    expect(fn () => app(CreateTodoIssue::class)->handle(title: '101st', parentId: $this->parent->id))
        ->toThrow(TodoValidationException::class);
});

it('ignores temporary children when counting', function (): void {
    insertChildren($this->parent, 100, ['is_temporary' => true]);

    app(CreateTodoIssue::class)->handle(title: 'Real child', parentId: $this->parent->id);

    expect(app(WriteWarnings::class)->all())->toBe([]);
});

it('exempts a temporary child created under a full parent', function (): void {
    insertChildren($this->parent, 100);

    $issue = app(CreateTodoIssue::class)->handle(title: 'Scratch', parentId: $this->parent->id, temporary: true);

    expect($issue->is_temporary)->toBeTrue()
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('exempts children of a temporary parent', function (): void {
    $temporaryParent = Issue::factory()->temporary()->create(['repository_id' => $this->repository->id]);
    insertChildren($temporaryParent, 100, ['is_temporary' => true]);

    app(CreateTodoIssue::class)->handle(title: 'Scratch child', parentId: $temporaryParent->id, temporary: true);

    expect(app(WriteWarnings::class)->all())->toBe([]);
});

it('does no check and no warning when the instance is not mirrored', function (): void {
    $parent = localOnlyParent();
    insertChildren($parent, 150);

    app(CreateTodoIssue::class)->handle(title: 'Local child', parentId: $parent->id);

    expect(app(WriteWarnings::class)->all())->toBe([])
        ->and(childCount($parent))->toBe(151);
});

it('does not check a top-level issue', function (): void {
    app(CreateTodoIssue::class)->handle(title: 'Top level');

    expect(app(WriteWarnings::class)->all())->toBe([]);
});

// Intent: the batch routes every item through CreateTodoIssue; the collector holds one warning per parent,
// and a refused item fails alone (best-effort batch) without writing anything for it.
it('collects one warning per parent for a batch, not one per item', function (): void {
    insertChildren($this->parent, 78);

    $result = app(CreateTodoIssues::class)->handle([
        ['title' => 'B1', 'parentId' => $this->parent->id],
        ['title' => 'B2', 'parentId' => $this->parent->id],
        ['title' => 'B3', 'parentId' => $this->parent->id],
        ['title' => 'B4', 'parentId' => $this->parent->id],
    ]);

    expect($result['succeeded'])->toBe(4)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1)
        ->and(app(WriteWarnings::class)->all()[0])->toContain('Crowded parent');
});

it('collects a separate warning for each distinct crowded parent in a batch', function (): void {
    $other = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Other crowded']);
    insertChildren($this->parent, 79);
    insertChildren($other, 79);

    app(CreateTodoIssues::class)->handle([
        ['title' => 'X1', 'parentId' => $this->parent->id],
        ['title' => 'X2', 'parentId' => $other->id],
    ]);

    expect(app(WriteWarnings::class)->all())->toHaveCount(2);
});

it('refuses only the overflowing batch items and creates the rest', function (): void {
    insertChildren($this->parent, 99);

    $result = app(CreateTodoIssues::class)->handle([
        ['title' => 'Fits', 'parentId' => $this->parent->id],
        ['title' => 'Overflows', 'parentId' => $this->parent->id],
        ['title' => 'Unrelated'],
    ]);

    expect($result['succeeded'])->toBe(2)->and($result['failed'])->toBe(1)
        ->and($result['items'][1]['error']['code'])->toBe('validation_error')
        ->and($result['items'][1]['error']['message'])->toContain('Crowded parent')
        ->and(childCount($this->parent))->toBe(100)
        ->and(Issue::query()->where('title', 'Overflows')->exists())->toBeFalse()
        ->and(Issue::query()->where('title', 'Unrelated')->exists())->toBeTrue();
});

it('keeps the batch return shape unchanged', function (): void {
    insertChildren($this->parent, 79);

    $result = app(CreateTodoIssues::class)->handle([['title' => 'Only', 'parentId' => $this->parent->id]]);

    expect(array_keys($result))->toBe(['items', 'succeeded', 'failed']);
});
