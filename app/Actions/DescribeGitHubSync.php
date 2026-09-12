<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SyncState;

class DescribeGitHubSync
{
    /** @return array<string, bool|int|string|null> */
    public function handle(): array
    {
        $state = SyncState::query()->where('resource_key', 'github:'.config('github.owner').'/'.config('github.repository'))->first();
        $generation = $state instanceof SyncState ? $state->completed_reconciliation_generation : 0;
        $isPending = $state instanceof SyncState && $state->is_pending;
        $lastSuccess = $state?->last_success_at;
        $isStale = $lastSuccess === null || $lastSuccess->lte(now()->subSeconds((int) config('todo.stale_seconds')));
        $version = sha1(implode('|', [(string) $generation, (string) $lastSuccess?->getTimestamp(), (string) $state?->last_attempt_at?->getTimestamp(), (string) $state?->retry_after?->getTimestamp(), (string) $state?->last_error, (string) (int) $isPending]));

        return [
            'version' => $version,
            'generation' => $generation,
            'isPending' => $isPending,
            'isStale' => $isStale,
            'lastSuccessAt' => $lastSuccess?->toIso8601String(),
            'lastError' => $state?->last_error,
            'retryAfter' => $state?->retry_after?->toIso8601String(),
            'activePollSeconds' => (int) config('todo.active_poll_seconds'),
            'idlePollSeconds' => (int) config('todo.idle_poll_seconds'),
        ];
    }
}
