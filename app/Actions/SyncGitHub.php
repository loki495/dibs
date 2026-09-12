<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SyncState;
use App\Services\GitHub\FetchGitHubSnapshot;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;
use Throwable;

class SyncGitHub
{
    public function __construct(private readonly FetchGitHubSnapshot $fetch, private readonly ApplyGitHubSnapshot $apply) {}

    public function handle(#[SensitiveParameter] string $token, bool $comments = false): void
    {
        $key = 'github:'.config('github.owner').'/'.config('github.repository');
        $lock = Cache::lock($key, 900);
        if (! $lock->get()) {
            throw new GitHubSyncException('A GitHub sync is already running.');
        }
        try {
            $state = SyncState::query()->firstOrCreate(['resource_key' => $key]);
            if ($state->retry_after?->isFuture()) {
                throw new GitHubSyncException('GitHub sync is waiting for its retry window.');
            }
            $state->update(['last_attempt_at' => now()]);
            try {
                $snapshot = $this->fetch->handle(new GitHubClient($token), $comments);
                if (! $lock instanceof Lock || ! $lock->isOwnedByCurrentProcess()) {
                    throw new GitHubSyncException('Sync lock expired before import; retry the snapshot.');
                }
                $this->apply->handle($snapshot);
                $state->update(['last_success_at' => now(), 'last_error' => null, 'retry_after' => null,
                    'completed_reconciliation_generation' => ($state->completed_reconciliation_generation ?? 0) + 1]);
            } catch (Throwable $exception) {
                $state->update(['last_error' => $exception instanceof GitHubSyncException ? $exception->getMessage() : 'Unexpected sync failure; inspect the application error log.',
                    'retry_after' => now()->addSeconds($exception instanceof GitHubSyncException ? $exception->retrySeconds : 60)]);
                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }
}
