<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubMutation;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;

class TrackGitHubMutation
{
    /** @param array<string, mixed> $intent */
    public function begin(string $kind, ?Issue $issue, array $intent): GitHubMutation
    {
        return GitHubMutation::query()->create(['kind' => $kind, 'issue_id' => $issue?->id, 'status' => 'pending', 'intent_json' => $intent]);
    }

    public function confirm(GitHubMutation $mutation): void
    {
        $mutation->update(['status' => 'confirmed', 'confirmed_at' => now(), 'error' => null, 'requires_reconciliation' => false]);
    }

    public function fail(GitHubMutation $mutation, GitHubSyncException $exception): void
    {
        $ambiguous = str_contains(strtolower($exception->getMessage()), 'connection failed');
        $mutation->update(['status' => $ambiguous ? 'reconciliation_needed' : 'failed', 'error' => $exception->getMessage(),
            'requires_reconciliation' => $ambiguous, 'failed_at' => now()]);
    }
}
