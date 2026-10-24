<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/** The `replace` argument todo_comment and todo_revise share; App\Support\TextReplacements applies it. */
final class ReplaceArgument
{
    /**
     * @param  array<int, array<string, mixed>>  $replace
     * @return list<array{from: string, to: string, all: bool}>
     */
    public static function normalize(array $replace): array
    {
        return array_values(array_map(fn (array $edit): array => [
            'from' => (string) $edit['from'],
            'to' => (string) ($edit['to'] ?? ''),
            'all' => (bool) ($edit['all'] ?? false),
        ], $replace));
    }

    public static function schema(JsonSchema $schema, string $what): Type
    {
        return $schema->array()->items($schema->object([
            'from' => $schema->string()->required(),
            'to' => $schema->string()->required(),
            'all' => $schema->boolean(),
        ]))->description("Exact-string edits applied in order to the {$what}'s current text under the same expectedRevision, instead of resending it whole: each `from` must occur exactly once (or pass all: true to replace every occurrence), else nothing is applied and an error says which edit failed.");
    }
}
