<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoClaimTool;
use App\Models\Issue;
use App\Models\TaskClaim;
use Illuminate\Testing\Fluent\AssertableJson;

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

it('reports ordered batch claims with missing and unavailable errors and no tokens', function (): void {
    $free = Issue::factory()->create();
    $held = Issue::factory()->create();
    $removed = Issue::factory()->create(['is_available' => false]);
    app(ClaimTaskForAgent::class)->handle($held, 'batch-agent', getmypid(), 30);

    TodoServer::tool(DescribeTodoClaimTool::class, ['issueIds' => [$held->id, 999_999, $removed->id, $free->id, $held->id]])
        ->assertOk()->assertDontSee('capabilityToken')
        ->assertStructuredContent(function (AssertableJson $json) use ($held, $removed, $free): void {
            $json->where('items.0.id', $held->id)->where('items.0.claimed', true)
                ->where('items.0.claim.isCurrentlyAlive', true)
                ->where('items.1.error.code', 'not_found')
                ->where('items.2.id', $removed->id)->where('items.2.error.code', 'unavailable')
                ->where('items.3.id', $free->id)->where('items.3.claimed', false)->where('items.3.claim', null)
                ->where('items.4.id', $held->id)->etc();
        });
});

it('rejects invalid or ambiguous claim batch arguments', function (array $arguments, string $field): void {
    TodoServer::tool(DescribeTodoClaimTool::class, $arguments)->assertHasErrors([$field])->assertDontSee('internal server error');
})->with([
    'empty batch' => [['issueIds' => []], 'issue ids'],
    'too many ids' => [['issueIds' => range(1, 101)], 'issue ids'],
    'not a list' => [['issueIds' => ['key' => 1]], 'issue ids'],
    'invalid id' => [['issueIds' => [0]], 'issueIds.0'],
    'non numeric id' => [['issueIds' => ['no']], 'issueIds.0'],
    'both selectors' => [['issueId' => 1, 'issueIds' => [2]], 'issue id'],
]);
