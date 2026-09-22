<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CompleteTodoTaskTool;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\TaskClaim;

it('exposes the tool under the todo_complete name', function (): void {
    expect(app(CompleteTodoTaskTool::class)->name())->toBe('todo_complete');
});

it('completes a claimed task, closing it and posting the summary', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(CompleteTodoTaskTool::class, ['issueId' => $issue->id, 'capabilityToken' => $result['capability_token'], 'summary' => 'Shipped it.'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"state":"CLOSED"');

    expect($issue->fresh()->state)->toBe('CLOSED')
        ->and(GitHubPushQueueItem::query()->where('operation', 'close_issue')->exists())->toBeTrue()
        ->and(Comment::query()->where('issue_id', $issue->id)->sole()->body)->toBe('Shipped it.')
        ->and(TaskClaim::query()->sole()->released_at)->not->toBeNull();
});

it('refuses to complete a task the caller does not hold the claim for', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(CompleteTodoTaskTool::class, ['issueId' => $issue->id, 'capabilityToken' => 'wrong-token'])
        ->assertHasErrors(['No live claim']);

    expect($issue->fresh()->state)->toBe('OPEN');
});

it('rejects a call missing required arguments', function (): void {
    TodoServer::tool(CompleteTodoTaskTool::class, [])->assertHasErrors();
});
