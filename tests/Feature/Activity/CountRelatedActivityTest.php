<?php

declare(strict_types=1);

use App\Actions\CountRelatedActivity;
use App\Models\ChangeLog;
use App\Models\McpCallLog;

it('counts the rows in each log that share a request id', function (): void {
    McpCallLog::factory()->count(2)->create(['request_id' => 'req-1']);
    ChangeLog::factory()->count(3)->create(['request_id' => 'req-1']);
    McpCallLog::factory()->create(['request_id' => 'req-2']);
    ChangeLog::factory()->create(['request_id' => 'req-2']);

    expect(app(CountRelatedActivity::class)->handle('req-1'))->toBe(['mcp' => 2, 'changes' => 3]);
});

it('reports zero for a request id nothing used, and for a blank one', function (): void {
    McpCallLog::factory()->create(['request_id' => null]);
    ChangeLog::factory()->create(['request_id' => null]);

    expect(app(CountRelatedActivity::class)->handle('never-used'))->toBe(['mcp' => 0, 'changes' => 0])
        ->and(app(CountRelatedActivity::class)->handle(''))->toBe(['mcp' => 0, 'changes' => 0]);
});
