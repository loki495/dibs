<?php

declare(strict_types=1);

use App\Actions\MoveIssueUnderParent;
use App\Actions\ReviseTodoIssue;
use App\Actions\UpdateTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Support\WriteWarnings;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: moving an issue under a parent adds one child to it. Re-parenting to the same parent is a
// no-op (no check), clearing a parent is never checked, and the limits are the same as for creation.
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Crowded parent']);
    $this->mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Mover', 'revision' => 1]);
});

it('moves silently below the warning threshold', function (): void {
    insertChildren($this->parent, 10);

    app(MoveIssueUnderParent::class)->handle($this->mover, $this->parent);

    expect($this->mover->fresh()->parent_issue_id)->toBe($this->parent->id)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('warns when the moved issue becomes the 80th child', function (): void {
    insertChildren($this->parent, 79);

    app(MoveIssueUnderParent::class)->handle($this->mover, $this->parent);

    expect($this->mover->fresh()->parent_issue_id)->toBe($this->parent->id)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1)
        ->and(app(WriteWarnings::class)->all()[0])->toContain('Crowded parent');
});

it('refuses a move that would make the 101st child and changes nothing', function (): void {
    insertChildren($this->parent, 100);
    $queue = GitHubPushQueueItem::query()->count();

    expect(fn () => app(MoveIssueUnderParent::class)->handle($this->mover, $this->parent))
        ->toThrow(TodoValidationException::class, 'Crowded parent');

    expect($this->mover->fresh()->parent_issue_id)->toBeNull()
        ->and(GitHubPushQueueItem::query()->count())->toBe($queue);
});

it('treats re-parenting to the same parent as a no-op without a check', function (): void {
    insertChildren($this->parent, 99);
    $this->mover->update(['parent_issue_id' => $this->parent->id]);

    app(MoveIssueUnderParent::class)->handle($this->mover->fresh(), $this->parent);

    expect(app(WriteWarnings::class)->all())->toBe([]);
});

it('never checks clearing a parent', function (): void {
    insertChildren($this->parent, 150);
    $this->mover->update(['parent_issue_id' => $this->parent->id]);

    app(MoveIssueUnderParent::class)->handle($this->mover->fresh(), null);

    expect($this->mover->fresh()->parent_issue_id)->toBeNull()
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('does not check when the instance is not mirrored', function (): void {
    $parent = localOnlyParent();
    $mover = Issue::factory()->create(['repository_id' => $parent->repository_id]);
    insertChildren($parent, 150);

    app(MoveIssueUnderParent::class)->handle($mover, $parent);

    expect($mover->fresh()->parent_issue_id)->toBe($parent->id)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('exempts a temporary issue moved under a full parent', function (): void {
    insertChildren($this->parent, 100);
    $temporary = Issue::factory()->temporary()->create(['repository_id' => $this->repository->id]);

    // A permanent parent with a temporary child: the existing rule requires temporary parents for permanent children only.
    app(MoveIssueUnderParent::class)->handle($temporary, $this->parent);

    expect($temporary->fresh()->parent_issue_id)->toBe($this->parent->id)
        ->and(app(WriteWarnings::class)->all())->toBe([]);
});

it('exempts moves under a temporary parent', function (): void {
    $temporaryParent = Issue::factory()->temporary()->create(['repository_id' => $this->repository->id]);
    insertChildren($temporaryParent, 120, ['is_temporary' => true]);
    $temporaryChild = Issue::factory()->temporary()->create(['repository_id' => $this->repository->id]);

    app(MoveIssueUnderParent::class)->handle($temporaryChild, $temporaryParent);

    expect(app(WriteWarnings::class)->all())->toBe([]);
});

// Intent: the parent change inside revise and update goes through MoveIssueUnderParent, so both inherit
// the warning and the refusal; a refusal must leave the other edited fields unchanged.
it('warns when revise sets a parent that reaches 80 children', function (): void {
    insertChildren($this->parent, 79);

    app(ReviseTodoIssue::class)->handle(id: $this->mover->id, expectedRevision: 1, parentId: $this->parent->id);

    expect($this->mover->fresh()->parent_issue_id)->toBe($this->parent->id)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1);
});

it('refuses revise into a full parent and leaves the issue unchanged', function (): void {
    insertChildren($this->parent, 100);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $this->mover->id, expectedRevision: 1, title: 'Renamed', parentId: $this->parent->id))
        ->toThrow(TodoValidationException::class, 'Crowded parent');

    $fresh = $this->mover->fresh();
    expect($fresh->parent_issue_id)->toBeNull()->and($fresh->title)->toBe('Mover')->and($fresh->revision)->toBe(1);
});

it('does not warn when revise clears the parent', function (): void {
    insertChildren($this->parent, 150);
    $this->mover->update(['parent_issue_id' => $this->parent->id]);

    app(ReviseTodoIssue::class)->handle(id: $this->mover->id, expectedRevision: 1, clearParent: true);

    expect(app(WriteWarnings::class)->all())->toBe([]);
});

it('warns when update sets a parent that reaches 80 children', function (): void {
    insertChildren($this->parent, 79);

    app(UpdateTodoIssue::class)->handle(id: $this->mover->id, expectedRevision: 1, title: 'Mover', parentId: $this->parent->id);

    expect($this->mover->fresh()->parent_issue_id)->toBe($this->parent->id)
        ->and(app(WriteWarnings::class)->all())->toHaveCount(1);
});

it('refuses update into a full parent and leaves the issue unchanged', function (): void {
    insertChildren($this->parent, 100);

    expect(fn () => app(UpdateTodoIssue::class)->handle(id: $this->mover->id, expectedRevision: 1, title: 'Renamed', parentId: $this->parent->id))
        ->toThrow(TodoValidationException::class, 'Crowded parent');

    $fresh = $this->mover->fresh();
    expect($fresh->parent_issue_id)->toBeNull()->and($fresh->title)->toBe('Mover')->and($fresh->revision)->toBe(1);
});

it('does not warn when update keeps the same parent', function (): void {
    insertChildren($this->parent, 99);
    $this->mover->update(['parent_issue_id' => $this->parent->id]);

    app(UpdateTodoIssue::class)->handle(id: $this->mover->id, expectedRevision: 1, title: 'Mover edited', parentId: $this->parent->id);

    expect(app(WriteWarnings::class)->all())->toBe([]);
});
