<?php

declare(strict_types=1);

use App\Models\GitHubRepository;
use App\Models\Issue;

require_once __DIR__.'/../../Support/ChildLimitFixtures.php';

// Intent: the agent CLI prints each warning as its own `Warning: <message>` line; a refusal fails the
// command and writes nothing.
beforeEach(function (): void {
    $this->repository = GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
    $this->parent = Issue::factory()->create(['repository_id' => $this->repository->id, 'title' => 'Crowded parent']);
});

it('prints a Warning line when todo:agent:create reaches 80 children', function (): void {
    insertChildren($this->parent, 79);

    $this->artisan('todo:agent:create', ['title' => 'Eightieth', '--parent' => $this->parent->id])
        ->expectsOutputToContain('Warning: ')
        ->expectsOutputToContain('Crowded parent')
        ->assertSuccessful();

    expect(childCount($this->parent))->toBe(80);
});

it('prints no Warning line from todo:agent:create below the threshold', function (): void {
    insertChildren($this->parent, 5);

    $this->artisan('todo:agent:create', ['title' => 'Quiet', '--parent' => $this->parent->id])
        ->doesntExpectOutputToContain('Warning:')
        ->assertSuccessful();
});

it('fails todo:agent:create for the 101st child and writes nothing', function (): void {
    insertChildren($this->parent, 100);

    $this->artisan('todo:agent:create', ['title' => 'Overflow', '--parent' => $this->parent->id])
        ->assertFailed();

    expect(Issue::query()->where('title', 'Overflow')->exists())->toBeFalse();
});

it('prints a Warning line when todo:agent:update moves an issue to reach 80 children', function (): void {
    insertChildren($this->parent, 79);
    $mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'revision' => 1]);

    $this->artisan('todo:agent:update', ['issue' => $mover->id, '--expected-revision' => '1', '--parent' => $this->parent->id])
        ->expectsOutputToContain('Warning: ')
        ->assertSuccessful();

    expect($mover->fresh()->parent_issue_id)->toBe($this->parent->id);
});

it('fails todo:agent:update for a move into a full parent and changes nothing', function (): void {
    insertChildren($this->parent, 100);
    $mover = Issue::factory()->create(['repository_id' => $this->repository->id, 'revision' => 1]);

    $this->artisan('todo:agent:update', ['issue' => $mover->id, '--expected-revision' => '1', '--parent' => $this->parent->id])
        ->assertFailed();

    expect($mover->fresh()->parent_issue_id)->toBeNull();
});
