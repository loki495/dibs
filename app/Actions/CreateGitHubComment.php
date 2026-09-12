<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Models\Issue;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CreateGitHubComment
{
    public function handle(#[SensitiveParameter] string $token, Issue $issue, string $body): Comment
    {
        $body = trim($body);
        if ($body === '') {
            throw new GitHubSyncException('A comment cannot be empty.');
        }
        if (! $issue->is_available || $issue->github_node_id === '') {
            throw new GitHubSyncException('The selected task is not available in the local snapshot. Refresh and try again.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($subjectId: ID!, $body: String!) { addComment(input: {subjectId: $subjectId, body: $body}) { commentEdge { node { id body url createdAt updatedAt author { login } } } } }',
            ['subjectId' => $issue->github_node_id, 'body' => $body],
        );
        $remote = $data['addComment']['commentEdge']['node'] ?? null;
        if (! is_array($remote) || ! is_string($remote['id'] ?? null) || ! is_string($remote['body'] ?? null)) {
            throw new GitHubSyncException('GitHub did not return the new comment. Refresh before retrying.');
        }

        return DB::transaction(fn (): Comment => Comment::query()->updateOrCreate(
            ['github_node_id' => $remote['id']],
            ['issue_id' => $issue->id, 'body' => $remote['body'], 'author_login' => $remote['author']['login'] ?? null,
                'url' => $remote['url'] ?? null, 'remote_created_at' => $remote['createdAt'] ?? null, 'remote_updated_at' => $remote['updatedAt'] ?? null,
                'last_synced_at' => now(), 'is_available' => true, 'last_seen_at' => now()],
        ));
    }
}
