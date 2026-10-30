<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Actions\SyncStepFields;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** The `step` and `blockedBy` arguments todo_create, todo_revise and todo_scaffold_plan children share; SyncStepFields applies them. */
final class StepArgument
{
    public const int MAX_FIELD_LENGTH = 4000;

    /** @return array<string, list<string>> validation rules, keys prefixed with $prefix (e.g. "children.*.") */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            $prefix.'step' => ['sometimes', 'array:'.implode(',', array_keys(SyncStepFields::FIELDS))],
            $prefix.'blockedBy' => ['sometimes', 'array', 'max:50'],
            $prefix.'blockedBy.*' => ['integer', 'min:'.($prefix === '' ? 1 : 0)],
        ];
        foreach (array_keys(SyncStepFields::FIELDS) as $key) {
            $rules[$prefix.'step.'.$key] = ['sometimes', 'nullable', 'string', 'max:'.self::MAX_FIELD_LENGTH];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{step: array<string, ?string>, blockedBy: list<int>|null}
     */
    public static function normalize(array $arguments): array
    {
        return [
            'step' => array_intersect_key($arguments['step'] ?? [], SyncStepFields::FIELDS),
            'blockedBy' => isset($arguments['blockedBy']) ? array_values(array_map(intval(...), $arguments['blockedBy'])) : null,
        ];
    }

    /** @return array<string, Type> */
    public static function schema(JsonSchema $schema, string $blockedByMeaning = 'Local ids of sibling steps (same parent) this step waits on; it is not ready until they all close. Replaces the whole list; [] clears it. Self, cross-plan and cyclic edges are refused.'): array
    {
        $field = fn (string $what) => $schema->string()->nullable()->description($what.' Null or blank clears it.');

        return [
            'step' => $schema->object([
                'doneWhen' => $field('When the step may close: what must be true and what evidence is recorded.'),
                'evidence' => $field('The evidence its completion records (commit SHA, test command and exit code, docs).'),
                'filesLikely' => $field('The files or areas the step probably touches.'),
            ])->description('Plan step fields, kept as data and returned in todo_show `step` and the work order; only the keys given change, and the body is not touched. An empty field falls back to the matching "Done-when:", "Evidence:" or "Files likely:" body line.'),
            'blockedBy' => $schema->array()->items($schema->integer())->description($blockedByMeaning),
        ];
    }
}
