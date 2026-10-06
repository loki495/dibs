<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoClaimTool;
use App\Models\Issue;
use App\Models\TaskClaim;

it('exposes the tool under the todo_claim_status name', function (): void {
    expect(app(DescribeTodoClaimTool::class)->name())->toBe('todo_claim_status');
});

it('reports no claim for a free task', function (): void {
    $issue = Issue::factory()->create();

    TodoServer::tool(DescribeTodoClaimTool::class, ['issueId' => $issue->id])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"claimed":false');
});

it('reports live claim details without exposing the capability token', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(DescribeTodoClaimTool::class, ['issueId' => $issue->id])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"claimed":true')
        ->assertSee('codex')
        ->assertDontSee('capabilityToken');
});

it('includes the watcher\'s recorded liveness alongside the direct check', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['liveness_alive' => true, 'liveness_checked_at' => now()]);

    TodoServer::tool(DescribeTodoClaimTool::class, ['issueId' => $issue->id])
        ->assertOk()
        ->assertSee('"isCurrentlyAlive":true')
        ->assertSee('"recordedLiveness":{"alive":true')
        ->assertSee('"isStale":false');
});

it('rejects a call missing the required issueId', function (): void {
    TodoServer::tool(DescribeTodoClaimTool::class, [])->assertHasErrors();
});
