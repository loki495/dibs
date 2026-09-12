<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class UpdateGitHubComment
{
    public function handle(#[SensitiveParameter] string $token, Comment $comment, string $body): Comment
    {
        $body = trim($body);
        if ($body === '') {
            throw new GitHubSyncException('A comment cannot be empty.');
        }
        if (! $comment->is_available || $comment->github_node_id === '') {
            throw new GitHubSyncException('The selected comment is not available in the local snapshot. Refresh and try again.');
        }

        $data = (new GitHubClient($token))->query(
            'mutation($commentId: ID!, $body: String!) { updateIssueComment(input: {id: $commentId, body: $body}) { issueComment { id body url createdAt updatedAt author { login } } } }',
            ['commentId' => $comment->github_node_id, 'body' => $body],
        );
        $remote = $data['updateIssueComment']['issueComment'] ?? null;
        if (! is_array($remote) || ($remote['id'] ?? null) !== $comment->github_node_id || ! is_string($remote['body'] ?? null)) {
            throw new GitHubSyncException('GitHub did not confirm the comment update. Refresh before retrying.');
        }

        return DB::transaction(function () use ($comment, $remote): Comment {
            $comment->update(['body' => $remote['body'], 'author_login' => $remote['author']['login'] ?? $comment->author_login,
                'url' => $remote['url'] ?? $comment->url, 'remote_created_at' => $remote['createdAt'] ?? $comment->remote_created_at,
                'remote_updated_at' => $remote['updatedAt'] ?? null, 'last_synced_at' => now(), 'last_seen_at' => now()]);

            return $comment->refresh();
        });
    }
}
