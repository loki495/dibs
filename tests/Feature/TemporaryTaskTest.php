<?php

declare(strict_types=1);

use App\Actions\CreateTodoComment;
use App\Actions\CreateTodoIssue;
use App\Actions\DeleteTodoIssue;
use App\Actions\EnqueueGitHubPush;
use App\Actions\MoveIssueUnderParent;
use App\Actions\QueueLocalRecordsForGitHub;
use App\Actions\RenameGroupOption;
use App\Actions\ResolveGroupOption;
use App\Actions\SyncStepFields;
use App\Exceptions\TodoValidationException;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CreateTodoTask;
use App\Models\ChangeLog;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Support\TodoCreationInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['github.owner' => '', 'github.repository' => '']);
    Http::fake();
});

it('creates temporary tasks and prevents permanent children or dependency edges', function (): void {
    $temporary = app(CreateTodoIssue::class)->handle(title: 'Temporary', temporary: true);
    $child = app(CreateTodoIssue::class)->handle(title: 'Temporary child', parentId: $temporary->id, temporary: true);
    $permanent = app(CreateTodoIssue::class)->handle(title: 'Permanent');
    expect($temporary->fresh()->is_temporary)->toBeTrue();
    expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Invalid', parentId: $temporary->id))->toThrow(TodoValidationException::class);
    expect(fn () => app(MoveIssueUnderParent::class)->handle($permanent, $temporary))->toThrow(TodoValidationException::class);
    expect(fn () => app(SyncStepFields::class)->handle($child, blockedBy: [$temporary->id]))->toThrow(TodoValidationException::class);
});

it('does not enqueue temporary issues, comments, memberships or new labels, and queues reused labels for permanent tasks', function (): void {
    GitHubRepository::factory()->create();
    $project = GitHubProject::factory()->create();
    $issue = app(CreateTodoIssue::class)->handle(title: 'Temporary', temporary: true, area: $project->id, newLabelNames: ['temporary label']);
    app(CreateTodoComment::class)->handle($issue->id, 'A comment');
    app(EnqueueGitHubPush::class)->handle('create_label', 'label', $issue->labels()->sole()->id, [], 'temporary-label-create');
    expect(GitHubPushQueueItem::count())->toBe(0);
    $permanent = app(CreateTodoIssue::class)->handle(title: 'Permanent', labelIds: [$issue->labels()->sole()->id]);
    expect(GitHubPushQueueItem::where('operation', 'create_label')->count())->toBe(1)
        ->and(GitHubPushQueueItem::where('target_type', 'issue')->where('target_id', $permanent->id)->exists())->toBeTrue();
});

it('excludes temporary issues and their exclusive labels on first import adoption', function (): void {
    $issue = app(CreateTodoIssue::class)->handle(title: 'Temporary', temporary: true, newLabelNames: ['temporary label']);
    $permanent = app(CreateTodoIssue::class)->handle(title: 'Permanent', newLabelNames: ['permanent label']);
    $repo = $issue->repository;
    $repo->update(['is_local' => false]);
    app(QueueLocalRecordsForGitHub::class)->handle($repo);
    expect(GitHubPushQueueItem::where('target_type', 'issue')->where('target_id', $issue->id)->exists())->toBeFalse()
        ->and(GitHubPushQueueItem::where('target_type', 'label')->where('target_id', $issue->labels()->sole()->id)->exists())->toBeFalse()
        ->and(GitHubPushQueueItem::where('target_type', 'issue')->where('target_id', $permanent->id)->exists())->toBeTrue();
});

it('requires a strict boolean temporary field', function (): void {
    expect(Validator::make(['title' => 'test', 'temporary' => 'true'], TodoCreationInput::rules())->fails())->toBeTrue();
});

it('accepts temporary tasks through MCP single and batch creation and the CLI', function (): void {
    TodoServer::tool(CreateTodoTask::class, ['title' => 'MCP single', 'temporary' => true])->assertOk();
    TodoServer::tool(CreateTodoTask::class, ['tasks' => [['title' => 'MCP batch', 'temporary' => true]]])->assertOk();
    $this->artisan('todo:agent:create', ['title' => 'CLI', '--temporary' => true])->assertSuccessful();
    expect(Issue::whereIn('title', ['MCP single', 'MCP batch', 'CLI'])->where('is_temporary', true)->count())->toBe(3);
});

it('keeps detached temporary labels out of first import', function (): void {
    $issue = app(CreateTodoIssue::class)->handle(title: 'Temporary', temporary: true, newLabelNames: ['detached']);
    $label = $issue->labels()->sole();
    $issue->labels()->detach();
    $repo = $issue->repository;
    $repo->update(['is_local' => false]);
    app(QueueLocalRecordsForGitHub::class)->handle($repo);
    expect(GitHubPushQueueItem::where('target_type', 'label')->where('target_id', $label->id)->exists())->toBeFalse();
});

it('does not retain temporary label and group creation in activity after consumption', function (): void {
    GitHubRepository::factory()->create();
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->create(['project_id' => $project->id, 'semantic_key' => 'group']);
    $this->artisan('todo:agent:create', ['title' => 'Temporary metadata', '--temporary' => true, '--area' => $project->id,
        '--new-group' => 'Private group', '--new-label' => 'Private label'])->assertSuccessful();
    $issue = Issue::where('title', 'Temporary metadata')->sole();
    app(DeleteTodoIssue::class)->handle($issue, false);

    expect(ChangeLog::count())->toBe(0);
});

it('keeps renamed and detached temporary groups out of the mirror until permanent reuse', function (): void {
    GitHubRepository::factory()->create();
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->create(['project_id' => $project->id, 'semantic_key' => 'group']);
    $issue = app(CreateTodoIssue::class)->handle(title: 'Temporary', temporary: true, area: $project->id, newGroupName: 'Private group');
    $group = $issue->projectItems()->sole()->groupOption;
    app(RenameGroupOption::class)->handle($group, 'Private renamed group');
    app(DeleteTodoIssue::class)->handle($issue, false);
    app(RenameGroupOption::class)->handle($group, 'Detached group');
    app(QueueLocalRecordsForGitHub::class)->handle($issue->repository);
    expect(GitHubPushQueueItem::count())->toBe(0);

    app(CreateTodoIssue::class)->handle(title: 'Permanent', area: $project->id, groupId: $group->id);
    expect(GitHubPushQueueItem::where('operation', 'create_group_option')->where('target_id', $group->id)->count())->toBe(1)
        ->and($group->fresh()->is_temporary_origin)->toBeFalse();
    app(RenameGroupOption::class)->handle($group->fresh(), 'Shared group');
    expect(GitHubPushQueueItem::where('operation', 'rename_group_option')->count())->toBe(1);
});

it('continues mirroring independently created unpublished groups', function (): void {
    GitHubRepository::factory()->create();
    $project = GitHubProject::factory()->create();
    ProjectField::factory()->create(['project_id' => $project->id, 'semantic_key' => 'group']);
    $group = app(ResolveGroupOption::class)->handle($project, 'Ordinary group');
    app(RenameGroupOption::class)->handle($group, 'Renamed ordinary group');

    expect(GitHubPushQueueItem::where('operation', 'create_group_option')->count())->toBe(1)
        ->and(GitHubPushQueueItem::where('operation', 'rename_group_option')->count())->toBe(1)
        ->and(ChangeLog::where('action', 'ResolveGroupOption')->count())->toBe(1);
});
