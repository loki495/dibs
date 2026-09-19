<?php

declare(strict_types=1);

use App\Models\ChangeLog;
use App\Models\McpCallLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('has the activity log tables with the columns the recorder needs', function (): void {
    expect(Schema::getColumnListing('mcp_call_logs'))->toEqualCanonicalizing([
        'id', 'request_id', 'tool', 'arguments', 'status', 'error_message', 'duration_ms', 'agent_label', 'created_at',
    ])->and(Schema::getColumnListing('change_logs'))->toEqualCanonicalizing([
        'id', 'request_id', 'source', 'category', 'action', 'subject_type', 'subject_id', 'subject_label',
        'summary', 'changes', 'actor_type', 'actor_label', 'created_at',
    ]);
});

it('casts an mcp call log arguments payload as an array and stamps created_at', function (): void {
    $log = McpCallLog::create([
        'request_id' => 'req-1',
        'tool' => 'todo_show',
        'arguments' => ['id' => 5],
        'status' => McpCallLog::STATUS_OK,
        'duration_ms' => 12,
    ]);

    $fresh = $log->fresh();

    expect($fresh->arguments)->toBe(['id' => 5])
        ->and($fresh->duration_ms)->toBe(12)
        ->and($fresh->created_at)->not->toBeNull()
        ->and($fresh->error_message)->toBeNull()
        ->and($fresh->agent_label)->toBeNull();
});

it('casts a change log diff as an array and stamps created_at', function (): void {
    $log = ChangeLog::create([
        'request_id' => 'req-2',
        'source' => ChangeLog::SOURCE_UI,
        'category' => ChangeLog::CATEGORY_CHANGE,
        'action' => 'UpdateTodoIssue',
        'subject_type' => 'issue',
        'subject_id' => 7,
        'subject_label' => 'Fix the sink',
        'summary' => 'Renamed the issue',
        'changes' => ['title' => ['from' => 'Fix sink', 'to' => 'Fix the sink']],
        'actor_type' => ChangeLog::ACTOR_USER,
        'actor_label' => 'andres',
    ]);

    $fresh = $log->fresh();

    expect($fresh->changes)->toBe(['title' => ['from' => 'Fix sink', 'to' => 'Fix the sink']])
        ->and($fresh->subject_id)->toBe(7)
        ->and($fresh->created_at)->not->toBeNull();
});

it('allows an event change log with no subject or diff', function (): void {
    $log = ChangeLog::create([
        'source' => ChangeLog::SOURCE_SYSTEM,
        'category' => ChangeLog::CATEGORY_AUTH,
        'action' => 'auth.login',
        'summary' => 'Signed in',
        'actor_type' => ChangeLog::ACTOR_SYSTEM,
    ]);

    $fresh = $log->fresh();

    expect($fresh->subject_type)->toBeNull()
        ->and($fresh->subject_id)->toBeNull()
        ->and($fresh->changes)->toBeNull()
        ->and($fresh->request_id)->toBeNull();
});

it('rejects a change log row with no action, summary or source', function (array $missing): void {
    $attributes = array_diff_key([
        'source' => ChangeLog::SOURCE_UI,
        'category' => ChangeLog::CATEGORY_CHANGE,
        'action' => 'UpdateTodoIssue',
        'summary' => 'Renamed',
        'actor_type' => ChangeLog::ACTOR_USER,
    ], array_flip($missing));

    ChangeLog::create($attributes);
})->with([
    'no action' => [['action']],
    'no summary' => [['summary']],
    'no source' => [['source']],
])->throws(QueryException::class);

it('rejects an mcp call log row with no tool or status', function (array $missing): void {
    $attributes = array_diff_key([
        'tool' => 'todo_show',
        'status' => McpCallLog::STATUS_OK,
    ], array_flip($missing));

    McpCallLog::create($attributes);
})->with([
    'no tool' => [['tool']],
    'no status' => [['status']],
])->throws(QueryException::class);

it('defaults the activity settings to 30 day retention, 2 KB values and the standard secret keys', function (): void {
    expect(config('dibs.activity.mcp_retention_days'))->toBe(30)
        ->and(config('dibs.activity.change_retention_days'))->toBe(30)
        ->and(config('dibs.activity.max_value_bytes'))->toBe(2048)
        ->and(config('dibs.activity.redact_keys'))->toEqualCanonicalizing([
            'token', 'secret', 'password', 'authorization', 'key',
        ]);
});
