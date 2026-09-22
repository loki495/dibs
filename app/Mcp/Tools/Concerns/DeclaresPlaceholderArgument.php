<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

trait DeclaresPlaceholderArgument
{
    /**
     * Some MCP clients fail the call outright when a tool's input is an empty object (Claude Code's
     * canUseTool callback: "invalid permission result"), so a tool whose callers may have nothing to send
     * still declares one argument that the handler never reads. It is marked required in the schema, because
     * a client that treats it as optional just omits it and sends the empty object again; laravel/mcp
     * does not enforce the schema, so callers that leave it out still succeed.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->placeholderSchema($schema);
    }

    /** @return array<string, Type> */
    private function placeholderSchema(JsonSchema $schema): array
    {
        return [
            'noop' => $schema->boolean()->required()->description('Ignored. Always pass true: it only exists so clients that mishandle an empty input object can still call this tool.'),
        ];
    }
}
