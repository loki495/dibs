<?php

declare(strict_types=1);

use App\Actions\ClearActivityLog;
use App\Models\ChangeLog;
use App\Models\McpCallLog;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\Activity\ActivityRedactor;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function activityPage(): Testable
{
    return Livewire::actingAs(User::factory()->create())->test('pages::activity');
}

function rowKey(McpCallLog|ChangeLog $row): string
{
    return 'wire:key="entry-'.$row->id.'"';
}

beforeEach(function (): void {
    config(['dibs.timezone' => 'America/Los_Angeles']);
});

it('is unreachable to a guest', function (): void {
    $this->get('/activity')->assertRedirect('/login');
});

it('opens on the MCP call log with a toggle to the change log', function (): void {
    $call = McpCallLog::factory()->create(['tool' => 'todo_create', 'agent_label' => 'claude-code', 'duration_ms' => 42]);
    ChangeLog::factory()->create(['summary' => 'Renamed the issue']);

    activityPage()
        ->assertSet('log', 'mcp')
        ->assertSeeHtml(rowKey($call))
        ->assertSee('todo_create')->assertSee('claude-code')->assertSee('42 ms')
        ->assertSee('MCP calls')->assertSee('Changes')
        ->assertDontSee('Renamed the issue');
});

it('shows an empty state when nothing has been recorded', function (): void {
    activityPage()->assertSee('No MCP calls recorded yet.')
        ->call('setLog', 'changes')->assertSee('No changes recorded yet.');
});

it('switches to the change log and resets the filters that only made sense for the other log', function (): void {
    McpCallLog::factory()->create(['tool' => 'todo_create', 'status' => 'error']);
    $change = ChangeLog::factory()->create(['summary' => 'Renamed the issue']);

    activityPage()
        ->set('type', 'todo_create')->set('status', 'error')->set('search', 'x')
        ->call('setLog', 'changes')
        ->assertSet('log', 'changes')
        ->assertSet('type', '')->assertSet('status', '')->assertSet('search', '')
        ->assertSeeHtml(rowKey($change))->assertSee('Renamed the issue');
});

it('ignores a request to switch to a log that does not exist', function (): void {
    activityPage()->call('setLog', 'bogus')->assertSet('log', 'mcp');
});

it('does not let the client write the active log directly', function (): void {
    expect(fn () => activityPage()->set('log', 'changes'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('filters MCP calls by tool and by status', function (): void {
    $match = McpCallLog::factory()->create(['tool' => 'todo_create', 'status' => 'error']);
    $otherStatus = McpCallLog::factory()->create(['tool' => 'todo_create', 'status' => 'ok']);
    $otherTool = McpCallLog::factory()->create(['tool' => 'todo_list', 'status' => 'error']);

    activityPage()->set('type', 'todo_create')->set('status', 'error')
        ->assertSeeHtml(rowKey($match))
        ->assertDontSeeHtml(rowKey($otherStatus))
        ->assertDontSeeHtml(rowKey($otherTool));
});

it('filters changes by action, category and source', function (): void {
    $match = ChangeLog::factory()->create(['action' => 'auth.login', 'category' => 'auth', 'source' => 'ui']);
    $wrongSource = ChangeLog::factory()->create(['action' => 'auth.login', 'category' => 'auth', 'source' => 'cli']);
    $wrongAction = ChangeLog::factory()->create(['action' => 'UpdateTodoIssue', 'category' => 'change', 'source' => 'ui']);

    activityPage()->call('setLog', 'changes')
        ->set('type', 'auth.login')->set('category', 'auth')->set('source', 'ui')
        ->assertSeeHtml(rowKey($match))
        ->assertDontSeeHtml(rowKey($wrongSource))
        ->assertDontSeeHtml(rowKey($wrongAction));
});

it('searches the visible log by free text', function (): void {
    $match = McpCallLog::factory()->create(['arguments' => ['title' => 'Fix the sink']]);
    $other = McpCallLog::factory()->create(['arguments' => ['title' => 'Something else']]);

    activityPage()->set('search', 'sink')
        ->assertSeeHtml(rowKey($match))
        ->assertDontSeeHtml(rowKey($other));
});

it('filters by an inclusive date range in the configured timezone', function (): void {
    $lateEvening = McpCallLog::factory()->create(['created_at' => '2026-09-21 06:30:00']); // 23:30 on the 20th in Los Angeles
    $nextMorning = McpCallLog::factory()->create(['created_at' => '2026-09-21 16:00:00']);

    activityPage()->set('from', '2026-09-20')->set('to', '2026-09-20')
        ->assertSeeHtml(rowKey($lateEvening))
        ->assertDontSeeHtml(rowKey($nextMorning));
});

it('shows entry times in the configured timezone', function (): void {
    McpCallLog::factory()->create(['created_at' => '2026-09-21 06:30:15']);

    activityPage()->assertSee('Sep 20, 23:30:15');
});

it('reports a malformed date as a validation error and keeps listing instead of crashing', function (): void {
    $row = McpCallLog::factory()->create();

    activityPage()->set('from', 'not-a-date')
        ->assertHasErrors(['from' => 'date_format'])
        ->assertSee('Use a date like 2026-09-21.')
        ->assertSeeHtml(rowKey($row));
});

it('clears every filter at once', function (): void {
    activityPage()
        ->set('type', 'todo_list')->set('status', 'ok')->set('search', 'x')->set('from', '2026-09-01')->set('to', '2026-09-30')->set('requestId', 'req-1')
        ->call('resetFilters')
        ->assertSet('type', '')->assertSet('status', '')->assertSet('search', '')
        ->assertSet('from', '')->assertSet('to', '')->assertSet('requestId', '');
});

it('pages through a long log and stays within its bounds', function (): void {
    $rows = McpCallLog::factory()->count(30)->create();

    activityPage()
        ->assertSet('page', 1)->assertSee('Page 1 of 2')->assertSee('30 entries')
        ->assertSeeHtml(rowKey($rows[29]))->assertDontSeeHtml(rowKey($rows[0]))
        ->call('previousPage')->assertSet('page', 1)
        ->call('nextPage')->assertSet('page', 2)->assertSee('Page 2 of 2')
        ->assertSeeHtml(rowKey($rows[0]))->assertDontSeeHtml(rowKey($rows[29]))
        ->call('nextPage')->assertSet('page', 2)
        ->call('previousPage')->assertSet('page', 1);
});

it('returns to the first page when a filter changes', function (): void {
    McpCallLog::factory()->count(30)->create();

    activityPage()->call('nextPage')->assertSet('page', 2)
        ->set('status', 'ok')->assertSet('page', 1);
});

it('expands an MCP call to its redacted arguments, never the secret', function (): void {
    $secret = 's3cret-capability-token';
    $call = app(ActivityRecorder::class)->mcpCall('todo_complete', ['issueId' => 12, 'capabilityToken' => $secret], 'ok', null, 9, 'claude-code');

    activityPage()
        ->assertDontSee('issueId')
        ->call('toggle', $call->id)
        ->assertSet('expanded', $call->id)
        ->assertSee('issueId')->assertSee(ActivityRedactor::REDACTED)
        ->assertDontSee($secret)
        ->call('toggle', $call->id)
        ->assertSet('expanded', null);
});

it('shows the error message of a failed MCP call when expanded', function (): void {
    $call = McpCallLog::factory()->create(['status' => 'error', 'error_message' => 'Stale revision: reread the task']);

    activityPage()->call('toggle', $call->id)
        ->assertSee('Stale revision: reread the task')
        ->assertSeeHtml('bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-300');
});

it('expands a change to its field-level diff', function (): void {
    $change = ChangeLog::factory()->create(['changes' => ['title' => ['from' => 'Fix sink', 'to' => 'Fix the sink'], 'labels' => ['from' => null, 'to' => ['bug', 'ui']]]]);

    activityPage()->call('setLog', 'changes')
        ->call('toggle', $change->id)
        ->assertSee('title')->assertSee('Fix sink')->assertSee('Fix the sink')
        ->assertSee('labels')->assertSee('"bug"');
});

it('says so when an expanded change recorded no field-level diff', function (): void {
    $change = ChangeLog::factory()->create(['changes' => null]);

    activityPage()->call('setLog', 'changes')->call('toggle', $change->id)->assertSee('No field-level changes recorded.');
});

it('links an MCP call to the changes it made and back, through the shared request id', function (): void {
    $call = McpCallLog::factory()->create(['request_id' => 'req-1']);
    McpCallLog::factory()->create(['request_id' => 'req-2']);
    $changes = ChangeLog::factory()->count(2)->create(['request_id' => 'req-1']);
    $unrelated = ChangeLog::factory()->create(['request_id' => 'req-2']);

    $page = activityPage()->call('toggle', $call->id)->assertSee('Show 2 changes from this request')
        ->call('showRequest', 'changes', 'req-1')
        ->assertSet('log', 'changes')->assertSet('requestId', 'req-1')
        ->assertSeeHtml(rowKey($changes[0]))->assertSeeHtml(rowKey($changes[1]))->assertDontSeeHtml(rowKey($unrelated))
        ->assertSee('Request req-1');

    $page->call('toggle', $changes[0]->id)->assertSee('Show 1 MCP call from this request')
        ->call('showRequest', 'mcp', 'req-1')
        ->assertSet('log', 'mcp')->assertSeeHtml(rowKey($call));

    $page->call('clearRequest')->assertSet('requestId', '')->assertSet('page', 1);

    $page->call('showRequest', 'mcp', 'req-1')->call('resetFilters')->assertSet('requestId', '');
});

it('offers no request link when the request has nothing in the other log', function (): void {
    $call = McpCallLog::factory()->create(['request_id' => 'lonely']);

    activityPage()->call('toggle', $call->id)->assertDontSee('from this request');
});

it('clears the whole visible log after confirmation and leaves one audit row', function (): void {
    McpCallLog::factory()->count(3)->create(['tool' => 'todo_list']);
    McpCallLog::factory()->create(['tool' => 'todo_create']);
    $keptChange = ChangeLog::factory()->create();

    activityPage()
        ->assertSeeHtml('wire:confirm')->assertSee('Clear all MCP calls')
        ->set('type', 'todo_create')
        ->call('clearLog')
        ->assertSee('Cleared 4 entries.')
        ->assertSee('No MCP calls recorded yet.')
        ->assertDontSee('Clear all MCP calls');

    expect(McpCallLog::query()->count())->toBe(0)
        ->and(ChangeLog::query()->whereKey($keptChange->id)->exists())->toBeTrue()
        ->and(ChangeLog::query()->where('action', 'ClearActivityLog')->sole()->summary)->toBe('Cleared the MCP call log (4 entries)');
});

it('clears the change log and shows the single audit row it leaves behind', function (): void {
    ChangeLog::factory()->count(2)->create();

    activityPage()->call('setLog', 'changes')->assertSee('Clear all changes')
        ->call('clearLog')
        ->assertSee('Cleared 2 entries.')
        ->assertSee('Cleared the change log (2 entries)');

    expect(ChangeLog::query()->count())->toBe(1);
});

it('hides the clear button when the log is already empty', function (): void {
    activityPage()->assertDontSee('Clear all MCP calls')
        ->call('clearLog')
        ->assertDontSee('Cleared 0 entries.');

    expect(ChangeLog::query()->count())->toBe(0);
});

it('does not let a tampered log name reach the clear action', function (): void {
    McpCallLog::factory()->create();

    expect(fn () => activityPage()->set('log', ClearActivityLog::CHANGES))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(McpCallLog::query()->count())->toBe(1);
});

it('is linked from the settings menu and from the workspace sidebar', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('top-bar')->assertSee('Activity')->assertSeeHtml('href="'.route('activity').'"');

    $this->actingAs($user)->get('/')->assertOk()->assertSeeHtml('href="'.route('activity').'"');
});

it('goes back to where a request link was followed from, restoring the log, filters, page and open row', function (): void {
    $origin = McpCallLog::factory()->create(['status' => 'ok', 'request_id' => 'req-1']);
    McpCallLog::factory()->count(29)->create(['status' => 'ok']);
    ChangeLog::factory()->create(['request_id' => 'req-1']);

    activityPage()
        ->set('status', 'ok')->call('nextPage')->call('toggle', $origin->id)
        ->call('showRequest', 'changes', 'req-1')
        ->assertSet('log', 'changes')->assertSee('Back to MCP calls')
        ->call('goBack')
        ->assertSet('log', 'mcp')->assertSet('status', 'ok')->assertSet('page', 2)
        ->assertSet('expanded', $origin->id)->assertSet('requestId', '')
        ->assertDontSee('Back to MCP calls')->assertDontSee('Back to Changes');
});

it('remembers several hops and walks back through them one at a time', function (): void {
    $call = McpCallLog::factory()->create(['request_id' => 'req-1']);
    $change = ChangeLog::factory()->create(['request_id' => 'req-1']);

    activityPage()
        ->call('toggle', $call->id)->call('showRequest', 'changes', 'req-1')
        ->call('toggle', $change->id)->call('showRequest', 'mcp', 'req-1')
        ->assertSet('log', 'mcp')->assertSee('Back to Changes')
        ->call('goBack')
        ->assertSet('log', 'changes')->assertSet('requestId', 'req-1')->assertSet('expanded', $change->id)->assertSee('Back to MCP calls')
        ->call('goBack')
        ->assertSet('log', 'mcp')->assertSet('requestId', '')->assertSet('expanded', $call->id)->assertDontSee('Back to MCP calls')->assertDontSee('Back to Changes');
});

it('ignores Back when there is nowhere to go back to', function (): void {
    McpCallLog::factory()->create();

    activityPage()->call('goBack')->assertSet('log', 'mcp')->assertSet('history', [])->assertDontSee('Back to MCP calls')->assertDontSee('Back to Changes');
});

it('drops the way back once the user picks a log, resets the filters or clears', function (string $method, array $arguments): void {
    $call = McpCallLog::factory()->create(['request_id' => 'req-1']);
    ChangeLog::factory()->create(['request_id' => 'req-1']);

    activityPage()->call('showRequest', 'changes', 'req-1')->assertSee('Back to MCP calls')
        ->call($method, ...$arguments)
        ->assertSet('history', [])->assertDontSee('Back to MCP calls')->assertDontSee('Back to Changes');
})->with([
    'switching log' => ['setLog', ['mcp']],
    'resetting filters' => ['resetFilters', []],
    'clearing the log' => ['clearLog', []],
]);

it('keeps the way back when only the request filter chip is removed', function (): void {
    McpCallLog::factory()->create(['request_id' => 'req-1']);
    ChangeLog::factory()->create(['request_id' => 'req-1']);

    activityPage()->call('showRequest', 'changes', 'req-1')->call('clearRequest')
        ->assertSet('requestId', '')->assertSee('Back to MCP calls');
});

it('ignores a request link to a log that does not exist', function (): void {
    activityPage()->call('showRequest', 'bogus', 'req-1')
        ->assertSet('log', 'mcp')->assertSet('requestId', '')->assertSet('history', []);
});

it('does not let the client write the way back directly', function (): void {
    expect(fn () => activityPage()->set('history', [['log' => 'changes']]))->toThrow(CannotUpdateLockedPropertyException::class);
});
