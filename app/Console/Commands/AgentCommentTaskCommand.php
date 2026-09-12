<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateGitHubComment;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Console\Command;

class AgentCommentTaskCommand extends Command
{
    protected $signature = 'todo:agent:comment {issue : Local Todo issue ID} {body : Comment body}';

    protected $description = 'Add a GitHub-first task comment for a host-local agent as JSON';

    public function handle(CreateGitHubComment $comment): int
    {
        $issue = Issue::query()->where('is_available', true)->find($this->argument('issue'));
        $token = (string) config('github.token');
        if (! $issue instanceof Issue || $token === '') {
            $this->error('A current local Todo issue and server-side GitHub token are required.');

            return self::FAILURE;
        }
        try {
            $result = $comment->handle($token, $issue, $this->argument('body'));
        } catch (GitHubSyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['comment_id' => $result->id, 'issue_id' => $result->issue_id, 'url' => $result->url], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
