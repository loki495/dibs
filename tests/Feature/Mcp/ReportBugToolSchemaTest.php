<?php

declare(strict_types=1);

use App\Mcp\Tools\ReportBugTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('declares the expected input schema for tools/list introspection', function (): void {
    $schema = app(ReportBugTool::class)->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['summary', 'details', 'toolOrCommand', 'arguments', 'idempotencyKey']);
});
