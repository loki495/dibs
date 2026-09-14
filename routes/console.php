<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delivers push-queue rows created by local-authoritative writes (#37, #49). Runs alongside the
// still-synchronous GitHub-first Actions above until they're converted to enqueue instead of call.
Schedule::command('todo:push:drain')->everyMinute()->name('todo-github-push-drain')->withoutOverlapping();

// Demo mode only -- ->when() makes this a no-op on every normal (non-demo) deployment of this
// same image, same convention as config('dibs.demo_mode') everywhere else. See ResolveDemoDatabase
// and docs/demo-hosting.md.
Schedule::command('demo:cleanup')
    ->daily()
    ->name('dibs-demo-cleanup')
    ->when(fn (): bool => (bool) config('dibs.demo_mode'));
