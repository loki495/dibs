<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delivers push-queue rows created by local-authoritative writes (#37, #49). Runs alongside the
// still-synchronous GitHub-first Actions above until they're converted to enqueue instead of call.
// A single quick pass (see DrainGitHubPushQueueCommand) fired every ten seconds, rather than one
// long-lived invocation looping internally for most of a minute - simpler (no sleep/duration
// bookkeeping) and shrinks the window a container restart could catch mid-run down to however
// long one pass's GitHub calls take, instead of up to 55 seconds. everyTenSeconds() works because
// dibs-scheduler runs `schedule:work` (not cron-driven `schedule:run`), which loops internally
// within the minute for sub-minute frequencies - no extra infra needed.
//
// withoutOverlapping's own default lock TTL is 1440 minutes (24h) - way too long for a job that
// normally finishes in a couple of seconds. A container restart mid-run (SIGKILL after the stop
// grace period, which bypasses withoutOverlapping's SIGTERM-based auto-release) can orphan that
// lock, and at the default TTL the drain silently stops running for up to a day with no error -
// discovered 2026-09-20 when a stale lock blocked every run for ~8 hours after a routine restart.
// 1 minute is comfortably above a single pass's real runtime (even at the --limit=20 cap) while
// capping how long an orphaned lock can block real drains to six missed ticks at most.
Schedule::command('todo:push:drain')->everyTenSeconds()->name('todo-github-push-drain')->withoutOverlapping(1);

// Demo mode only -- ->when() makes this a no-op on every normal (non-demo) deployment of this
// same image, same convention as config('dibs.demo_mode') everywhere else. See ResolveDemoDatabase
// and docs/demo-hosting.md.
Schedule::command('demo:cleanup')
    ->daily()
    ->name('dibs-demo-cleanup')
    ->when(fn (): bool => (bool) config('dibs.demo_mode'));
