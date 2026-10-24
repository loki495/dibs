<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ReleaseTodoTask;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\TaskClaim;

it('exposes the tool under the todo_release name', function (): void {
    expect(app(ReleaseTodoTask::class)->name())->toBe('todo_release');
});

it('releases a live claim held by the calling process', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(ReleaseTodoTask::class, ['issueId' => $issue->id, 'capabilityToken' => $result['capability_token'], 'left' => 'Write the tests; the action is done.'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"released":true');

    expect(TaskClaim::query()->sole()->released_at)->not->toBeNull()
        ->and(Comment::query()->where('issue_id', $issue->id)->sole()->body)->toContain('Write the tests; the action is done.');
});

it('refuses to release a claim the caller does not hold', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(ReleaseTodoTask::class, ['issueId' => $issue->id, 'capabilityToken' => 'wrong-token', 'left' => 'Next: finish it.'])
        ->assertHasErrors(['No live claim']);

    expect(TaskClaim::query()->sole()->released_at)->toBeNull();
});

it('rejects a call missing required arguments', function (): void {
    TodoServer::tool(ReleaseTodoTask::class, [])->assertHasErrors();
});

it('refuses a blank left and keeps the claim, posting nothing', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(ReleaseTodoTask::class, ['issueId' => $issue->id, 'capabilityToken' => $result['capability_token'], 'left' => '   '])
        ->assertHasErrors(['left']);

    expect(TaskClaim::query()->sole()->released_at)->toBeNull()
        ->and(Comment::query()->count())->toBe(0);
});
