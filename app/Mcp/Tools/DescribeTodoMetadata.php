<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoMetadata as DescribeTodoMetadataAction;
use App\Mcp\Tools\Concerns\DeclaresPlaceholderArgument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Everything a caller can attach to or use for a task: areas (Projects) with open-task counts, Groups and Priority options with their ids and areas, and labels with ids, descriptions and usage counts, plus the rules for attaching or creating each. Search names with `query` (label descriptions too), narrow with `kinds` and `area`. Use it before todo_create or todo_scaffold_plan to get ids, or to check whether a label or Group already exists before creating one.')]
class DescribeTodoMetadata extends Tool implements Errable
{
    use DeclaresPlaceholderArgument;

    protected string $name = 'todo_metadata';

    public function handle(Request $request, DescribeTodoMetadataAction $describe): ResponseFactory
    {
        $arguments = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:100'],
            'kinds' => ['sometimes', 'array', 'max:'.count(DescribeTodoMetadataAction::KINDS)],
            'kinds.*' => ['string', 'in:'.implode(',', DescribeTodoMetadataAction::KINDS)],
            'area' => ['sometimes', 'integer', 'min:1'],
        ], attributes: ['kinds' => 'kinds', 'kinds.*' => 'kinds.:index']);

        return Response::structured($describe->handle(
            query: $arguments['query'] ?? null,
            kinds: array_values(array_map(strval(...), $arguments['kinds'] ?? [])),
            area: isset($arguments['area']) ? (int) $arguments['area'] : null,
        ));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->placeholderSchema($schema),
            'query' => $schema->string()->max(100)->description('Matches names case-insensitively (and label descriptions). Literal substring.'),
            'kinds' => $schema->array()->items($schema->string()->enum(DescribeTodoMetadataAction::KINDS))->max(count(DescribeTodoMetadataAction::KINDS))->description('Which of areas, groups, priorities and labels to return; all when omitted.'),
            'area' => $schema->integer()->min(1)->description('An area (Project) id; scopes areas, groups and priorities to it. Labels are not per area.'),
        ];
    }
}
