<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\McpWriteReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ResolveIdempotentWrite
{
    /**
     * Return the previous result for an idempotency key already seen, without
     * repeating the write. Otherwise perform the write and record its receipt in
     * the same transaction, so a write never commits without its receipt.
     *
     * @template TModel of Model
     *
     * @param  callable(int): (TModel|null)  $find  Resolve the previously created subject by its id.
     * @param  callable(): TModel  $create  Perform the write and return the new subject.
     * @return TModel
     */
    public function handle(string $idempotencyKey, string $subjectType, callable $find, callable $create): Model
    {
        try {
            // The write Actions' own transactions become savepoints of this one. attempts: 3
            // retries the whole check-write-receipt unit on SQLite's transient "database is
            // locked", which is also what a DEFERRED transaction raises when another writer
            // committed after this one's receipt lookup.
            return DB::transaction(function () use ($idempotencyKey, $subjectType, $find, $create): Model {
                $receipt = McpWriteReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
                $existing = $this->replay($receipt, $idempotencyKey, $subjectType, $find);
                if ($existing instanceof Model) {
                    return $existing;
                }

                $subject = $create();
                if ($receipt instanceof McpWriteReceipt) {
                    $receipt->update(['subject_id' => $subject->getKey()]);
                } else {
                    McpWriteReceipt::query()->create([
                        'idempotency_key' => $idempotencyKey,
                        'subject_type' => $subjectType,
                        'subject_id' => $subject->getKey(),
                    ]);
                }

                return $subject;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent call with the same key committed its receipt first; this call's
            // write was rolled back with it, so answer with the winner's result. With no
            // receipt for the key the violation came from the write itself (e.g. a label name
            // created concurrently), not from the key, so it is not ours to explain.
            $receipt = McpWriteReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
            if (! $receipt instanceof McpWriteReceipt) {
                throw $exception;
            }
            $existing = $this->replay($receipt, $idempotencyKey, $subjectType, $find);
            if ($existing instanceof Model) {
                return $existing;
            }

            throw new TodoValidationException("Another write with idempotency key [{$idempotencyKey}] collided with a concurrent call whose result is no longer available. Retry with a new key.", 0, $exception);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  callable(int): (TModel|null)  $find
     * @return TModel|null
     */
    private function replay(?McpWriteReceipt $receipt, string $idempotencyKey, string $subjectType, callable $find): ?Model
    {
        if (! $receipt instanceof McpWriteReceipt) {
            return null;
        }
        if ($receipt->subject_type !== $subjectType) {
            throw new TodoValidationException("Idempotency key [{$idempotencyKey}] was already used for a {$receipt->subject_type} write; use a new key for this {$subjectType} write.");
        }

        return $find((int) $receipt->subject_id);
    }
}
