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
