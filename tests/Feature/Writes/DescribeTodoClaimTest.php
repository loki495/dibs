<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\DescribeTodoClaim;
use App\Models\Issue;
use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;

it('returns null when the task has no live claim', function (): void {
    $issue = Issue::factory()->create();

    expect(app(DescribeTodoClaim::class)->handle($issue->id))->toBeNull();
});

it('describes a live claim without exposing the capability token', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description)->not->toBeNull()
        ->and($description['agentName'])->toBe('codex')
        ->and($description['pid'])->toBe(getmypid())
        ->and($description['isVerifiedLive'])->toBeTrue()
        ->and($description['isCurrentlyAlive'])->toBeTrue()
        ->and($description['isExpired'])->toBeFalse()
        ->and($description)->not->toHaveKey('capabilityToken')
        ->and(json_encode($description))->not->toContain('token');
});

it('reports isExpired for a claim past its lease, and null isCurrentlyAlive when liveness was never verifiable', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', 999_999_999, 30);
    TaskClaim::query()->sole()->update(['expires_at' => now()->subMinute()]);

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description['isExpired'])->toBeTrue()
        ->and($description['isVerifiedLive'])->toBeFalse()
        ->and($description['isCurrentlyAlive'])->toBeNull();
});

it('returns null once the claim is released', function (): void {
    $issue = Issue::factory()->create();
    $result = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    $result['claim']->update(['released_at' => now()]);

    expect(app(DescribeTodoClaim::class)->handle($issue->id))->toBeNull();
});

it('reports a verified claim as liveness unknown, not dead, from a process that cannot see host PIDs', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    app()->instance(LinuxProcessLiveness::class, new LinuxProcessLiveness(enabled: false));

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description['isVerifiedLive'])->toBeTrue()
        ->and($description['isCurrentlyAlive'])->toBeNull();
});

it('reports no recorded liveness before the watcher has checked the claim', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    expect(app(DescribeTodoClaim::class)->handle($issue->id)['recordedLiveness'])->toBeNull();
});

it('exposes the watcher\'s recorded liveness, and shows it where the direct check is impossible', function (bool $recordedAlive): void {
    $this->freezeSecond();
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['liveness_alive' => $recordedAlive, 'liveness_checked_at' => now()->subSeconds(20)]);
    app()->instance(LinuxProcessLiveness::class, new LinuxProcessLiveness(enabled: false));

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description['isCurrentlyAlive'])->toBeNull()
        ->and($description['recordedLiveness'])->toBe(['alive' => $recordedAlive, 'checkedAt' => now()->subSeconds(20)->toAtomString(), 'isStale' => false])
        ->and($description['displayAlive'])->toBe($recordedAlive);
})->with(['alive' => true, 'gone' => false]);

it('marks a recording older than the staleness threshold as stale and stops showing it', function (): void {
    config(['dibs.claim_liveness.stale_after_seconds' => 90]);
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['liveness_alive' => false, 'liveness_checked_at' => now()->subSeconds(91)]);
    app()->instance(LinuxProcessLiveness::class, new LinuxProcessLiveness(enabled: false));

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description['recordedLiveness']['isStale'])->toBeTrue()
        ->and($description['displayAlive'])->toBeNull();
});

it('prefers its own direct check over a recording that disagrees', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['liveness_alive' => false, 'liveness_checked_at' => now()]);

    $description = app(DescribeTodoClaim::class)->handle($issue->id);

    expect($description['isCurrentlyAlive'])->toBeTrue()
        ->and($description['recordedLiveness']['alive'])->toBeFalse()
        ->and($description['displayAlive'])->toBeTrue();
});
