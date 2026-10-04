<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\RecordClaimLiveness;
use App\Models\AgentSession;
use App\Models\Issue;
use App\Models\TaskClaim;
use App\Services\Process\LinuxProcessLiveness;
use Illuminate\Console\Signals;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;

/** Laravel only installs command signal traps outside unit tests; these tests exercise the real trap. */
function enableSignalTraps(): void
{
    Signals::resolveAvailabilityUsing(fn (): bool => extension_loaded('pcntl'));
}

afterEach(function (): void {
    Signals::resolveAvailabilityUsing(fn (): bool => app()->runningInConsole() && ! app()->runningUnitTests() && extension_loaded('pcntl'));
});

function claimFor(string $agent, int $pid = 0): TaskClaim
{
    return app(ClaimTaskForAgent::class)->handle(Issue::factory()->create(), $agent, $pid > 0 ? $pid : getmypid(), 30)['claim'];
}

function lockedDatabaseException(): QueryException
{
    return new QueryException('sqlite', 'update "task_claims" set "liveness_alive" = ?', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
}

it('records a live claim as alive and a claim whose process is gone as dead, with the check time', function (): void {
    $this->freezeSecond();
    $alive = claimFor('alive-agent');
    $dead = claimFor('dead-agent');
    // Same pid, different start time: the process this claim was bound to no longer exists.
    AgentSession::query()->whereKey($dead->agent_session_id)->update(['process_started_at' => now()->subDays(30)]);

    $this->artisan('dibs:claims:watch', ['--once' => true])->assertSuccessful();

    expect($alive->refresh()->liveness_alive)->toBeTrue()
        ->and($alive->liveness_checked_at->equalTo(now()))->toBeTrue()
        ->and($dead->refresh()->liveness_alive)->toBeFalse()
        ->and($dead->liveness_checked_at->equalTo(now()))->toBeTrue()
        ->and($dead->released_at)->toBeNull();
});

it('updates the check time on every pass', function (): void {
    $claim = claimFor('codex');
    $this->artisan('dibs:claims:watch', ['--once' => true])->assertSuccessful();
    $first = $claim->refresh()->liveness_checked_at;

    $this->travel(45)->seconds();
    $this->artisan('dibs:claims:watch', ['--once' => true])->assertSuccessful();

    expect($claim->refresh()->liveness_checked_at->greaterThan($first))->toBeTrue();
});

it('skips expired, released and never-verified claims', function (): void {
    $expired = claimFor('expired');
    $expired->update(['expires_at' => now()->subMinute()]);
    $released = claimFor('released');
    $released->update(['released_at' => now()]);
    $unverified = claimFor('unverified', 999_999_999);

    $this->artisan('dibs:claims:watch', ['--once' => true])->assertSuccessful();

    foreach ([$expired, $released, $unverified] as $claim) {
        expect($claim->refresh()->liveness_alive)->toBeNull()
            ->and($claim->liveness_checked_at)->toBeNull();
    }
});

it('records nothing and says so where host processes cannot be checked', function (): void {
    $claim = claimFor('codex');
    app()->instance(LinuxProcessLiveness::class, new LinuxProcessLiveness(enabled: false));

    $this->artisan('dibs:claims:watch', ['--once' => true])
        ->expectsOutputToContain('Process liveness cannot be checked here')
        ->assertSuccessful();

    expect($claim->refresh()->liveness_checked_at)->toBeNull();
});

it('survives a locked database and carries on, then stops promptly on SIGTERM', function (): void {
    enableSignalTraps();
    Exceptions::fake();
    $calls = 0;
    $this->mock(RecordClaimLiveness::class)->shouldReceive('handle')->twice()->andReturnUsing(function () use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            throw lockedDatabaseException();
        }
        posix_kill(getmypid(), SIGTERM);

        return ['alive' => 0, 'dead' => 0, 'skipped' => 0];
    });

    $started = microtime(true);
    $this->artisan('dibs:claims:watch', ['--interval' => 1])
        ->expectsOutputToContain('Database busy; will retry on the next check.')
        ->assertSuccessful();

    expect($calls)->toBe(2)
        ->and(microtime(true) - $started)->toBeLessThan(5);
    Exceptions::assertNothingReported();
});

it('stops within about a second of SIGTERM even mid-wait on a long interval', function (): void {
    enableSignalTraps();
    $this->mock(RecordClaimLiveness::class)->shouldReceive('handle')->once()->andReturnUsing(function (): array {
        pcntl_alarm(1);

        return ['alive' => 0, 'dead' => 0, 'skipped' => 0];
    });
    pcntl_signal(SIGALRM, fn () => posix_kill(getmypid(), SIGTERM));

    $started = microtime(true);
    $this->artisan('dibs:claims:watch', ['--interval' => 300])->assertSuccessful();

    pcntl_signal(SIGALRM, SIG_DFL);
    expect(microtime(true) - $started)->toBeLessThan(5);
});

it('reports an unexpected error instead of swallowing it, and keeps the process alive', function (): void {
    Exceptions::fake();
    $this->mock(RecordClaimLiveness::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('boom'));

    $this->artisan('dibs:claims:watch', ['--once' => true])
        ->expectsOutputToContain('Claim liveness check failed: boom')
        ->assertSuccessful();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'boom');
});

it('does not overwrite a claim released between the read and the write', function (): void {
    $claim = claimFor('codex');
    $liveness = Mockery::mock(LinuxProcessLiveness::class);
    $liveness->shouldReceive('currentlyAlive')->andReturnUsing(function () use ($claim): bool {
        $claim->update(['released_at' => now()]);

        return false;
    });

    (new RecordClaimLiveness($liveness))->handle();

    expect($claim->refresh()->liveness_checked_at)->toBeNull();
});
