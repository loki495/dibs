<?php

declare(strict_types=1);

use App\Models\TodoCaptureRequest;
use App\Models\User;

it('creates a pending capture request by default', function (): void {
    $request = TodoCaptureRequest::factory()->create(['raw_text' => 'Fix the sink']);

    expect($request->status)->toBe(TodoCaptureRequest::STATUS_PENDING)
        ->and($request->raw_text)->toBe('Fix the sink')
        ->and($request->draft)->toBeNull()
        ->and($request->user)->toBeInstanceOf(User::class);
});

it('casts the draft payload as an array', function (): void {
    $request = TodoCaptureRequest::factory()->create([
        'status' => TodoCaptureRequest::STATUS_COMPLETED,
        'agent_used' => 'codex',
        'draft' => ['title' => 'Fix the sink', 'area' => 2],
    ]);

    expect($request->fresh()->draft)->toBe(['title' => 'Fix the sink', 'area' => 2])
        ->and($request->agent_used)->toBe('codex');
});

it('records a failure without a draft', function (): void {
    $request = TodoCaptureRequest::factory()->create([
        'status' => TodoCaptureRequest::STATUS_FAILED,
        'error' => 'All configured agents failed.',
    ]);

    expect($request->fresh()->draft)->toBeNull()
        ->and($request->error)->toBe('All configured agents failed.');
});
