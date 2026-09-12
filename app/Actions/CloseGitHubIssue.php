<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CloseGitHubIssue
{
    public function handle(#[SensitiveParameter] string $token, Issue $issue): Issue
    {
        if (! $issue->is_available || $issue->github_node_id === '') {
            throw new GitHubSyncException('The selected task is not available in the local snapshot. Refresh and try again.');
        }
        if ($issue->state === 'CLOSED') {
            throw new GitHubSyncException('This task is already closed.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($issueId: ID!) { closeIssue(input: {issueId: $issueId}) { issue { id state stateReason updatedAt } } }',
            ['issueId' => $issue->github_node_id],
        );
        $remote = $data['closeIssue']['issue'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $issue->github_node_id || ($remote['state'] ?? null) !== 'CLOSED') {
            throw new GitHubSyncException('GitHub did not confirm the task was closed. Refresh before retrying.');
        }

        return DB::transaction(function () use ($issue, $remote): Issue {
            $issue->update(['state' => 'CLOSED', 'state_reason' => $remote['stateReason'] ?? null,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $issue->refresh();
        });
    }
}
