<?php

declare(strict_types=1);

use App\Actions\ResolveIdempotentWrite;
use App\Models\Issue;
use App\Models\McpWriteReceipt;

it('performs the write and records a receipt on the first call', function (): void {
    $issue = app(ResolveIdempotentWrite::class)->handle(
        'create-task:abc',
        'issue',
        fn (int $id) => Issue::find($id),
        fn () => Issue::factory()->create(['title' => 'First attempt']),
    );

    expect($issue->title)->toBe('First attempt')
        ->and(Issue::query()->count())->toBe(1)
        ->and(McpWriteReceipt::query()->where('idempotency_key', 'create-task:abc')->sole()->subject_id)->toBe($issue->id);
});

it('returns the original result on a retried key instead of writing again', function (): void {
    $create = fn () => Issue::factory()->create(['title' => 'Should only exist once']);
    $find = fn (int $id) => Issue::find($id);

    $first = app(ResolveIdempotentWrite::class)->handle('create-task:retry', 'issue', $find, $create);
    $second = app(ResolveIdempotentWrite::class)->handle('create-task:retry', 'issue', $find, $create);

    expect($second->id)->toBe($first->id)
        ->and(Issue::query()->count())->toBe(1);
});

it('performs the write again if the previously recorded subject no longer exists', function (): void {
    $issue = Issue::factory()->create();
    McpWriteReceipt::query()->create(['idempotency_key' => 'create-task:orphaned', 'subject_type' => 'issue', 'subject_id' => $issue->id]);
    $issue->delete();

    $result = app(ResolveIdempotentWrite::class)->handle(
        'create-task:orphaned',
        'issue',
        fn (int $id) => Issue::find($id),
        fn () => Issue::factory()->create(['title' => 'Recreated']),
    );

    expect($result->title)->toBe('Recreated');
});
