<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReviseTodoTask;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

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

it('moves an issue into a different Group through the tool', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Homelab']);
    $issue = Issue::factory()->create(['revision' => 1]);
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'groupId' => $group->id])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"conflict":false');

    expect($item->fresh()->group_option_id)->toBe($group->id);
});

it('returns a structured error when setting a Group on an issue with no area assigned yet', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'groupId' => $group->id])
        ->assertHasErrors();
});

it('adds and removes labels by name through the tool and returns them in the detail', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $old = Label::factory()->for($issue->repository, 'repository')->create(['name' => 'today']);
    $issue->labels()->attach($old);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLabels' => ['Next', 'lesson'], 'removeLabels' => ['today', 'not there']])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"conflict":false')
        ->assertSee('lesson');

    expect($issue->labels()->pluck('name')->sort()->values()->all())->toBe(['lesson', 'next']);
});

it('returns a structured error for a blank or contradictory label change', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLabels' => ['bug'], 'removeLabels' => ['bug']])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLabels' => ['']])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLabels' => 'bug'])->assertHasErrors();

    expect($issue->fresh()->revision)->toBe(1)->and($issue->labels()->count())->toBe(0);
});

it('reports a conflict instead of changing labels on a stale revision', function (): void {
    $issue = Issue::factory()->create(['revision' => 2]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLabels' => ['bug']])
        ->assertOk()
        ->assertSee('"conflict":true');

    expect($issue->labels()->count())->toBe(0);
});
