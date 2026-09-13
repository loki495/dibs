<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use Illuminate\Http\Response;
use Laravel\Mcp\Server\Contracts\Transport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Drives raw JSON-RPC strings through TodoServer::handle() directly (bypassing the framework's
 * `TodoServer::tool(...)` testing DSL, which never exercises protocol negotiation) so this proves
 * the exact behavior a real stdio-connected client would see.
 */
class RecordingTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function onReceive(Closure $handler): void {}

    public function send(string $message): void
    {
        $this->sent[] = json_decode($message, true);
    }

    public function run(): Response|StreamedResponse
    {
        throw new LogicException('Not implemented.');
    }

    public function stream(Closure $stream): void {}
}

function bootTodoServer(RecordingTransport $transport): TodoServer
{
    $server = new TodoServer($transport);
    $server->start();

    return $server;
}

function sendRawRpc(TodoServer $server, RecordingTransport $transport, array $message): array
{
    $server->handle(json_encode($message));

    return $transport->sent[array_key_last($transport->sent)];
}

it('answers a pre-2026-07-28 initialize handshake instead of rejecting it', function (): void {
    $transport = new RecordingTransport;
    $server = bootTodoServer($transport);

    $response = sendRawRpc($server, $transport, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0']],
    ]);

    expect($response)->not->toHaveKey('error')
        ->and($response['result']['protocolVersion'])->toBe('2025-11-25')
        ->and($response['result']['serverInfo']['name'])->toBe('Todo');
});

it('falls back to a supported legacy version when the client requests an unrecognized one', function (): void {
    $transport = new RecordingTransport;
    $server = bootTodoServer($transport);

    $response = sendRawRpc($server, $transport, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '1999-01-01', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0']],
    ]);

    expect($response['result']['protocolVersion'])->toBe('2025-11-25');
});

it('serves tool calls without a _meta block once a legacy client has initialized', function (): void {
    $transport = new RecordingTransport;
    $server = bootTodoServer($transport);

    sendRawRpc($server, $transport, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0']],
    ]);

    $response = sendRawRpc($server, $transport, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => (object) []]);

    expect($response)->not->toHaveKey('error')
        ->and($response['result']['tools'])->not->toBeEmpty();
});

it('still enforces _meta on a new-style client that never sent a legacy initialize', function (): void {
    $transport = new RecordingTransport;
    $server = bootTodoServer($transport);

    $response = sendRawRpc($server, $transport, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);

    expect($response)->toHaveKey('error')
        ->and($response['error']['code'])->toBe(-32602);
});

it('still serves a new-style client that sends the required _meta block', function (): void {
    $transport = new RecordingTransport;
    $server = bootTodoServer($transport);

    $response = sendRawRpc($server, $transport, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []]],
    ]);

    expect($response)->not->toHaveKey('error')
        ->and($response['result']['tools'])->not->toBeEmpty();
});
