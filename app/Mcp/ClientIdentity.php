<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * The client name a pre-2026-07-28 client gave in its `initialize` handshake. Newer clients send it
 * with every request instead, so this is only the fallback. A singleton: one stdio server process
 * serves exactly one connection, and JSON-RPC method handlers are rebuilt per request.
 */
final class ClientIdentity
{
    private ?string $name = null;

    public function remember(?string $name): void
    {
        $this->name = $name !== null && $name !== '' ? $name : null;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
