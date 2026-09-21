<?php

declare(strict_types=1);

use App\Actions\ApplyGitHubSnapshot;
use App\Actions\SyncGitHub;
use App\Models\Issue;
use App\Models\SyncState;
use App\Services\GitHub\FetchGitHubSnapshot;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\Cache;

it('preserves a successful snapshot and records a failed fetch with backoff', function (): void {
    $issue = Issue::factory()->create(['title' => 'Keep this']);
    $fetch = Mockery::mock(FetchGitHubSnapshot::class);
    $fetch->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub HTTP 429', 120));
    $sync = new SyncGitHub($fetch, app(ApplyGitHubSnapshot::class));
    expect(fn () => $sync->handle('test-token'))->toThrow(GitHubSyncException::class, 'GitHub HTTP 429');
    expect($issue->fresh()->title)->toBe('Keep this')
        ->and(SyncState::query()->sole()->last_success_at)->toBeNull()
        ->and(SyncState::query()->sole()->last_error)->toBe('GitHub HTTP 429')
        ->and(SyncState::query()->sole()->retry_after->isFuture())->toBeTrue();
    expect(fn () => $sync->handle('test-token'))->toThrow(GitHubSyncException::class, 'retry window');
});

it('applies a successful snapshot and records success with an incremented reconciliation generation', function (): void {
    $fetch = Mockery::mock(FetchGitHubSnapshot::class);
    $fetch->shouldReceive('handle')->once()->andReturn(['repository' => ['id' => 'R_1'], 'labels' => [], 'issues' => [], 'projects' => []]);
    $apply = Mockery::mock(ApplyGitHubSnapshot::class);
    $apply->shouldReceive('handle')->once()->with(['repository' => ['id' => 'R_1'], 'labels' => [], 'issues' => [], 'projects' => []]);

    (new SyncGitHub($fetch, $apply))->handle('test-token');

    $state = SyncState::query()->sole();
    expect($state->last_success_at)->not->toBeNull()
        ->and($state->last_error)->toBeNull()
        ->and($state->retry_after)->toBeNull()
        ->and($state->completed_reconciliation_generation)->toBe(1);
});

it('rejects applying a snapshot fetched after the sync lock already expired', function (): void {
    $fetch = Mockery::mock(FetchGitHubSnapshot::class);
    $fetch->shouldReceive('handle')->once()->andReturnUsing(function () {
        // Simulate the lock expiring mid-fetch (e.g. a very slow GitHub response outliving the
        // lock's own TTL) by releasing it out from under the in-progress sync.
        Cache::lock('github:'.config('github.owner').'/'.config('github.repository'), 900)->forceRelease();

        return ['repository' => ['id' => 'R_1'], 'labels' => [], 'issues' => [], 'projects' => []];
    });
    $apply = Mockery::mock(ApplyGitHubSnapshot::class);
    $apply->shouldNotReceive('handle');

    expect(fn () => (new SyncGitHub($fetch, $apply))->handle('test-token'))
        ->toThrow(GitHubSyncException::class, 'lock expired before import');
    expect(SyncState::query()->sole()->last_error)->toContain('lock expired before import');
});

it('does not fetch while another sync owns the lock', function (): void {
    $lock = Cache::lock('github:'.config('github.owner').'/'.config('github.repository'), 900);
    $lock->get();
    $fetch = Mockery::mock(FetchGitHubSnapshot::class);
    $fetch->shouldNotReceive('handle');
    try {
        expect(fn () => (new SyncGitHub($fetch, app(ApplyGitHubSnapshot::class)))->handle('test-token'))
            ->toThrow(GitHubSyncException::class, 'already running');
    } finally {
        $lock->release();
    }
});

it('fails clearly when no GitHub credential is provided', function (): void {
    config(['github.token' => null]);
    $this->artisan('todo:sync')->expectsOutput('Supply GITHUB_TOKEN or use the scripts/github-pull wrapper with your gh login.')->assertFailed();
});
