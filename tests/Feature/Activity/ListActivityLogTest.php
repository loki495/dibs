<?php

declare(strict_types=1);

use App\Actions\ListActivityLog;
use App\Models\ChangeLog;
use App\Models\McpCallLog;
use App\Support\ActivityLogFilters;

function listLog(string $log, ?ActivityLogFilters $filters = null, int $page = 1, int $perPage = 25): array
{
    $result = app(ListActivityLog::class)->handle($log, $filters ?? new ActivityLogFilters, $page, $perPage);

    return ['ids' => $result->getCollection()->pluck('id')->all(), 'total' => $result->total(), 'lastPage' => $result->lastPage()];
}

beforeEach(function (): void {
    config(['dibs.timezone' => 'America/Los_Angeles']);
});

it('lists the requested log newest first, paginated, and reports the total', function (): void {
    $rows = McpCallLog::factory()->count(5)->create();
    ChangeLog::factory()->count(2)->create();

    $first = listLog(ListActivityLog::MCP_CALLS, null, 1, 2);
    $last = listLog(ListActivityLog::MCP_CALLS, null, 3, 2);

    expect($first['ids'])->toBe([$rows[4]->id, $rows[3]->id])
        ->and($first['total'])->toBe(5)
        ->and($first['lastPage'])->toBe(3)
        ->and($last['ids'])->toBe([$rows[0]->id]);
});

it('lists change rows from the change log, not the MCP log', function (): void {
    McpCallLog::factory()->create();
    $change = ChangeLog::factory()->create();

    expect(listLog(ListActivityLog::CHANGES)['ids'])->toBe([$change->id]);
});

it('filters the MCP log by tool and status', function (): void {
    $match = McpCallLog::factory()->create(['tool' => 'todo_create', 'status' => McpCallLog::STATUS_ERROR]);
    McpCallLog::factory()->create(['tool' => 'todo_create', 'status' => McpCallLog::STATUS_OK]);
    McpCallLog::factory()->create(['tool' => 'todo_list', 'status' => McpCallLog::STATUS_ERROR]);

    $result = listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(type: 'todo_create', status: McpCallLog::STATUS_ERROR));

    expect($result['ids'])->toBe([$match->id]);
});

it('filters the change log by action, category and source', function (): void {
    $match = ChangeLog::factory()->create(['action' => 'auth.login', 'category' => ChangeLog::CATEGORY_AUTH, 'source' => ChangeLog::SOURCE_UI]);
    ChangeLog::factory()->create(['action' => 'auth.login', 'category' => ChangeLog::CATEGORY_AUTH, 'source' => ChangeLog::SOURCE_CLI]);
    ChangeLog::factory()->create(['action' => 'UpdateTodoIssue', 'category' => ChangeLog::CATEGORY_CHANGE, 'source' => ChangeLog::SOURCE_UI]);

    $result = listLog(ListActivityLog::CHANGES, new ActivityLogFilters(type: 'auth.login', category: ChangeLog::CATEGORY_AUTH, source: ChangeLog::SOURCE_UI));

    expect($result['ids'])->toBe([$match->id]);
});

it('ignores a filter that does not apply to the log being listed', function (): void {
    McpCallLog::factory()->count(2)->create();
    ChangeLog::factory()->count(2)->create();

    expect(listLog(ListActivityLog::CHANGES, new ActivityLogFilters(status: 'error'))['total'])->toBe(2)
        ->and(listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(category: 'auth', source: 'cli'))['total'])->toBe(2);
});

it('treats blank filter strings as no filter', function (): void {
    McpCallLog::factory()->count(2)->create();

    expect(listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(from: '', to: '', type: '', status: '', search: '  ', requestId: ''))['total'])->toBe(2);
});

it('applies the date range inclusively in the configured timezone, not UTC', function (): void {
    $lateEvening = McpCallLog::factory()->create(['created_at' => '2026-09-21 06:30:00']); // 23:30 on 2026-09-20 in Los Angeles
    $morning = McpCallLog::factory()->create(['created_at' => '2026-09-21 16:00:00']);     // 09:00 on 2026-09-21
    $nextDay = McpCallLog::factory()->create(['created_at' => '2026-09-22 10:00:00']);     // 2026-09-22

    $onTheTwentieth = listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(from: '2026-09-20', to: '2026-09-20'));
    $fromTheTwentyFirst = listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(from: '2026-09-21'));
    $upToTheTwentyFirst = listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(to: '2026-09-21'));

    expect($onTheTwentieth['ids'])->toBe([$lateEvening->id])
        ->and($fromTheTwentyFirst['ids'])->toBe([$nextDay->id, $morning->id])
        ->and($upToTheTwentyFirst['ids'])->toBe([$morning->id, $lateEvening->id]);
});

it('ignores a date that does not parse or does not exist instead of failing', function (string $date): void {
    McpCallLog::factory()->count(2)->create();

    expect(listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(from: $date))['total'])->toBe(2)
        ->and(listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(to: $date))['total'])->toBe(2);
})->with([
    'not a date' => 'not-a-date',
    'month out of range' => '2026-13-45',
    'day that rolls into the next month' => '2026-02-31',
    'wrong format' => '09/21/2026',
]);

it('searches across the MCP columns including the stored arguments, case-insensitively', function (): void {
    $byTool = McpCallLog::factory()->create(['tool' => 'todo_claim']);
    $byArguments = McpCallLog::factory()->create(['arguments' => ['title' => 'Fix The Sink']]);
    $byError = McpCallLog::factory()->create(['error_message' => 'Stale revision']);
    $byAgent = McpCallLog::factory()->create(['agent_label' => 'codex-cli']);
    McpCallLog::factory()->create(['tool' => 'todo_list', 'agent_label' => 'claude-code']);

    $ids = fn (string $term): array => listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(search: $term))['ids'];

    expect($ids('CLAIM'))->toBe([$byTool->id])
        ->and($ids('fix the sink'))->toBe([$byArguments->id])
        ->and($ids('stale'))->toBe([$byError->id])
        ->and($ids('codex'))->toBe([$byAgent->id]);
});

it('searches across the change columns including the field diff', function (): void {
    $bySummary = ChangeLog::factory()->create(['summary' => 'Renamed the issue']);
    $bySubject = ChangeLog::factory()->create(['subject_label' => 'Clean ceiling fans']);
    $byActor = ChangeLog::factory()->create(['actor_label' => 'claude-code']);
    $byDiff = ChangeLog::factory()->create(['changes' => ['title' => ['from' => 'old', 'to' => 'Shiny new title']]]);

    $ids = fn (string $term): array => listLog(ListActivityLog::CHANGES, new ActivityLogFilters(search: $term))['ids'];

    expect($ids('renamed'))->toBe([$bySummary->id])
        ->and($ids('ceiling'))->toBe([$bySubject->id])
        ->and($ids('claude-code'))->toBe([$byActor->id])
        ->and($ids('shiny new'))->toBe([$byDiff->id]);
});

it('treats % and _ in a search as literal characters', function (): void {
    $literal = ChangeLog::factory()->create(['summary' => 'Set progress to 100% done']);
    ChangeLog::factory()->create(['summary' => 'Set progress to 1000 done']);
    $underscore = ChangeLog::factory()->create(['summary' => 'Renamed group to a_b']);
    ChangeLog::factory()->create(['summary' => 'Renamed group to axb']);

    expect(listLog(ListActivityLog::CHANGES, new ActivityLogFilters(search: '100%'))['ids'])->toBe([$literal->id])
        ->and(listLog(ListActivityLog::CHANGES, new ActivityLogFilters(search: 'a_b'))['ids'])->toBe([$underscore->id]);
});

it('narrows to one request id across either log', function (): void {
    $mcp = McpCallLog::factory()->create(['request_id' => 'req-1']);
    McpCallLog::factory()->create(['request_id' => 'req-2']);
    $change = ChangeLog::factory()->create(['request_id' => 'req-1']);
    ChangeLog::factory()->create(['request_id' => 'req-2']);

    expect(listLog(ListActivityLog::MCP_CALLS, new ActivityLogFilters(requestId: 'req-1'))['ids'])->toBe([$mcp->id])
        ->and(listLog(ListActivityLog::CHANGES, new ActivityLogFilters(requestId: 'req-1'))['ids'])->toBe([$change->id]);
});

it('clamps page size and page, and returns an empty page past the end', function (): void {
    McpCallLog::factory()->count(3)->create();

    $tooBig = app(ListActivityLog::class)->handle(ListActivityLog::MCP_CALLS, new ActivityLogFilters, 1, 5000);
    $tooSmall = app(ListActivityLog::class)->handle(ListActivityLog::MCP_CALLS, new ActivityLogFilters, 0, 0);
    $pastEnd = listLog(ListActivityLog::MCP_CALLS, null, 99, 25);

    expect($tooBig->perPage())->toBe(ListActivityLog::MAX_PER_PAGE)
        ->and($tooSmall->perPage())->toBe(1)
        ->and($tooSmall->currentPage())->toBe(1)
        ->and($pastEnd['ids'])->toBe([])
        ->and($pastEnd['total'])->toBe(3);
});

it('refuses an unknown log name', function (): void {
    expect(fn () => app(ListActivityLog::class)->handle('everything', new ActivityLogFilters))
        ->toThrow(InvalidArgumentException::class, 'Unknown activity log "everything"');
});
