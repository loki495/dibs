<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\ClientIdentity;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers a pre-2026-07-28 `initialize` handshake for MCP clients whose own implementation
 * hasn't caught up to the stateless spec yet (confirmed against Claude Code's and opencode's
 * MCP clients, both of which still send this) — see the "keep bespoke legacy-MCP handshake
 * support" decision record in Dibs for why this stays until those clients move on.
 *
 * laravel/mcp 1.0.0 added first-class support for this exact dual-protocol scenario
 * (`JsonRpcRequest::isLegacy()` gates `_meta` validation before it ever runs, and the base
 * `Initialize` method already does the protocol-version negotiation/response shape below) —
 * this class only adds the one piece of real Dibs-specific value on top: remembering the
 * client's name for later use via `ClientIdentity`.
 */
class InitializeLegacyClient extends Initialize
{
    public function __construct(private readonly ClientIdentity $client) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $clientInfo = $request->get('clientInfo');
        $this->client->remember(is_array($clientInfo) && is_string($clientInfo['name'] ?? null) ? $clientInfo['name'] : null);

        return parent::handle($request, $context);
    }
}
