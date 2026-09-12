<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\HeartbeatTaskClaim;
use App\Actions\ReleaseTaskClaim;
use App\Models\Issue;
use App\Models\TaskClaim;

it('claims an available task, verifying the caller pid is genuinely alive', function (): void {
    $issue = Issue::factory()->create();

    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 15);

    expect(TaskClaim::query()->count())->toBe(1)
        ->and($result['is_verified_live'])->toBeTrue()
        ->and($result['capability_token'])->toBeString()->not->toBeEmpty()
        ->and($result['claim']->agentSession->pid)->toBe(getmypid());
});

it('rejects a live claim owned by another session — two workers cannot both hold the same task', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    expect(fn () => app(ClaimTaskForAgent::class)->handle($issue, 'claude', getmypid(), 30))
        ->toThrow(DomainException::class, 'currently claimed');
    expect(TaskClaim::query()->count())->toBe(1);
});

it('replaces an expired claim automatically', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['expires_at' => now()->subMinute()]);

    $result = app(ClaimTaskForAgent::class)->handle($issue, 'claude', getmypid(), 30);

    expect(TaskClaim::query()->whereNull('released_at')->count())->toBe(1)
        ->and(TaskClaim::query()->count())->toBe(2, 'the expired claim is kept as history, not deleted')
        ->and($result['claim']->agentSession->agent_name)->toBe('claude');
});

it('takes over a claim once PID reuse proves the previous holder is actually dead', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    // Simulate the original process having died and its pid slot now belonging to someone else, by
    // corrupting the stored start time so it no longer matches the (still-running) test process.
    TaskClaim::query()->sole()->agentSession->update(['process_started_at' => now()->subDays(30)]);

    $result = app(ClaimTaskForAgent::class)->handle($issue, 'claude', getmypid(), 30);

    expect(TaskClaim::query()->whereNull('released_at')->count())->toBe(1)
        ->and(TaskClaim::query()->count())->toBe(2, 'the dead-process claim is kept as history, not deleted')
        ->and($result['claim']->agentSession->agent_name)->toBe('claude');
});

it('does not take over a claim whose liveness was never verifiable — weaker assurance is not treated as dead', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', 999_999_999, 30);
    // The claim above is stored with is_verified_live = false (that pid never existed); confirm the
    // takeover check does not treat "unverifiable" the same as "confirmed dead".
    expect(TaskClaim::query()->sole()->agentSession->is_verified_live)->toBeFalse();

    expect(fn () => app(ClaimTaskForAgent::class)->handle($issue, 'claude', getmypid(), 30))
        ->toThrow(DomainException::class, 'currently claimed');
});

it('releases a claim only when the exact capability token and pid match', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    expect(fn () => app(ReleaseTaskClaim::class)->handle($issue->id, getmypid(), 'wrong-token'))
        ->toThrow(DomainException::class, 'No live claim');
    expect(fn () => app(ReleaseTaskClaim::class)->handle($issue->id, 999_999, $result['capability_token']))
        ->toThrow(DomainException::class, 'No live claim');

    $released = app(ReleaseTaskClaim::class)->handle($issue->id, getmypid(), $result['capability_token']);
    expect($released->released_at)->not->toBeNull();
});

it('renews a claim on heartbeat and rejects renewal without the right capability', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 5);
    $originalExpiry = $result['claim']->expires_at;

    expect(fn () => app(HeartbeatTaskClaim::class)->handle($issue->id, getmypid(), 'wrong-token', 30))
        ->toThrow(DomainException::class, 'No live claim');

    $renewed = app(HeartbeatTaskClaim::class)->handle($issue->id, getmypid(), $result['capability_token'], 30);
    expect($renewed->expires_at->greaterThan($originalExpiry))->toBeTrue();
});

it('refuses to renew a claim once its process is no longer verifiably alive', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    // Corrupt the stored start time the same way the takeover test does, to simulate the process dying.
    $result['claim']->agentSession->update(['process_started_at' => now()->subDays(30)]);

    expect(fn () => app(HeartbeatTaskClaim::class)->handle($issue->id, getmypid(), $result['capability_token'], 30))
        ->toThrow(DomainException::class, 'no longer verifiably alive');
});
