<?php

declare(strict_types=1);

use App\Actions\PruneActivityLog;
use App\Models\ChangeLog;
use App\Models\McpCallLog;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    config(['dibs.activity.mcp_retention_days' => 30, 'dibs.activity.change_retention_days' => 30]);
});

it('deletes rows older than the retention window and keeps newer ones, per log', function (): void {
    config(['dibs.activity.mcp_retention_days' => 7, 'dibs.activity.change_retention_days' => 90]);
    $oldMcp = McpCallLog::factory()->create(['created_at' => now()->subDays(8)]);
    $freshMcp = McpCallLog::factory()->create(['created_at' => now()->subDays(6)]);
    $changeInsideItsOwnWindow = ChangeLog::factory()->create(['created_at' => now()->subDays(60)]);
    $oldChange = ChangeLog::factory()->create(['created_at' => now()->subDays(91)]);

    $deleted = app(PruneActivityLog::class)->handle();

    expect($deleted)->toBe(['mcp' => 1, 'changes' => 1])
        ->and(McpCallLog::query()->pluck('id')->all())->toBe([$freshMcp->id])
        ->and(ChangeLog::query()->pluck('id')->all())->toBe([$changeInsideItsOwnWindow->id])
        ->and(McpCallLog::query()->whereKey($oldMcp->id)->exists())->toBeFalse()
        ->and(ChangeLog::query()->whereKey($oldChange->id)->exists())->toBeFalse();
});

it('keeps a row created exactly at the cutoff and deletes one a second older', function (): void {
    $atCutoff = McpCallLog::factory()->create(['created_at' => now()->subDays(30)]);
    $justOlder = McpCallLog::factory()->create(['created_at' => now()->subDays(30)->subSecond()]);

    app(PruneActivityLog::class)->handle();

    expect(McpCallLog::query()->pluck('id')->all())->toBe([$atCutoff->id])
        ->and(McpCallLog::query()->whereKey($justOlder->id)->exists())->toBeFalse();
});

it('keeps a log forever when its retention is 0 or negative, without affecting the other log', function (int $days): void {
    config(['dibs.activity.mcp_retention_days' => $days, 'dibs.activity.change_retention_days' => 30]);
    McpCallLog::factory()->count(2)->create(['created_at' => now()->subYears(5)]);
    ChangeLog::factory()->count(2)->create(['created_at' => now()->subYears(5)]);

    $deleted = app(PruneActivityLog::class)->handle();

    expect($deleted)->toBe(['mcp' => 0, 'changes' => 2])
        ->and(McpCallLog::query()->count())->toBe(2)
        ->and(ChangeLog::query()->count())->toBe(0);
})->with([0, -5]);

it('does nothing and reports zero when both logs are empty', function (): void {
    expect(app(PruneActivityLog::class)->handle())->toBe(['mcp' => 0, 'changes' => 0]);
});
