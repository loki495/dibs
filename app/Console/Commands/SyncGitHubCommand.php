<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncGitHub;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Console\Command;

class SyncGitHubCommand extends Command
{
    protected $signature = 'todo:sync {--token-stdin : Read a GitHub token from standard input} {--comments : Also reconcile all issue comments}';

    protected $description = 'Pull a complete read-only snapshot of the configured GitHub repository and Projects';

    public function handle(SyncGitHub $sync): int
    {
        $token = $this->option('token-stdin') ? trim((string) stream_get_contents(STDIN)) : (string) config('github.token');
        if ($token === '') {
            $this->error('Supply GITHUB_TOKEN or use the scripts/github-pull wrapper with your gh login.');

            return self::FAILURE;
        }
        try {
            $sync->handle($token, (bool) $this->option('comments'));
        } catch (GitHubSyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('GitHub snapshot imported successfully.');

        return self::SUCCESS;
    }
}
