<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ClaimTodoTask;
use App\Models\Issue;
use App\Models\TaskClaim;

it('exposes the tool under the todo_claim name', function (): void {
    expect(app(ClaimTodoTask::class)->name())->toBe('todo_claim');
});

it('claims an available task, identifying the caller pid automatically', function (): void {
    $issue = Issue::factory()->create();

    TodoServer::tool(ClaimTodoTask::class, ['issueId' => $issue->id, 'agentName' => 'codex'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('capabilityToken');

    $claim = TaskClaim::query()->sole();
    expect($claim->agentSession->agent_name)->toBe('codex')
        ->and($claim->agentSession->pid)->toBe(posix_getppid())
        ->and($claim->agentSession->is_verified_live)->toBeTrue();
});

it('returns a structured error for a nonexistent or unavailable issue', function (): void {
    TodoServer::tool(ClaimTodoTask::class, ['issueId' => 999_999, 'agentName' => 'codex'])
        ->assertHasErrors();

    expect(TaskClaim::query()->count())->toBe(0);
});

it('refuses to claim a task another live session already holds', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'claude', posix_getppid(), 30);

    TodoServer::tool(ClaimTodoTask::class, ['issueId' => $issue->id, 'agentName' => 'codex'])
        ->assertHasErrors(['currently claimed']);

    expect(TaskClaim::query()->count())->toBe(1);
});

it('rejects a call missing required arguments', function (): void {
    TodoServer::tool(ClaimTodoTask::class, [])->assertHasErrors();
});
