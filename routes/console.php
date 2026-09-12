<?php

use App\Actions\RequestGitHubReconciliation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn (): array => app(RequestGitHubReconciliation::class)->handle(active: false, background: true))->everyMinute()->name('todo-github-reconciliation')->withoutOverlapping();
