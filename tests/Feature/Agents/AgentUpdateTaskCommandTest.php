<?php

declare(strict_types=1);

use App\Models\Issue;

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
