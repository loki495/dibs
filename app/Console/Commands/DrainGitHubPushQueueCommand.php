<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DrainGitHubPushQueue;
use Illuminate\Console\Command;

class DrainGitHubPushQueueCommand extends Command
{
    protected $signature = 'todo:push:drain
        {--limit=20 : Maximum queue rows to attempt per pass}
        {--once : Run a single pass instead of looping for the rest of the scheduled slot}
        {--interval=5 : Seconds to sleep between passes when looping}
        {--duration=55 : Seconds to keep looping before exiting, leaving room before the next scheduled tick}';

    protected $description = 'Deliver pending local writes to GitHub through the durable push queue, looping between scheduler ticks for near-real-time delivery';

    public function handle(DrainGitHubPushQueue $drain): int
    {
        $token = (string) config('github.token');
        if ($token === '') {
            $this->error('Supply GITHUB_TOKEN to drain the push queue.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $interval = max(1, (int) $this->option('interval'));
        $deadline = now()->addSeconds(max(0, (int) $this->option('duration')));

        do {
            $result = $drain->handle($token, $limit);
            $this->info(sprintf('Pushed %d, deferred %d, needing attention %d, waiting %d.', $result['pushed'], $result['deferred'], $result['needs_attention'], $result['waiting']));
            if ($this->option('once') || now()->gte($deadline)) {
                break;
            }
            sleep($interval);
        } while (now()->lt($deadline));

        return self::SUCCESS;
    }
}
