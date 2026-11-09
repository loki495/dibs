<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Support\WriteWarnings;
use DomainException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait RunsBatch
{
    public const int MAX_BATCH = 20;

    /**
     * Runs $one for each item independently, best effort: a failing item reports its error and
     * does not stop or roll back the others.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): array<string, mixed>  $one
     */
    protected function runBatch(array $items, callable $one): ResponseFactory
    {
        $results = [];
        $failed = 0;

        foreach ($items as $index => $item) {
            try {
                $results[] = ['index' => $index] + $one($item);
            } catch (ValidationException $exception) {
                $failed++;
                $results[] = ['index' => $index, 'error' => implode(' ', $exception->validator->errors()->all())];
            } catch (DomainException|TodoRecordNotFoundException|TodoRecordUnavailableException|TodoStaleRevisionException|TodoValidationException $exception) {
                $failed++;
                $results[] = ['index' => $index, 'error' => $exception->getMessage()];
            }
        }

        return Response::structured(app(WriteWarnings::class)->attachTo(['items' => $results, 'succeeded' => count($items) - $failed, 'failed' => $failed]));
    }
}
