<?php

namespace App\Providers;

use App\Services\Activity\ActivityContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ActivityContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustProxies::at(config('dibs.trusted_proxies'));

        // The first command wins: a command that calls another (migrate:fresh inside a seeder
        // build) must keep one request id and one label for everything it records.
        Event::listen(function (CommandStarting $event): void {
            $context = $this->app->make(ActivityContext::class);

            if (! $context->hasBegun()) {
                $context->beginConsole($event->command !== '' ? $event->command : 'artisan');
            }
        });

        Event::listen(function (ScheduledTaskStarting $event): void {
            $this->app->make(ActivityContext::class)->beginSystem($event->task->description ?? 'scheduled task');
        });
    }
}
