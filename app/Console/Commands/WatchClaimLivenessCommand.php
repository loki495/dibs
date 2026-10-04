<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecordClaimLiveness;
use App\Services\Process\LinuxProcessLiveness;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Throwable;

/**
 * The app container's main process. It runs where host PIDs are visible and records each live
 * claim's process liveness so the web container, which can't see them, can show it.
 */
class WatchClaimLivenessCommand extends Command
{
    protected $signature = 'dibs:claims:watch
        {--interval= : Seconds between checks (default: DIBS_CLAIM_LIVENESS_INTERVAL, 30)}
        {--once : Check once and exit}';

    protected $description = 'Periodically check every live claim\'s process and record whether it is alive, for the web UI';

    private bool $stopping = false;

    public function handle(RecordClaimLiveness $record, LinuxProcessLiveness $liveness, ConcurrencyErrorDetector $concurrency): int
    {
        $interval = max(1, (int) ($this->option('interval') ?? config('dibs.claim_liveness.watch_interval_seconds')));
        $once = (bool) $this->option('once');
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        if (! $liveness->isVerifiable()) {
            $this->warn('Process liveness cannot be checked here (DIBS_PROCESS_LIVENESS=false or no host /proc); nothing will be recorded.');
        }

        while (! $this->stopping) {
            $this->checkOnce($record, $concurrency);
            if ($once) {
                break;
            }
            // One-second steps so a SIGTERM ends the wait within a second even if it lands between sleeps.
            for ($waited = 0; $waited < $interval && ! $this->stopping; $waited++) {
                sleep(1);
            }
        }

        return self::SUCCESS;
    }

    private function checkOnce(RecordClaimLiveness $record, ConcurrencyErrorDetector $concurrency): void
    {
        try {
            $counts = $record->handle();
            $this->line(sprintf('Checked claims: %d alive, %d gone, %d not checkable.', $counts['alive'], $counts['dead'], $counts['skipped']), verbosity: 'v');
        } catch (Throwable $e) {
            // A busy SQLite writer (the scheduler's push-queue drain, an agent's write) is expected now and
            // then; the next pass retries. Anything else is reported in full, then the loop carries on so a
            // bad pass doesn't stop the container that agents exec into.
            if ($concurrency->causedByConcurrencyError($e)) {
                $this->warn('Database busy; will retry on the next check.');

                return;
            }
            report($e);
            $this->error('Claim liveness check failed: '.$e->getMessage());
        }
    }
}
