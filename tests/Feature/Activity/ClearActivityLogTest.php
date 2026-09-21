<?php

declare(strict_types=1);

use App\Actions\ClearActivityLog;
use App\Models\ChangeLog;
use App\Models\McpCallLog;
use App\Models\User;
use App\Services\Activity\ActivityContext;

it('clears the change log and leaves one audit row saying who cleared how many', function (): void {
    ChangeLog::factory()->count(3)->create();
    $this->actingAs(User::factory()->create(['name' => 'Andres']));
    app(ActivityContext::class)->beginWeb();

    $cleared = app(ClearActivityLog::class)->handle(ClearActivityLog::CHANGES);

    $audit = ChangeLog::query()->sole();

    expect($cleared)->toBe(3)
        ->and($audit->action)->toBe('ClearActivityLog')
        ->and($audit->category)->toBe(ChangeLog::CATEGORY_CHANGE)
        ->and($audit->actor_type)->toBe(ChangeLog::ACTOR_USER)
        ->and($audit->actor_label)->toBe('Andres')
        ->and($audit->summary)->toBe('Cleared the change log (3 entries)')
        ->and($audit->changes)->toBe(['entries_deleted' => ['from' => 3, 'to' => 0]]);
});

it('clears only the MCP call log when asked to and records the clear in the change log', function (): void {
    McpCallLog::factory()->count(2)->create();
    $keptChange = ChangeLog::factory()->create();

    $cleared = app(ClearActivityLog::class)->handle(ClearActivityLog::MCP_CALLS);

    expect($cleared)->toBe(2)
        ->and(McpCallLog::query()->count())->toBe(0)
        ->and(ChangeLog::query()->whereKey($keptChange->id)->exists())->toBeTrue()
        ->and(ChangeLog::query()->where('action', 'ClearActivityLog')->sole()->summary)->toBe('Cleared the MCP call log (2 entries)');
});

it('uses the singular form for a single entry', function (): void {
    McpCallLog::factory()->create();

    app(ClearActivityLog::class)->handle(ClearActivityLog::MCP_CALLS);

    expect(ChangeLog::query()->sole()->summary)->toBe('Cleared the MCP call log (1 entry)');
});

it('does nothing and leaves no audit row when the log is already empty', function (): void {
    $cleared = app(ClearActivityLog::class)->handle(ClearActivityLog::MCP_CALLS);

    expect($cleared)->toBe(0)
        ->and(ChangeLog::query()->count())->toBe(0);
});

it('refuses an unknown log name without deleting anything', function (): void {
    McpCallLog::factory()->create();
    ChangeLog::factory()->create();

    expect(fn () => app(ClearActivityLog::class)->handle('everything'))
        ->toThrow(InvalidArgumentException::class, 'Unknown activity log "everything"');

    expect(McpCallLog::query()->count())->toBe(1)
        ->and(ChangeLog::query()->count())->toBe(1);
});
