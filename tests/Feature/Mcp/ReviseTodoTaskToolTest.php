<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReviseTodoTask;
use App\Models\Issue;

it('exposes the tool under the todo_revise name', function (): void {
    expect(app(ReviseTodoTask::class)->name())->toBe('todo_revise');
});

it('revises an issue through the tool', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old', 'revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'title' => 'New title'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('New title')
        ->assertSee('"conflict":false');

    expect($issue->fresh()->title)->toBe('New title');
});

it('returns a non-error conflict response with current data on a stale revision', function (): void {
    $issue = Issue::factory()->create(['title' => 'Original', 'revision' => 1]);
    $issue->update(['title' => 'Changed elsewhere', 'revision' => 2]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'title' => 'My change'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"conflict":true')
        ->assertSee('Changed elsewhere');

    expect($issue->fresh()->title)->toBe('Changed elsewhere');
});

it('returns a structured error for a nonexistent issue', function (): void {
    TodoServer::tool(ReviseTodoTask::class, ['id' => 999_999, 'expectedRevision' => 1, 'title' => 'x'])
        ->assertHasErrors();
});

it('rejects a call missing required arguments', function (): void {
    TodoServer::tool(ReviseTodoTask::class, [])->assertHasErrors();
});
