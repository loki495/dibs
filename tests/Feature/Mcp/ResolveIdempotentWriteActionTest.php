<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Actions\ResolveIdempotentWrite;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\McpWriteReceipt;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

it('leaves neither a write nor a receipt when the write fails', function (): void {
    expect(fn () => app(ResolveIdempotentWrite::class)->handle(
        'create-task:fails',
        'issue',
        fn (int $id) => Issue::find($id),
        function (): Issue {
            Issue::factory()->create(['title' => 'Half done']);

            throw new RuntimeException('Failed after writing');
        },
    ))->toThrow(RuntimeException::class, 'Failed after writing');

    expect(Issue::query()->count())->toBe(0)
        ->and(McpWriteReceipt::query()->count())->toBe(0);
});

it('rolls back the write when the receipt cannot be recorded', function (): void {
    McpWriteReceipt::creating(function (): void {
        throw new RuntimeException('Receipt insert failed');
    });

    expect(fn () => app(ResolveIdempotentWrite::class)->handle(
        'create-task:receipt-fails',
        'issue',
        fn (int $id) => Issue::find($id),
        fn () => Issue::factory()->create(),
    ))->toThrow(RuntimeException::class, 'Receipt insert failed');

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a key already used for a different kind of write', function (): void {
    $issue = Issue::factory()->create();
    $comment = Comment::factory()->create(['issue_id' => $issue->id]);
    McpWriteReceipt::query()->create(['idempotency_key' => 'shared-key', 'subject_type' => 'comment', 'subject_id' => $comment->id]);

    expect(fn () => app(ResolveIdempotentWrite::class)->handle(
        'shared-key',
        'issue',
        fn (int $id) => Issue::find($id),
        fn () => Issue::factory()->create(['title' => 'Must not be created']),
    ))->toThrow(TodoValidationException::class, 'already used for a comment write');

    expect(Issue::query()->where('title', 'Must not be created')->exists())->toBeFalse();
});

it('returns the winner of a concurrent call with the same key instead of duplicating it', function (): void {
    $winner = Issue::factory()->create(['title' => 'Winner']);
    $simulateWinnerCommit = fn () => DB::table('mcp_write_receipts')->insert([
        'idempotency_key' => 'create-task:race',
        'subject_type' => 'issue',
        'subject_id' => $winner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // The other call commits its receipt after this call's lookup but before its insert. The
    // in-transaction copy collides with this call's receipt; the rollback discards it, so it is
    // put back afterwards to stand for the other connection's already-committed row.
    $rolledBack = false;
    Event::listen(TransactionRolledBack::class, function () use (&$rolledBack, $simulateWinnerCommit): void {
        if (! $rolledBack) {
            $rolledBack = true;
            $simulateWinnerCommit();
        }
    });

    $result = app(ResolveIdempotentWrite::class)->handle(
        'create-task:race',
        'issue',
        fn (int $id) => Issue::find($id),
        function () use ($simulateWinnerCommit): Issue {
            $simulateWinnerCommit();

            return Issue::factory()->create(['title' => 'Loser']);
        },
    );

    expect($result->id)->toBe($winner->id)
        ->and(Issue::query()->where('title', 'Loser')->exists())->toBeFalse()
        ->and(McpWriteReceipt::query()->where('idempotency_key', 'create-task:race')->sole()->subject_id)->toBe($winner->id);
});

it('reports a handled error when a concurrent duplicate leaves no result to return', function (): void {
    expect(fn () => app(ResolveIdempotentWrite::class)->handle(
        'create-task:race-lost',
        'issue',
        fn (int $id) => Issue::find($id),
        function (): Issue {
            DB::table('mcp_write_receipts')->insert(['idempotency_key' => 'create-task:race-lost', 'subject_type' => 'issue', 'subject_id' => 999, 'created_at' => now(), 'updated_at' => now()]);

            return Issue::factory()->create(['title' => 'Loser']);
        },
    ))->toThrow(TodoValidationException::class, 'collided with a concurrent call');

    expect(Issue::query()->count())->toBe(0);
});

it('replays a real create without a second issue or push', function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);

    $first = app(CreateTodoIssue::class)->handle(title: 'Replayed create', idempotencyKey: 'tool:replay');
    $second = app(CreateTodoIssue::class)->handle(title: 'Replayed create', idempotencyKey: 'tool:replay');

    expect($second->id)->toBe($first->id)
        ->and(Issue::query()->where('title', 'Replayed create')->count())->toBe(1)
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->count())->toBe(1);
});
