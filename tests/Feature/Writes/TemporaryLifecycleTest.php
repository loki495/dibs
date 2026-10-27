<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\CompleteTodoTask;
use App\Actions\CreateTodoComment;
use App\Actions\CreateTodoIssue;
use App\Actions\DeleteTodoIssue;
use App\Actions\ReleaseTaskClaim;
use App\Exceptions\TodoValidationException;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CompleteTodoTaskTool;
use App\Models\AgentSession;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\McpWriteReceipt;
use App\Models\TaskClaim;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::fake());

it('purges a claimed temporary task and copies only its summary to its permanent parent', function (): void {
    $parent = Issue::factory()->create();
    $task = Issue::factory()->temporary()->create(['parent_issue_id' => $parent->id, 'title' => 'private title']);
    $claim = app(ClaimTaskForAgent::class)->handle($task, 'codex', posix_getppid(), 30);
    TodoServer::tool(CompleteTodoTaskTool::class, ['issueId' => $task->id, 'capabilityToken' => $claim['capability_token'], 'summary' => 'Done safely.'])
        ->assertOk()->assertSee('"deleted":true')->assertSee('"temporary":true');
    expect(Issue::find($task->id))->toBeNull()
        ->and(TaskClaim::where('issue_id', $task->id)->exists())->toBeFalse()
        ->and(Comment::where('issue_id', $parent->id)->sole()->body)->toBe('Done safely.');
});

it('requires the correct claim even for temporary tasks', function (): void {
    $task = Issue::factory()->temporary()->create();
    app(ClaimTaskForAgent::class)->handle($task, 'codex', getmypid(), 30);
    expect(fn () => app(CompleteTodoTask::class)->handle($task->id, getmypid(), 'bad'))->toThrow(DomainException::class);
    expect($task->fresh())->not->toBeNull();
});

it('refuses to purge a temporary parent or cascade through temporary descendants', function (): void {
    $parent = Issue::factory()->create();
    $task = Issue::factory()->temporary()->create(['parent_issue_id' => $parent->id]);
    Issue::factory()->temporary()->create(['parent_issue_id' => $task->id]);
    expect(fn () => app(DeleteTodoIssue::class)->handle($task, false))->toThrow(TodoValidationException::class);
    expect(fn () => app(DeleteTodoIssue::class)->handle($parent, true))->toThrow(TodoValidationException::class);
    expect($task->fresh())->not->toBeNull();
});

it('retains released temporary work then discards it without receipts or orphaned claim sessions', function (): void {
    config(['github.owner' => '', 'github.repository' => '']);
    GitHubRepository::factory()->create();
    $task = app(CreateTodoIssue::class)->handle(title: 'Private handoff', idempotencyKey: 'handoff-create', temporary: true);
    app(CreateTodoComment::class)->handle($task->id, 'Private comment', 'handoff-comment');
    $first = app(ClaimTaskForAgent::class)->handle($task, 'first worker', getmypid(), 30);
    app(ReleaseTaskClaim::class)->handle($task->id, getmypid(), $first['capability_token'], 'Read the remaining state.');
    expect($task->fresh())->not->toBeNull()
        ->and(GitHubPushQueueItem::count())->toBe(0);
    $second = app(ClaimTaskForAgent::class)->handle($task->fresh(), 'second worker', getmypid(), 30);
    app(CompleteTodoTask::class)->handle($task->id, getmypid(), $second['capability_token'], reason: 'NOT_PLANNED');
    expect(Issue::find($task->id))->toBeNull()
        ->and(Comment::where('issue_id', $task->id)->count())->toBe(0)
        ->and(TaskClaim::where('issue_id', $task->id)->count())->toBe(0)
        ->and(McpWriteReceipt::count())->toBe(0)
        ->and(AgentSession::count())->toBe(0)
        ->and(GitHubPushQueueItem::count())->toBe(0);
});
