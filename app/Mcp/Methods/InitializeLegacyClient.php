<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\ClientIdentity;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers a pre-2026-07-28 `initialize` handshake for MCP clients whose own implementation
 * hasn't caught up to the stateless spec yet (confirmed against Claude Code's and opencode's
 * MCP clients, both of which still send this). Paired with TodoServer::validateProtocolMeta()
 * relaxing its `_meta` requirement once such a client is detected.
 */
class InitializeLegacyClient implements Method
{
    public function __construct(private readonly ClientIdentity $client) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $clientInfo = $request->get('clientInfo');
        $this->client->remember(is_array($clientInfo) && is_string($clientInfo['name'] ?? null) ? $clientInfo['name'] : null);

        $requested = $request->get('protocolVersion');
        $version = is_string($requested) && in_array($requested, ProtocolVersion::initializeSupported(), true)
            ? $requested
            : ProtocolVersion::V2025_11_25->value;

        return JsonRpcResponse::result($request->id, [
            'protocolVersion' => $version,
            'capabilities' => $context->serverCapabilities ?: (object) [],
            'serverInfo' => $context->implementation->toArray(),
            'instructions' => $context->instructions,
        ]);
    }
}
