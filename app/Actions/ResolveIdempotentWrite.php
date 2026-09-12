<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\McpWriteReceipt;
use Illuminate\Database\Eloquent\Model;

class ResolveIdempotentWrite
{
    /**
     * Return the previous result for an idempotency key already seen, without
     * repeating the write. Otherwise perform the write once and remember it.
     *
     * @template TModel of Model
     *
     * @param  callable(int): (TModel|null)  $find  Resolve the previously created subject by its id.
     * @param  callable(): TModel  $create  Perform the write and return the new subject.
     * @return TModel
     */
    public function handle(string $idempotencyKey, string $subjectType, callable $find, callable $create): Model
    {
        $receipt = McpWriteReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($receipt instanceof McpWriteReceipt) {
            $existing = $find($receipt->subject_id);
            if ($existing instanceof Model) {
                return $existing;
            }
        }

        $subject = $create();
        McpWriteReceipt::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            ['subject_type' => $subjectType, 'subject_id' => $subject->getKey()],
        );

        return $subject;
    }
}
