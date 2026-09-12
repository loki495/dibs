<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncGitHub;
use App\Models\SyncState;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileGitHub implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 840;

    public function handle(SyncGitHub $sync): void
    {
        try {
            $token = (string) config('github.token');
            if ($token === '') {
                $this->clearPending('Automatic synchronization requires a server-side GitHub token.');

                return;
            }
            $sync->handle($token, comments: false);
        } catch (GitHubSyncException) {
            // SyncGitHub records the actionable failure and retry window.
        } finally {
            $this->clearPending();
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->clearPending();
    }

    private function clearPending(?string $error = null): void
    {
        $state = SyncState::query()->where('resource_key', 'github:'.config('github.owner').'/'.config('github.repository'))->first();
        if ($state === null) {
            return;
        }
        $values = ['is_pending' => false, 'pending_since' => null];
        if ($error !== null) {
            $values['last_error'] = $error;
            $values['retry_after'] = now()->addMinute();
        }
        $state->update($values);
    }
}
