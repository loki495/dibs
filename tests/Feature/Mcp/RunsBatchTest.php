<?php

declare(strict_types=1);

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Mcp\Tools\Concerns\RunsBatch;
use Illuminate\Validation\ValidationException;

/**
 * Stands in for a tool that uses the concern: runBatch is otherwise only reached
 * through a full MCP tool call, which hides the per-item error handling.
 */
final class RunsBatchSubject
{
    use RunsBatch;

    /**
     * @param  list<string>  $items
     * @param  callable(string): array<string, mixed>  $one
     * @return array<string, mixed>
     */
    public function run(array $items, callable $one): array
    {
        return $this->runBatch($items, $one)->mergeStructuredContent([])['structuredContent'];
    }
}

it('runs every item in order and counts the successes', function (): void {
    $result = (new RunsBatchSubject)->run(['a', 'b', 'c'], fn (string $item): array => ['name' => $item]);

    expect($result['items'])->toBe([
        ['index' => 0, 'name' => 'a'],
        ['index' => 1, 'name' => 'b'],
        ['index' => 2, 'name' => 'c'],
    ])
        ->and($result['succeeded'])->toBe(3)
        ->and($result['failed'])->toBe(0);
});

it('keeps going after a failing item and reports the error at its own index', function (): void {
    $calls = 0;

    $result = (new RunsBatchSubject)->run(['first', 'refuses', 'last'], function (string $item) use (&$calls): array {
        $calls++;

        if ($item === 'refuses') {
            throw new DomainException('this one refuses');
        }

        return ['name' => $item];
    });

    expect($calls)->toBe(3)
        ->and($result['succeeded'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and($result['items'][0])->toBe(['index' => 0, 'name' => 'first'])
        ->and($result['items'][1])->toBe(['index' => 1, 'error' => 'this one refuses'])
        ->and($result['items'][2])->toBe(['index' => 2, 'name' => 'last']);
});

it('joins every message of a validation exception into one error', function (): void {
    $result = (new RunsBatchSubject)->run(['x'], function (): array {
        throw ValidationException::withMessages([
            'title' => ['The title field is required.'],
            'area' => ['That area does not exist.'],
        ]);
    });

    expect($result['failed'])->toBe(1)
        ->and($result['items'][0]['index'])->toBe(0)
        ->and($result['items'][0]['error'])->toContain('The title field is required.')
        ->and($result['items'][0]['error'])->toContain('That area does not exist.')
        ->and($result['succeeded'])->toBe(0);
});

it('reports every handled domain exception with its own message', function (Throwable $thrown, string $message): void {
    $result = (new RunsBatchSubject)->run(['x'], function () use ($thrown): array {
        throw $thrown;
    });

    expect($result['failed'])->toBe(1)
        ->and($result['items'][0])->toBe(['index' => 0, 'error' => $message])
        ->and($result['succeeded'])->toBe(0);
})->with([
    'domain' => [new DomainException('domain refuses'), 'domain refuses'],
    'record not found' => [new TodoRecordNotFoundException('no such record'), 'no such record'],
    'record unavailable' => [new TodoRecordUnavailableException('record unavailable'), 'record unavailable'],
    'stale revision' => [new TodoStaleRevisionException(42, 'revision moved on'), 'revision moved on'],
    'todo validation' => [new TodoValidationException('not allowed here'), 'not allowed here'],
]);

it('reports an empty batch as nothing done and nothing failed', function (): void {
    $result = (new RunsBatchSubject)->run([], fn (string $item): array => ['name' => $item]);

    expect($result['items'])->toBe([])
        ->and($result['succeeded'])->toBe(0)
        ->and($result['failed'])->toBe(0);
});
