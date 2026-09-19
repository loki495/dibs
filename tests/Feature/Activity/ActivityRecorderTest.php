<?php

declare(strict_types=1);

use App\Models\ChangeLog;
use App\Models\Issue;
use App\Services\Activity\ActivityContext;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

function recorder(): ActivityRecorder
{
    return app(ActivityRecorder::class);
}

beforeEach(function (): void {
    app()->forgetScopedInstances();
});

it('records a change with the subject, summary, diff and the current actor and request id', function (): void {
    $context = app(ActivityContext::class);
    $context->beginMcp('claude-code');
    $issue = Issue::factory()->create(['title' => 'Fix the sink']);

    $log = recorder()->change(
        'UpdateTodoIssue',
        $issue,
        'Renamed the issue',
        ['title' => ['from' => 'Fix sink', 'to' => 'Fix the sink']],
    );

    expect($log)->toBeInstanceOf(ChangeLog::class)
        ->and($log->fresh()->only([
            'request_id', 'source', 'category', 'action', 'subject_type', 'subject_id', 'subject_label',
            'summary', 'changes', 'actor_type', 'actor_label',
        ]))->toBe([
            'request_id' => $context->requestId(),
            'source' => ChangeLog::SOURCE_MCP,
            'category' => ChangeLog::CATEGORY_CHANGE,
            'action' => 'UpdateTodoIssue',
            'subject_type' => 'issue',
            'subject_id' => $issue->id,
            'subject_label' => 'Fix the sink',
            'summary' => 'Renamed the issue',
            'changes' => ['title' => ['from' => 'Fix sink', 'to' => 'Fix the sink']],
            'actor_type' => ChangeLog::ACTOR_AGENT,
            'actor_label' => 'claude-code',
        ]);
});

it('shares one request id across every entry from the same operation', function (): void {
    app(ActivityContext::class)->beginMcp('claude-code');
    $issue = Issue::factory()->create();

    $first = recorder()->change('CreateTodoIssue', $issue, 'Created');
    $second = recorder()->change('SyncIssueLabels', $issue, 'Labels changed');

    expect($first->request_id)->toBe($second->request_id)->not->toBeNull();
});

it('lets a caller override the stored subject label', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old title']);

    $log = recorder()->change('DeleteTodoIssue', $issue, 'Deleted', [], 'Snapshot label');

    expect($log->subject_label)->toBe('Snapshot label');
});

it('stores an empty diff as null rather than an empty object', function (): void {
    $log = recorder()->change('CompleteTodoTask', Issue::factory()->create(), 'Closed');

    expect($log->fresh()->changes)->toBeNull();
});

it('redacts secret-like fields in a diff before storing them', function (): void {
    $log = recorder()->change('UpdateSetting', null, 'Changed a setting', [
        'github_token' => ['from' => 'ghp_old', 'to' => 'ghp_new'],
        'title' => ['from' => 'a', 'to' => 'b'],
    ]);

    $stored = ChangeLog::query()->findOrFail($log->id);

    expect($stored->changes)->toBe([
        'github_token' => '[redacted]',
        'title' => ['from' => 'a', 'to' => 'b'],
    ])->and(json_encode($stored->getAttributes()))->not->toContain('ghp_');
});

it('truncates oversized diff values and an oversized summary', function (): void {
    config(['dibs.activity.max_value_bytes' => 50]);

    $log = recorder()->change('UpdateTodoIssue', null, str_repeat('s', 500), [
        'body' => ['from' => str_repeat('a', 500), 'to' => str_repeat('b', 500)],
    ]);

    $stored = ChangeLog::query()->findOrFail($log->id);

    expect(strlen($stored->summary))->toBeLessThan(200)
        ->and($stored->summary)->toContain('truncated')
        ->and($stored->changes['body']['from'])->toContain('truncated')
        ->and($stored->changes['body']['to'])->toContain('truncated');
});

it('records a subject-less entry with no subject columns', function (): void {
    $log = recorder()->change('UpdateSetting', null, 'Changed a setting');

    expect($log->subject_type)->toBeNull()
        ->and($log->subject_id)->toBeNull()
        ->and($log->subject_label)->toBeNull();
});

it('records an event under its own category with the current source', function (): void {
    app(ActivityContext::class)->beginConsole('todo:push:drain');

    $log = recorder()->event(ChangeLog::CATEGORY_QUEUE, 'queue.drain', 'Delivered 3 of 4 queued pushes', ['delivered' => ['from' => null, 'to' => 3]]);

    expect($log->fresh()->only(['category', 'action', 'summary', 'source', 'actor_type', 'actor_label', 'subject_type', 'changes']))->toBe([
        'category' => ChangeLog::CATEGORY_QUEUE,
        'action' => 'queue.drain',
        'summary' => 'Delivered 3 of 4 queued pushes',
        'source' => ChangeLog::SOURCE_CLI,
        'actor_type' => ChangeLog::ACTOR_SYSTEM,
        'actor_label' => 'todo:push:drain',
        'subject_type' => null,
        'changes' => ['delivered' => ['from' => null, 'to' => 3]],
    ]);
});

it('records a failed sign-in with no user as an anonymous web event', function (): void {
    app(ActivityContext::class)->beginWeb();

    $log = recorder()->event(ChangeLog::CATEGORY_AUTH, 'auth.failed', 'Failed sign-in for someone@example.test');

    expect($log->source)->toBe(ChangeLog::SOURCE_UI)
        ->and($log->actor_type)->toBe(ChangeLog::ACTOR_SYSTEM)
        ->and($log->actor_label)->toBeNull();
});

describe('diff', function (): void {
    it('keeps only the fields that changed', function (): void {
        expect(recorder()->diff(
            ['title' => 'a', 'state' => 'OPEN', 'body' => 'same'],
            ['title' => 'b', 'state' => 'CLOSED', 'body' => 'same'],
        ))->toBe([
            'title' => ['from' => 'a', 'to' => 'b'],
            'state' => ['from' => 'OPEN', 'to' => 'CLOSED'],
        ]);
    });

    it('treats a field that appears or disappears as changing to or from null', function (): void {
        expect(recorder()->diff(['gone' => 'x'], ['new' => 'y']))->toBe([
            'gone' => ['from' => 'x', 'to' => null],
            'new' => ['from' => null, 'to' => 'y'],
        ]);
    });

    it('returns nothing when nothing changed', function (): void {
        expect(recorder()->diff(['a' => 1, 'b' => [1, 2]], ['a' => 1, 'b' => [1, 2]]))->toBe([]);
    });

    it('compares list fields such as label names by value', function (): void {
        expect(recorder()->diff(['labels' => ['bug']], ['labels' => ['bug', 'agent task']]))
            ->toBe(['labels' => ['from' => ['bug'], 'to' => ['bug', 'agent task']]]);
    });
});

describe('diffModel', function (): void {
    it('reports only the attributes an update changed and ignores timestamps', function (): void {
        $issue = Issue::factory()->create(['title' => 'Old', 'state' => 'OPEN']);
        $issue->update(['title' => 'New']);

        expect(recorder()->diffModel($issue))->toBe(['title' => ['from' => 'Old', 'to' => 'New']]);
    });

    it('reports every attribute of a just-created model as changing from null', function (): void {
        $issue = Issue::factory()->create(['title' => 'Brand new']);

        $diff = recorder()->diffModel($issue);

        expect($diff['title'])->toBe(['from' => null, 'to' => 'Brand new'])
            ->and($diff)->not->toHaveKeys(['id', 'created_at', 'updated_at']);
    });

    it('reports every attribute of a deleted model as changing to null', function (): void {
        $issue = Issue::factory()->create(['title' => 'Doomed']);
        $issue->delete();

        expect(recorder()->diffModel($issue)['title'])->toBe(['from' => 'Doomed', 'to' => null]);
    });

    it('reports nothing for a model that was saved without changes', function (): void {
        $issue = Issue::factory()->create();
        $issue = $issue->fresh();
        $issue->save();

        expect(recorder()->diffModel($issue))->toBe([]);
    });
});

describe('when the entry cannot be written', function (): void {
    it('rethrows in debug so the failure surfaces', function (): void {
        config(['app.debug' => true]);
        Schema::drop('change_logs');

        recorder()->change('UpdateTodoIssue', null, 'Renamed');
    })->throws(QueryException::class);

    it('reports the failure and lets the write it describes carry on outside debug', function (): void {
        config(['app.debug' => false]);
        Exceptions::fake();
        Schema::drop('change_logs');

        $result = recorder()->change('UpdateTodoIssue', null, 'Renamed');

        expect($result)->toBeNull();
        Exceptions::assertReported(QueryException::class);
    });

    it('does not leak the redacted value into the reported failure', function (): void {
        config(['app.debug' => false]);
        Exceptions::fake();
        Schema::drop('change_logs');

        recorder()->change('UpdateSetting', null, 'Changed', ['github_token' => ['from' => 'ghp_old', 'to' => 'ghp_new']]);

        Exceptions::assertReported(fn (QueryException $e): bool => ! str_contains($e->getMessage(), 'ghp_'));
    });
});
