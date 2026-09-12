<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class DeleteGitHubIssue
{
    public function handle(#[SensitiveParameter] string $token, Issue $issue): Issue
    {
        if (! $issue->is_available || $issue->github_node_id === '') {
            throw new GitHubSyncException('The selected task is not available in the local snapshot. Refresh and try again.');
        }
        if ($issue->children()->where('is_available', true)->exists()) {
            throw new GitHubSyncException('Detach or delete this task’s children before deleting it.');
        }

        (new GitHubClient($token))->query(
            'mutation($issueId: ID!) { deleteIssue(input: {issueId: $issueId}) { clientMutationId } }',
            ['issueId' => $issue->github_node_id],
        );

        return DB::transaction(function () use ($issue): Issue {
            $issue->update(['is_available' => false, 'last_seen_at' => now(), 'last_synced_at' => now()]);

            return $issue->refresh();
        });
    }
}
