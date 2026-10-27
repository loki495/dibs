<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CompleteTodoTaskTool;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\TaskClaim;

it('declares completion and temporary discard reasons in its public schema', function (): void {
    $schema = app(CompleteTodoTaskTool::class)->toArray()['inputSchema'];
    expect($schema['properties']['reason']['enum'])->toBe(['COMPLETED', 'NOT_PLANNED'])
        ->and($schema['properties']['reason']['description'])->toContain('explicit discard');
});

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

it('accepts a reason and references, closing with them and exposing them in the closing object', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(CompleteTodoTaskTool::class, [
        'issueId' => $issue->id, 'capabilityToken' => $result['capability_token'],
        'summary' => 'Shipped it.', 'reason' => 'COMPLETED', 'references' => ['#42', 'commit abc123'],
    ])->assertOk()->assertHasNoErrors()->assertSee('"reason":"COMPLETED"')->assertSee('commit abc123');

    expect($issue->fresh()->state_reason)->toBe('COMPLETED')
        ->and(Comment::query()->sole()->references)->toBe(['#42', 'commit abc123']);
});

it('rejects a reason GitHub does not recognize with a structured error, changing nothing', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);

    TodoServer::tool(CompleteTodoTaskTool::class, ['issueId' => $issue->id, 'capabilityToken' => $result['capability_token'], 'reason' => 'DONE'])
        ->assertHasErrors();

    expect($issue->fresh()->state)->toBe('OPEN');
});

it('rejects malformed reason or references arguments before calling the handler', function (array $arguments, string $field): void {
    TodoServer::tool(CompleteTodoTaskTool::class, ['issueId' => 1, 'capabilityToken' => 'x', ...$arguments])->assertHasErrors([$field]);
})->with([
    'reason not a string' => [['reason' => 3], 'reason'],
    'references not an array' => [['references' => 'x'], 'references'],
    'reference not a string' => [['references' => [['x']]], 'references.0'],
    'reference too long' => [['references' => [str_repeat('x', 501)]], 'references.0'],
    'too many references' => [['references' => array_fill(0, 21, 'x')], 'references'],
]);

it('passes result evidence through and returns it in closing.result', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $issue->labels()->attach(Label::factory()->create(['name' => 'agent task']));
    $claim = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);
    $evidence = ['commit' => 'abc1234', 'tests' => ['command' => 'composer pest', 'exit_code' => 0], 'docs' => ['README.md']];

    TodoServer::tool(CompleteTodoTaskTool::class, ['issueId' => $issue->id, 'capabilityToken' => $claim['capability_token'], 'summary' => 'Shipped it.', 'result' => $evidence])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"commit":"abc1234"');

    expect(Comment::query()->sole()->result)->toBe($evidence);
});

it('returns the guard refusal as a tool error naming the missing field, and completes with force and a real reason', function (): void {
    $issue = Issue::factory()->create(['state' => 'OPEN']);
    $issue->labels()->attach(Label::factory()->create(['name' => 'bug']));
    $claim = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid(), 30);
    $arguments = ['issueId' => $issue->id, 'capabilityToken' => $claim['capability_token'], 'summary' => 'Fixed upstream.'];

    TodoServer::tool(CompleteTodoTaskTool::class, $arguments)->assertHasErrors(['result.commit', 'force: true']);
    TodoServer::tool(CompleteTodoTaskTool::class, [...$arguments, 'force' => true, 'forceReason' => 'force'])->assertHasErrors(['force needs a real reason']);
    expect($issue->fresh()->state)->toBe('OPEN');

    TodoServer::tool(CompleteTodoTaskTool::class, [...$arguments, 'force' => true, 'forceReason' => 'fixed by the vendor upstream, no commit of ours'])
        ->assertHasNoErrors()
        ->assertSee('Guard overridden: fixed by the vendor upstream');
    expect($issue->fresh()->state)->toBe('CLOSED');
});
