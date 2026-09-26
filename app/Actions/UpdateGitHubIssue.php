<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class UpdateGitHubIssue
{
    public function handle(#[SensitiveParameter] string $token, Issue $issue, string $title, ?string $body): Issue
    {
        $title = trim($title);
        if ($title === '') {
            throw new GitHubSyncException('A task title is required.');
        }
        if (! $issue->is_available || $issue->github_node_id === '') {
            throw new GitHubSyncException('The selected task is not available in the local snapshot. Refresh and try again.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($issueId: ID!, $title: String!, $body: String) { updateIssue(input: {id: $issueId, title: $title, body: $body}) { issue { id title body state stateReason closedAt url updatedAt } } }',
            ['issueId' => $issue->github_node_id, 'title' => $title, 'body' => $body],
        );
        $remote = $data['updateIssue']['issue'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $issue->github_node_id || ! is_string($remote['title'] ?? null)) {
            throw new GitHubSyncException('GitHub did not confirm the task update. Refresh before retrying.');
        }

        return DB::transaction(function () use ($issue, $remote): Issue {
            $issue->update(['title' => $remote['title'], 'body' => $remote['body'] ?? null, 'state' => $remote['state'] ?? $issue->state,
                'state_reason' => $remote['stateReason'] ?? null, 'closed_at' => $remote['closedAt'] ?? $issue->closed_at, 'url' => $remote['url'] ?? $issue->url,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $issue->refresh();
        });
    }
}
