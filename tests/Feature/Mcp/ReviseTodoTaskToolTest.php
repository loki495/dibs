<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReviseTodoTask;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
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

it('changes the parent and Group by name, and clears them, through the tool', function (): void {
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->create(['revision' => 1]);
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'parentId' => $parent->id, 'newGroupName' => 'Homelab'])
        ->assertOk()->assertHasNoErrors()->assertSee('"conflict":false');
    expect($issue->fresh()->parent_issue_id)->toBe($parent->id)->and($item->fresh()->group_option_id)->not->toBeNull();

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 2, 'clearParent' => true, 'clearGroup' => true])
        ->assertOk()->assertHasNoErrors();
    expect($issue->fresh()->parent_issue_id)->toBeNull()->and($item->fresh()->group_option_id)->toBeNull();
});

it('returns structured errors for contradictory or invalid parent and Group arguments', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'parentId' => $issue->id])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'parentId' => 0])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'parentId' => 5, 'clearParent' => true])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'groupId' => 5, 'clearGroup' => true])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'clearGroup' => 'yes'])->assertHasErrors();

    expect($issue->fresh()->revision)->toBe(1);
});

it('adds and removes links through the tool and reports them in the response', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $rule = Issue::factory()->create(['title' => 'A linked rule']);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLinks' => [['issueId' => $rule->id]]])
        ->assertOk()->assertHasNoErrors()->assertSee('A linked rule');
    expect($issue->links()->count())->toBe(1);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 2, 'removeLinks' => [['issueId' => $rule->id]]])->assertOk();
    expect($issue->links()->count())->toBe(0);
});

it('returns a structured error when linking to a missing record', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLinks' => [['issueId' => 999999]]])
        ->assertHasErrors(['does not exist']);
    expect($issue->links()->count())->toBe(0);
});

it('rejects malformed link arguments', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'addLinks' => [['issueId' => 'abc']]])->assertHasErrors();
});

function areaWithOptions(string $title, string $priorityName = '1'): array
{
    $project = GitHubProject::factory()->create(['title' => $title]);
    $group = ProjectFieldOption::factory()->for(ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']), 'field')->create(['name' => 'Dibs']);
    $priority = ProjectFieldOption::factory()->for(ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']), 'field')->create(['name' => $priorityName]);

    return [$project, $group, $priority];
}

it('sets and clears a Priority by id and by name, queueing each change', function (): void {
    [$project, , $one] = areaWithOptions('Alpha', '1');
    $five = ProjectFieldOption::factory()->for($one->field, 'field')->create(['name' => '5']);
    $issue = Issue::factory()->create(['revision' => 1]);
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['priority_option_id' => $five->id]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'priorityId' => $one->id])->assertOk()->assertHasNoErrors();
    expect($item->fresh()->priority_option_id)->toBe($one->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'set_project_item_priority')->where('target_id', $item->id)->count())->toBe(1);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 2, 'priorityName' => '5'])->assertOk()->assertHasNoErrors();
    expect($item->fresh()->priority_option_id)->toBe($five->id);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 3, 'clearPriority' => true])->assertOk()->assertHasNoErrors();
    expect($item->fresh()->priority_option_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_priority')->where('target_id', $item->id)->count())->toBe(1);
});

it('refuses a Priority from another Area, an unknown name, and a Priority on an issue with no area', function (): void {
    [$project] = areaWithOptions('Alpha');
    [, , $foreign] = areaWithOptions('Beta');
    $issue = Issue::factory()->create(['revision' => 1]);
    ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();
    $bare = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'priorityId' => $foreign->id])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'priorityName' => '9'])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'priorityId' => $foreign->id, 'clearPriority' => true])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $bare->id, 'expectedRevision' => 1, 'priorityId' => $foreign->id])->assertHasErrors();

    expect($issue->fresh()->revision)->toBe(1)->and($bare->fresh()->revision)->toBe(1);
});

it('moves an issue to another Area by id or title, dropping the old Group and Priority', function (): void {
    [$alpha, $group, $priority] = areaWithOptions('Alpha');
    [$beta] = areaWithOptions('Beta');
    $issue = Issue::factory()->create(['revision' => 1]);
    $old = ProjectItem::factory()->for($alpha, 'project')->for($issue, 'issue')->create(['github_node_id' => null, 'group_option_id' => $group->id, 'priority_option_id' => $priority->id]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => $beta->id])->assertOk()->assertHasNoErrors();

    $new = ProjectItem::query()->where('issue_id', $issue->id)->where('project_id', $beta->id)->first();
    expect($new->group_option_id)->toBeNull()->and($new->priority_option_id)->toBeNull()
        ->and($old->fresh()->is_available)->toBeFalse();

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 2, 'areaName' => 'alpha'])->assertOk()->assertHasNoErrors();
    expect(ProjectItem::query()->where('issue_id', $issue->id)->where('project_id', $alpha->id)->where('is_available', true)->count())->toBe(1);
});

it('moves Area and sets the new Area\'s Group and Priority in one call', function (): void {
    [$alpha] = areaWithOptions('Alpha');
    [$beta, $betaGroup, $betaPriority] = areaWithOptions('Beta', '2');
    $issue = Issue::factory()->create(['revision' => 1]);
    ProjectItem::factory()->for($alpha, 'project')->for($issue, 'issue')->create(['github_node_id' => null]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => $beta->id, 'groupId' => $betaGroup->id, 'priorityName' => '2'])->assertOk()->assertHasNoErrors();

    $new = ProjectItem::query()->where('issue_id', $issue->id)->where('project_id', $beta->id)->first();
    expect($new->group_option_id)->toBe($betaGroup->id)->and($new->priority_option_id)->toBe($betaPriority->id);
});

it('gives an area-less issue an Area, and clears an Area', function (): void {
    [$alpha] = areaWithOptions('Alpha');
    $issue = Issue::factory()->create(['revision' => 1]);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => $alpha->id])->assertOk()->assertHasNoErrors();
    expect(ProjectItem::query()->where('issue_id', $issue->id)->where('is_available', true)->count())->toBe(1);

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 2, 'clearArea' => true])->assertOk()->assertHasNoErrors();
    expect(ProjectItem::query()->where('issue_id', $issue->id)->where('is_available', true)->count())->toBe(0);
});

it('refuses an unknown Area, contradictory Area arguments, a Group from the old Area, and clearArea with a Priority', function (): void {
    [$alpha, $alphaGroup, $alphaPriority] = areaWithOptions('Alpha');
    [$beta] = areaWithOptions('Beta');
    $issue = Issue::factory()->create(['revision' => 1]);
    ProjectItem::factory()->for($alpha, 'project')->for($issue, 'issue')->create();

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => 999_999])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaName' => 'Nope'])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaName' => ' '])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => $beta->id, 'clearArea' => true])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => $beta->id, 'groupId' => $alphaGroup->id])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'areaId' => $beta->id, 'priorityId' => $alphaPriority->id])->assertHasErrors();
    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'clearArea' => true, 'clearPriority' => true])->assertHasErrors();

    expect($issue->fresh()->revision)->toBe(1)
        ->and(ProjectItem::query()->where('issue_id', $issue->id)->where('is_available', true)->pluck('project_id')->all())->toBe([$alpha->id]);
});

it('reports a conflict instead of moving Area or Priority on a stale revision', function (): void {
    [$alpha, , $priority] = areaWithOptions('Alpha');
    $issue = Issue::factory()->create(['revision' => 2]);
    $item = ProjectItem::factory()->for($alpha, 'project')->for($issue, 'issue')->create();

    TodoServer::tool(ReviseTodoTask::class, ['id' => $issue->id, 'expectedRevision' => 1, 'priorityId' => $priority->id])->assertOk()->assertSee('"conflict":true');

    expect($item->fresh()->priority_option_id)->toBeNull();
});
