<?php

declare(strict_types=1);

use App\Models\Issue;
use App\Models\Label;

it('revises a task title through the CLI', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old', 'revision' => 1]);

    $this->artisan('todo:agent:update', ['issue' => $issue->id, '--expected-revision' => '1', '--title' => 'New title'])
        ->expectsOutputToContain('"conflict": false')
        ->assertSuccessful();

    expect($issue->fresh()->title)->toBe('New title')->and($issue->fresh()->revision)->toBe(2);
});

it('returns a non-error conflict payload on a stale revision', function (): void {
    $issue = Issue::factory()->create(['title' => 'Original', 'revision' => 1]);
    $issue->update(['title' => 'Changed elsewhere', 'revision' => 2]);

    $this->artisan('todo:agent:update', ['issue' => $issue->id, '--expected-revision' => '1', '--title' => 'My change'])
        ->expectsOutputToContain('"conflict": true')
        ->assertSuccessful();

    expect($issue->fresh()->title)->toBe('Changed elsewhere');
});

it('rejects a call missing --expected-revision', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:update', ['issue' => $issue->id, '--title' => 'x'])->assertFailed();
});

it('returns a structured error for a nonexistent issue', function (): void {
    $this->artisan('todo:agent:update', ['issue' => 999999, '--expected-revision' => '1', '--title' => 'x'])->assertFailed();
});

it('rejects a call with neither --title nor --body', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    $this->artisan('todo:agent:update', ['issue' => $issue->id, '--expected-revision' => '1'])->assertFailed();
});

it('adds and removes labels through the CLI', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $old = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'today']);
    $issue->labels()->attach($old);

    $this->artisan('todo:agent:update', ['issue' => $issue->id, '--expected-revision' => '1', '--add-label' => ['bug', 'agent task'], '--remove-label' => ['today']])
        ->expectsOutputToContain('"conflict": false')
        ->assertSuccessful();

    expect($issue->labels()->pluck('name')->sort()->values()->all())->toBe(['agent task', 'bug']);
});

it('fails with a message for a label both added and removed', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    $this->artisan('todo:agent:update', ['issue' => $issue->id, '--expected-revision' => '1', '--add-label' => ['bug'], '--remove-label' => ['bug']])
        ->expectsOutputToContain('both added and removed')
        ->assertFailed();
});
