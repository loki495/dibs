<?php

declare(strict_types=1);

use App\Models\ChangeLog;
use App\Models\McpCallLog;
use Illuminate\Console\Scheduling\Schedule;

it('prunes through the activity:prune command and reports how many rows went', function (): void {
    config(['dibs.activity.mcp_retention_days' => 30, 'dibs.activity.change_retention_days' => 30]);
    McpCallLog::factory()->count(3)->create(['created_at' => now()->subDays(31)]);
    ChangeLog::factory()->create(['created_at' => now()->subDays(31)]);
    ChangeLog::factory()->create(['created_at' => now()->subDays(1)]);

    $this->artisan('activity:prune')
        ->expectsOutputToContain('Pruned 3 MCP call log row(s) and 1 change log row(s).')
        ->assertExitCode(0);

    expect(McpCallLog::query()->count())->toBe(0)
        ->and(ChangeLog::query()->count())->toBe(1);
});

it('says so when retention is disabled for both logs and deletes nothing', function (): void {
    config(['dibs.activity.mcp_retention_days' => 0, 'dibs.activity.change_retention_days' => 0]);
    McpCallLog::factory()->create(['created_at' => now()->subYears(2)]);

    $this->artisan('activity:prune')
        ->expectsOutputToContain('Retention is disabled for both logs')
        ->assertExitCode(0);

    expect(McpCallLog::query()->count())->toBe(1);
});

it('is scheduled daily with a short overlap lock', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'activity:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBeLessThan(1440);
});
