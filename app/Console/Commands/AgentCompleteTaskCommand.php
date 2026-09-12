<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CloseGitHubIssue;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Console\Command;

class AgentCompleteTaskCommand extends Command
{
    protected $signature = 'todo:agent:complete {issue : Local Todo issue ID}';

    protected $description = 'Complete a task through GitHub for a host-local agent as JSON';

    public function handle(CloseGitHubIssue $close): int
    {
        $issue = Issue::query()->where('is_available', true)->find($this->argument('issue'));
        $token = (string) config('github.token');
        if (! $issue instanceof Issue || $token === '') {
            $this->error('A current local Todo issue and server-side GitHub token are required.');

            return self::FAILURE;
        }
        try {
            $result = $close->handle($token, $issue);
        } catch (GitHubSyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['issue_id' => $result->id, 'state' => $result->state, 'url' => $result->url], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
