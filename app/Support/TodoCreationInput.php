<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\SyncStepFields;

final class TodoCreationInput
{
    private const int MAX_STEP_LENGTH = 4000;

    /** @return array<string, list<string>> */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'area' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parentId' => ['sometimes', 'integer', 'min:1'],
            'groupId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priorityId' => ['sometimes', 'integer', 'min:1'],
            'labelIds' => ['sometimes', 'array'],
            'labelIds.*' => ['integer'],
            'newGroupName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'newLabelName' => ['sometimes', 'nullable', 'string', 'max:50'],
            'labelNames' => ['sometimes', 'array'],
            'labelNames.*' => ['string', 'max:50'],
            'idempotencyKey' => ['sometimes', 'nullable', 'string', 'max:255'],
            'addLinks' => ['sometimes', 'array', 'max:50'],
            'addLinks.*.issueId' => ['required', 'integer', 'min:1'],
            'addLinks.*.commentId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'step' => ['sometimes', 'array:'.implode(',', array_keys(SyncStepFields::FIELDS))],
            'blockedBy' => ['sometimes', 'array', 'max:50'],
            'blockedBy.*' => ['integer', 'min:1'],
        ];
        foreach (array_keys(SyncStepFields::FIELDS) as $field) {
            $rules['step.'.$field] = ['sometimes', 'nullable', 'string', 'max:'.self::MAX_STEP_LENGTH];
        }

        return array_combine(array_map(fn (string $key): string => $prefix.$key, array_keys($rules)), array_values($rules));
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return array_values(array_filter(array_keys(self::rules()), fn (string $key): bool => ! str_contains($key, '.')));
    }

    /** @param array<string, mixed> $input validated task fields
     * @return array<string, mixed>
     */
    public static function arguments(array $input): array
    {
        return [
            'title' => $input['title'],
            'body' => $input['body'] ?? null,
            'area' => isset($input['area']) ? (int) $input['area'] : null,
            'parentId' => isset($input['parentId']) ? (int) $input['parentId'] : null,
            'groupId' => isset($input['groupId']) ? (int) $input['groupId'] : null,
            'priorityId' => isset($input['priorityId']) ? (int) $input['priorityId'] : null,
            'labelIds' => array_values(array_map(intval(...), $input['labelIds'] ?? [])),
            'newGroupName' => $input['newGroupName'] ?? null,
            'newLabelNames' => array_values(array_filter([...($input['labelNames'] ?? []), $input['newLabelName'] ?? null], is_string(...))),
            'idempotencyKey' => $input['idempotencyKey'] ?? null,
            'addLinks' => $input['addLinks'] ?? [],
            'step' => $input['step'] ?? [],
            'blockedBy' => isset($input['blockedBy']) ? array_values(array_map(intval(...), $input['blockedBy'])) : null,
        ];
    }
}
