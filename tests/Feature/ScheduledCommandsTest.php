<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('caps the push-queue drain overlap lock well below the 24-hour default', function (): void {
    // Regression test for 2026-09-20: a container restart mid-run orphaned this lock at the
    // withoutOverlapping default (1440 minutes), silently blocking every drain for ~8 hours
    // with no error. Keep this well above the command's real runtime but far below the default.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'todo:push:drain'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBeLessThan(1440)
        ->and($event->expiresAt)->toBeGreaterThanOrEqual(1);
});

it('runs the push-queue drain on a sub-minute cadence for near-real-time delivery', function (): void {
    // Only works because dibs-scheduler runs `schedule:work`, not cron-driven `schedule:run` -
    // see the comment in routes/console.php. Sub-minute frequencies keep the standard
    // everyMinute() cron expression; the actual repeat interval lives in repeatSeconds instead.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'todo:push:drain'));

    expect($event->repeatSeconds)->toBe(10);
});
