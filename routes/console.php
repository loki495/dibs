<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delivers the push-queue rows that local-authoritative writes create. Each run is a single quick pass
// (see DrainGitHubPushQueueCommand), fired every ten seconds, rather than one long-lived invocation that
// loops internally: no sleep/duration bookkeeping, and a container restart can only interrupt however long
// one pass's GitHub calls take. everyTenSeconds() works because dibs-scheduler runs `schedule:work`, which
// loops within the minute for sub-minute frequencies.
//
// withoutOverlapping's default lock TTL is 1440 minutes, far too long for a job that normally finishes in
// a couple of seconds: a container restart mid-run (SIGKILL after the stop grace period) can orphan the
// lock and silently stop the drain for up to a day. One minute is comfortably above a single pass's real
// runtime (even at the --limit=20 cap) and caps an orphaned lock at six missed ticks.
Schedule::command('todo:push:drain')->everyTenSeconds()->name('todo-github-push-drain')->withoutOverlapping(1);

// Applies DIBS_ACTIVITY_*_RETENTION_DAYS. The 60 minute lock cap keeps a lock orphaned by a container
// restart (see the drain comment above) from skipping a whole day's prune at the 24h default.
Schedule::command('activity:prune')->daily()->name('dibs-activity-prune')->withoutOverlapping(60);

// Demo mode only -- ->when() makes this a no-op on every normal (non-demo) deployment of this
// same image, same convention as config('dibs.demo_mode') everywhere else. See ResolveDemoDatabase
// and docs/demo-hosting.md.
Schedule::command('demo:cleanup')
    ->daily()
    ->name('dibs-demo-cleanup')
    ->when(fn (): bool => (bool) config('dibs.demo_mode'));
