<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DrainGitHubPushQueue;
use Illuminate\Console\Command;

class DrainGitHubPushQueueCommand extends Command
{
    protected $signature = 'todo:push:drain
        {--limit=20 : Maximum queue rows to attempt per pass}';

    protected $description = 'Deliver pending local writes to GitHub through the durable push queue in a single pass; scheduled every few seconds for near-real-time delivery';

    public function handle(DrainGitHubPushQueue $drain): int
    {
        $token = (string) config('github.token');
        if ($token === '') {
            $this->error('Supply GITHUB_TOKEN to drain the push queue.');

            return self::FAILURE;
        }

        $result = $drain->handle($token, (int) $this->option('limit'));
        $this->info(sprintf('Pushed %d, deferred %d, needing attention %d, waiting %d.', $result['pushed'], $result['deferred'], $result['needs_attention'], $result['waiting']));

        return self::SUCCESS;
    }
}
