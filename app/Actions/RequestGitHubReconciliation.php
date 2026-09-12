<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ReconcileGitHub;
use App\Models\SyncState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RequestGitHubReconciliation
{
    /** @return array<string, bool|int|string|null> */
    public function handle(bool $active, bool $background = false): array
    {
        $key = 'github:'.config('github.owner').'/'.config('github.repository');
        $lock = Cache::lock($key.':schedule', 10);
        if (! $lock->get()) {
            return app(DescribeGitHubSync::class)->handle();
        }

        try {
            DB::transaction(function () use ($active, $background, $key): void {
                $state = SyncState::query()->firstOrCreate(['resource_key' => $key]);
                if ($state->is_pending || $state->retry_after?->isFuture()) {
                    return;
                }

                $interval = $this->interval($active, $background);
                $lastAttempt = $state->last_attempt_at ?? $state->last_success_at;
                if ($lastAttempt?->isAfter(now()->subSeconds($interval))) {
                    return;
                }

                $state->update(['is_pending' => true, 'pending_since' => now()]);
                ReconcileGitHub::dispatch()->onConnection('database')->onQueue('github')->beforeCommit();
            });
        } finally {
            $lock->release();
        }

        return app(DescribeGitHubSync::class)->handle();
    }

    private function interval(bool $active, bool $background): int
    {
        if ($background) {
            return (int) config('todo.background_sync_seconds');
        }

        return (int) config($active ? 'todo.active_sync_seconds' : 'todo.idle_sync_seconds');
    }
}
