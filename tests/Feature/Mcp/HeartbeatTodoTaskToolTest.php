<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\HeartbeatTodoTask;
use App\Models\Issue;
use App\Models\TaskClaim;

it('exposes the tool under the todo_heartbeat name', function (): void {
    expect(app(HeartbeatTodoTask::class)->name())->toBe('todo_heartbeat');
});

it('renews a live claim held by the calling process', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 5);
    $originalExpiry = $result['claim']->expires_at;

    TodoServer::tool(HeartbeatTodoTask::class, ['issueId' => $issue->id, 'capabilityToken' => $result['capability_token'], 'minutes' => 30])
        ->assertOk()
        ->assertHasNoErrors();

    expect(TaskClaim::query()->sole()->expires_at->greaterThan($originalExpiry))->toBeTrue();
});

it('refuses to renew a claim the caller does not hold', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 5);

    TodoServer::tool(HeartbeatTodoTask::class, ['issueId' => $issue->id, 'capabilityToken' => 'wrong-token'])
        ->assertHasErrors(['No live claim']);
});

it('rejects a call missing required arguments', function (): void {
    TodoServer::tool(HeartbeatTodoTask::class, [])->assertHasErrors();
});
