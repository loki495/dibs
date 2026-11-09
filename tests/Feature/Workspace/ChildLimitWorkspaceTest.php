<?php

declare(strict_types=1);

use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\User;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: the web UI surfaces the warning as a Flux toast (Livewire 'toast-show' event) after create and
// after an edit that changes the parent; a refusal shows in the existing captureError / editError field.
// ASSUMPTION: no existing component flashes success today, so the toast mechanism is Flux::toast().
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Crowded parent']);
    $this->user = User::factory()->create();
});

it('dispatches a warning toast when capture makes the 80th child', function (): void {
    insertChildren($this->parent, 79);

    Livewire::actingAs($this->user)->test('pages::workspace')
        ->set('newTitle', 'Eightieth')->set('captureParent', $this->parent->id)
        ->call('capture')
        ->assertSet('captureError', null)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains(json_encode($params), 'Crowded parent'));

    expect(childCount($this->parent))->toBe(80);
});

it('dispatches no toast when capture stays below the threshold', function (): void {
    insertChildren($this->parent, 3);

    Livewire::actingAs($this->user)->test('pages::workspace')
        ->set('newTitle', 'Quiet')->set('captureParent', $this->parent->id)
        ->call('capture')
        ->assertNotDispatched('toast-show');
});

it('shows the refusal as a capture error for the 101st child and writes nothing', function (): void {
    insertChildren($this->parent, 100);

    Livewire::actingAs($this->user)->test('pages::workspace')
        ->set('newTitle', 'Overflow')->set('captureParent', $this->parent->id)
        ->call('capture')
        ->assertSet('captureError', fn (?string $error): bool => $error !== null && str_contains($error, 'Crowded parent'));

    expect(Issue::query()->where('title', 'Overflow')->exists())->toBeFalse();
});

it('dispatches a warning toast when editing moves an issue under a parent reaching 80', function (): void {
    insertChildren($this->parent, 79);
    $mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Mover', 'revision' => 1]);

    Livewire::actingAs($this->user)->test('pages::workspace')
        ->set('selected', $mover->id)->call('beginEdit')
        ->set('editParent', $this->parent->id)->call('saveIssue')
        ->assertSet('editError', null)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains(json_encode($params), 'Crowded parent'));

    expect($mover->fresh()->parent_issue_id)->toBe($this->parent->id);
});

it('shows the refusal as an edit error when the new parent is full', function (): void {
    insertChildren($this->parent, 100);
    $mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Mover', 'revision' => 1]);

    Livewire::actingAs($this->user)->test('pages::workspace')
        ->set('selected', $mover->id)->call('beginEdit')
        ->set('editParent', $this->parent->id)->call('saveIssue')
        ->assertSet('editError', fn (?string $error): bool => $error !== null && str_contains($error, 'Crowded parent'));

    expect($mover->fresh()->parent_issue_id)->toBeNull();
});
