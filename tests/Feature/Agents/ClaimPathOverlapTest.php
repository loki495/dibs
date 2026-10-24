<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\FindClaimPathOverlaps;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ClaimTodoTask;
use App\Models\Issue;
use App\Models\TaskClaim;

it('stores normalized paths on the claim and reports no overlap when none are held', function (): void {
    $result = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'codex', getmypid(), 30, ['/srv/app/Actions/', '/srv/app/Models/TaskClaim.php']);

    expect($result['claim']->fresh()->paths)->toBe(['/srv/app/Actions', '/srv/app/Models/TaskClaim.php'])
        ->and($result['overlaps'])->toBe([]);
});

it('claims without paths exactly as before', function (): void {
    $result = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'codex', getmypid(), 30);

    expect($result['claim']->fresh()->paths)->toBeNull()->and($result['overlaps'])->toBe([]);
});

it('reports a live claim whose path is the same, an ancestor or a descendant, but not a sibling prefix', function (): void {
    $other = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($other, 'claude:a:1', getmypid(), 30, ['/srv/app/Actions']);

    foreach (['/srv/app/Actions', '/srv/app/Actions/Claim.php', '/srv/app'] as $path) {
        $result = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'codex', getmypid(), 30, [$path]);
        expect($result['overlaps'])->toBe([['issueId' => $other->id, 'agentName' => 'claude:a:1', 'paths' => ['/srv/app/Actions']]], $path);
        $result['claim']->update(['released_at' => now()]);
    }

    $sibling = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'codex', getmypid(), 30, ['/srv/app/ActionsExtra']);
    expect($sibling['overlaps'])->toBe([]);
});

it('ignores expired, released and dead-process claims when looking for overlaps', function (): void {
    $expired = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'a', getmypid(), 30, ['/srv/x']);
    $expired['claim']->update(['expires_at' => now()->subMinute()]);
    $released = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'b', getmypid(), 30, ['/srv/x']);
    $released['claim']->update(['released_at' => now()]);
    $dead = app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'c', getmypid(), 30, ['/srv/x']);
    $dead['claim']->agentSession->update(['process_started_at' => now()->subDays(30)]);

    expect(app(FindClaimPathOverlaps::class)->handle(['/srv/x/file.php']))->toBe([]);
});

it('can exclude the caller by the conversation id ending its agent name', function (): void {
    app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'claude:swift-otter:abc-123', getmypid(), 30, ['/srv/x']);

    expect(app(FindClaimPathOverlaps::class)->handle(['/srv/x/f'], exceptSession: 'abc-123'))->toBe([])
        ->and(app(FindClaimPathOverlaps::class)->handle(['/srv/x/f'], exceptSession: 'other'))->toHaveCount(1);
});

it('refuses malformed paths and claims nothing', function (string $bad): void {
    expect(fn () => app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'codex', getmypid(), 30, [$bad]))
        ->toThrow(DomainException::class, 'absolute');
    expect(TaskClaim::query()->count())->toBe(0);
})->with(['relative' => 'app/Actions', 'dot-dot' => '/srv/../etc', 'empty' => '  ', 'nul' => "/srv/a\0b"]);

it('refuses more than the allowed number of paths', function (): void {
    $paths = array_map(fn (int $i): string => "/srv/p{$i}", range(1, 21));

    expect(fn () => app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'codex', getmypid(), 30, $paths))
        ->toThrow(DomainException::class, 'at most');
});

it('returns overlaps from todo_claim and a handled error for a malformed path', function (): void {
    app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), 'claude:a:1', getmypid(), 30, ['/srv/x']);
    $issue = Issue::factory()->create();

    TodoServer::tool(ClaimTodoTask::class, ['issueId' => $issue->id, 'agentName' => 'codex', 'paths' => ['/srv/x/y.php']])
        ->assertOk()->assertHasNoErrors()->assertSee('overlaps')->assertSee('claude:a:1');

    TodoServer::tool(ClaimTodoTask::class, ['issueId' => Issue::factory()->create()->id, 'agentName' => 'codex', 'paths' => ['relative']])
        ->assertHasErrors(['absolute']);
});

it('lists overlapping live claims through the todo:agent:claims command', function (): void {
    $held = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($held, 'claude:a:sess-1', getmypid(), 30, ['/srv/x']);

    $this->artisan('todo:agent:claims', ['--path' => ['/srv/x/f.php']])->expectsOutputToContain((string) $held->id)->assertExitCode(0);
    $this->artisan('todo:agent:claims', ['--path' => ['/srv/x/f.php'], '--except-session' => 'sess-1'])->expectsOutput('[]')->assertExitCode(0);
    $this->artisan('todo:agent:claims', ['--path' => ['relative']])->assertExitCode(1);
});

it('claims through todo:agent:claim with --path options', function (): void {
    $issue = Issue::factory()->create();

    $this->artisan('todo:agent:claim', ['issue' => $issue->id, '--agent' => 'codex', '--pid' => (string) getmypid(), '--path' => ['/srv/x']])->assertExitCode(0);

    expect(TaskClaim::query()->sole()->paths)->toBe(['/srv/x']);
});
