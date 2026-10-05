<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubPushQueueItem;
use App\Support\GitHubMirror;

class EnqueueGitHubPush
{
    /**
     * Record an intended GitHub write for the scheduled push worker to deliver.
     * Retry-safe: calling again with the same idempotency key refreshes a still-pending
     * row's payload instead of duplicating it, and leaves an already-pushed row alone.
     * Local-only (no repository imported from GitHub yet) nothing is queued and null is returned.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $operation, string $targetType, ?int $targetId, array $payload, string $idempotencyKey): ?GitHubPushQueueItem
    {
        if (! GitHubMirror::mirrored()) {
            return null;
        }
        $existing = GitHubPushQueueItem::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof GitHubPushQueueItem && $existing->status === 'pushed') {
            return $existing;
        }
        if ($existing instanceof GitHubPushQueueItem) {
            // A row brought back from needs_attention starts a fresh retry budget; a still-pending
            // row keeps its backoff.
            $retry = $existing->status === 'pending' ? [] : ['attempts' => 0, 'next_attempt_at' => null];
            $existing->update(['operation' => $operation, 'target_type' => $targetType, 'target_id' => $targetId, 'payload' => $payload, 'status' => 'pending', 'last_error' => null, ...$retry]);

            return $existing->refresh();
        }

        return GitHubPushQueueItem::query()->create([
            'operation' => $operation, 'target_type' => $targetType, 'target_id' => $targetId,
            'payload' => $payload, 'status' => 'pending', 'idempotency_key' => $idempotencyKey,
        ]);
    }
}
