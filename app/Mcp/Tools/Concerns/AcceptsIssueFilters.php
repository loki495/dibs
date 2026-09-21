<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Support\IssueFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** The filter arguments todo_list and todo_search share: their validation, their declared schema and the mapping onto IssueFilters. */
trait AcceptsIssueFilters
{
    private const int MAX_FILTER_ITEMS = 20;

    private const int MAX_FILTER_NAME_LENGTH = 100;

    private const array FILTER_NAME_LISTS = ['labels', 'anyLabels', 'excludeLabels', 'areaNames', 'groupNames'];

    private const array FILTER_ID_LISTS = ['areas', 'groups'];

    /** @return array<string, list<string>> */
    private function filterRules(): array
    {
        $rules = ['descendants' => ['sometimes', 'boolean']];

        foreach (self::FILTER_NAME_LISTS as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:'.self::MAX_FILTER_ITEMS];
            $rules[$key.'.*'] = ['string', 'max:'.self::MAX_FILTER_NAME_LENGTH];
        }

        foreach (self::FILTER_ID_LISTS as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:'.self::MAX_FILTER_ITEMS];
            $rules[$key.'.*'] = ['integer', 'min:1'];
        }

        return $rules;
    }

    /**
     * Laravel would print `anyLabels.0` as "any labels.0"; keep the argument names callers actually sent.
     *
     * @return array<string, string>
     */
    private function filterAttributes(): array
    {
        $attributes = [];

        foreach ([...self::FILTER_NAME_LISTS, ...self::FILTER_ID_LISTS] as $key) {
            $attributes[$key] = $key;
            $attributes[$key.'.*'] = $key.'.:index';
        }

        return $attributes;
    }

    /** @param  array<string, mixed>  $arguments  validated arguments */
    private function issueFiltersFrom(array $arguments): IssueFilters
    {
        $strings = fn (string $key): array => array_map(strval(...), array_values((array) ($arguments[$key] ?? [])));
        $ids = fn (string $key): array => array_map(intval(...), array_values((array) ($arguments[$key] ?? [])));

        return new IssueFilters(
            areas: $ids('areas'),
            areaNames: $strings('areaNames'),
            groups: $ids('groups'),
            groupNames: $strings('groupNames'),
            labels: $strings('labels'),
            anyLabels: $strings('anyLabels'),
            excludeLabels: $strings('excludeLabels'),
            parentId: isset($arguments['parentId']) ? (int) $arguments['parentId'] : null,
            descendants: (bool) ($arguments['descendants'] ?? false),
        );
    }

    /** @return array<string, Type> */
    private function filterSchema(JsonSchema $schema): array
    {
        $names = fn (string $description): Type => $schema->array()->items($schema->string()->max(self::MAX_FILTER_NAME_LENGTH))->max(self::MAX_FILTER_ITEMS)->description($description);
        $ids = fn (string $description): Type => $schema->array()->items($schema->integer()->min(1))->max(self::MAX_FILTER_ITEMS)->description($description);

        return [
            'labels' => $names('Label names every result must carry (all of them). Case-insensitive; labels are stored lowercase.'),
            'anyLabels' => $names('Label names of which every result must carry at least one.'),
            'excludeLabels' => $names('Label names no result may carry.'),
            'areas' => $ids('Project ids; a result may be in any of them.'),
            'areaNames' => $names('Project titles (case-insensitive) instead of, or as well as, ids.'),
            'groups' => $ids('Group option ids; a result may be in any of them. Must hold on the same Project membership as `areas`.'),
            'groupNames' => $names('Group names (case-insensitive), matched in every Project that has a Group of that name.'),
            'descendants' => $schema->boolean()->default(false)->description('With `parentId`, include the whole tree beneath that issue instead of only its direct children.'),
        ];
    }
}
