<?php

declare(strict_types=1);

use App\Actions\CheckCompletionGuards;
use App\Actions\CreateTodoIssue;
use App\Actions\DescribeTodoIssue;
use App\Actions\ReviseTodoIssue;
use App\Actions\ScaffoldTodoPlan;
use App\Exceptions\TodoValidationException;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReviseTodoTask;
use App\Models\ChangeLog;
use App\Models\GitHubRepository;
use App\Models\Issue;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

/** @return array{plan: Issue, a: Issue, b: Issue, c: Issue} */
function stepsPlan(): array
{
    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Plan', newLabelName: 'plan', children: [['title' => 'A'], ['title' => 'B'], ['title' => 'C']]);
    [$a, $b, $c] = $plan->children->all();

    return ['plan' => $plan, 'a' => $a, 'b' => $b, 'c' => $c];
}

it('sets one step field through todo_revise without touching the body, bumping the revision', function (): void {
    $issue = Issue::factory()->create(['body' => "Objective: do X.\nDone-when: old line.", 'revision' => 3]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 3, 'step' => ['doneWhen' => 'X ships with tests.']])
        ->assertOk()->assertHasNoErrors();

    $issue->refresh();
    expect($issue->body)->toBe("Objective: do X.\nDone-when: old line.")
        ->and($issue->done_when)->toBe('X ships with tests.')
        ->and($issue->revision)->toBe(4)
        ->and(app(DescribeTodoIssue::class)->handle($issue->id)['step']['doneWhen'])->toBe('X ships with tests.')
        ->and(ChangeLog::query()->where('action', 'SyncStepFields')->sole()->changes)->toHaveKey('done_when');
});

it('clears a field with null and falls back to the body line', function (): void {
    $issue = Issue::factory()->create(['body' => 'Evidence: body SHA', 'evidence' => 'field SHA', 'revision' => 1]);

    app(ReviseTodoIssue::class)->handle(id: $issue->id, expectedRevision: $issue->revision, step: ['evidence' => null]);

    expect($issue->fresh()->evidence)->toBeNull()
        ->and(app(DescribeTodoIssue::class)->handle($issue->id)['step']['evidence'])->toBe('body SHA');
});

it('omits step from todo_show when no field, line or blocker is set', function (): void {
    expect(app(DescribeTodoIssue::class)->handle(Issue::factory()->create(['body' => 'plain'])->id))->not->toHaveKey('step');
});

it('replaces the blocked-by list between siblings and clears it with an empty list', function (): void {
    ['a' => $a, 'b' => $b, 'c' => $c] = stepsPlan();
    $revise = app(ReviseTodoIssue::class);

    $revise->handle(id: $c->id, expectedRevision: $c->fresh()->revision, blockedBy: [$a->id, $b->id]);
    expect(app(DescribeTodoIssue::class)->handle($c->id)['step']['blockedBy'])->toBe([
        ['id' => $a->id, 'title' => 'A', 'state' => 'OPEN'], ['id' => $b->id, 'title' => 'B', 'state' => 'OPEN'],
    ]);

    $revise->handle(id: $c->id, expectedRevision: $c->fresh()->revision, blockedBy: []);
    expect($c->fresh()->blockedBy()->count())->toBe(0);
});

it('refuses self, cross-plan, missing and cyclic blockers, changing nothing', function (Closure $blockers, string $message): void {
    $steps = stepsPlan();
    $steps['b']->blockedBy()->attach($steps['a']->id);
    $other = app(ScaffoldTodoPlan::class)->handle(title: 'Other', children: [['title' => 'Elsewhere']])->children->first();

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $steps['a']->id, expectedRevision: $steps['a']->fresh()->revision, step: ['doneWhen' => 'set'], blockedBy: $blockers($steps, $other)))
        ->toThrow(TodoValidationException::class, $message);
    expect($steps['a']->fresh()->blockedBy()->count())->toBe(0)
        ->and($steps['a']->fresh()->done_when)->toBeNull();
})->with([
    'self' => [fn (array $s): array => [$s['a']->id], 'blocked by itself'],
    'cross-plan' => [fn (array $s, Issue $other): array => [$other->id], 'sibling steps of the same plan'],
    'missing' => [fn (): array => [999_999], 'does not exist'],
    'cycle' => [fn (array $s): array => [$s['b']->id], 'cycle'],
]);

it('detects a longer cycle through another step', function (): void {
    ['a' => $a, 'b' => $b, 'c' => $c] = stepsPlan();
    $b->blockedBy()->attach($a->id);
    $c->blockedBy()->attach($b->id);

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $a->id, expectedRevision: $a->fresh()->revision, blockedBy: [$c->id]))
        ->toThrow(TodoValidationException::class, 'cycle');
});

it('refuses blockers on an issue with no parent', function (): void {
    $lonely = Issue::factory()->create(['revision' => 1]);
    $other = Issue::factory()->create();

    expect(fn () => app(ReviseTodoIssue::class)->handle(id: $lonely->id, expectedRevision: $lonely->revision, blockedBy: [$other->id]))
        ->toThrow(TodoValidationException::class, 'sibling steps');
});

it('sets fields and blockers on create under a parent', function (): void {
    ['plan' => $plan, 'a' => $a] = stepsPlan();

    $step = app(CreateTodoIssue::class)->handle(title: 'D', parentId: $plan->id, step: ['filesLikely' => 'app/X.php'], blockedBy: [$a->id]);

    expect($step->fresh()->files_likely)->toBe('app/X.php')->and($step->blockedBy()->pluck('issues.id')->all())->toBe([$a->id]);
});

it('scaffolds children blocked by earlier children by position', function (): void {
    $plan = app(ScaffoldTodoPlan::class)->handle(title: 'Plan', children: [
        ['title' => 'First', 'step' => ['doneWhen' => 'first done']],
        ['title' => 'Second', 'blockedBy' => [0]],
    ]);
    [$first, $second] = $plan->children->all();

    expect($first->done_when)->toBe('first done')->and($second->blockedBy()->pluck('issues.id')->all())->toBe([$first->id]);
});

it('refuses a scaffold child blocked by itself or a later child, creating nothing', function (int $position): void {
    expect(fn () => app(ScaffoldTodoPlan::class)->handle(title: 'Plan', children: [['title' => 'First'], ['title' => 'Second', 'blockedBy' => [$position]]]))
        ->toThrow(TodoValidationException::class, 'earlier child');
    expect(Issue::query()->count())->toBe(0);
})->with([1, 2, -1]);

it('rejects unknown step keys at the tool boundary', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => $issue->revision, 'step' => ['tier' => 'Opus']])->assertHasErrors();
    expect($issue->fresh()->revision)->toBe($issue->revision);
});

it('lets stepLint and the Done-when guard accept a field or a body line', function (): void {
    ['plan' => $plan, 'a' => $a, 'b' => $b, 'c' => $c] = stepsPlan();
    $a->update(['done_when' => 'field', 'evidence' => 'field', 'files_likely' => 'field']);
    $b->update(['body' => "Done-when: line\nEvidence: line\nFiles likely: line"]);

    $lint = collect(app(DescribeTodoIssue::class)->handle($plan->id)['stepLint'])->pluck('id')->all();
    expect($lint)->not->toContain($a->id)->not->toContain($b->id)->toContain($c->id);

    $guard = fn (Issue $step): string => implode(' ', app(CheckCompletionGuards::class)->handle($step->fresh(), null, 'Done.', ['done_when_held' => true])['missing']);
    expect($guard($a))->not->toContain('has no Done-when')
        ->and($guard($b))->not->toContain('has no Done-when')
        ->and($guard($c))->toContain('has no Done-when');
});
