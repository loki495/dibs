<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Models\ChangeLog;
use App\Models\Issue;
use App\Models\McpCallLog;
use App\Services\Activity\ActivityContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Runs streamed responses immediately, unlike the plain recording transport used for the handshake tests. */
class CallLogTransport implements Transport
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

    public function stream(Closure $stream): void
    {
        $stream();
    }
}

/** @param  array<string, mixed>  $extraMeta */
function callLogMeta(?string $client = 'claude-code', array $extraMeta = []): array
{
    return ['_meta' => [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ...($client === null ? [] : ['io.modelcontextprotocol/clientInfo' => ['name' => $client, 'version' => '1.0']]),
        ...$extraMeta,
    ]];
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function callTool(TodoServer $server, CallLogTransport $transport, string $tool, array $arguments = [], ?string $client = 'claude-code'): array
{
    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments] + callLogMeta($client),
    ]));

    return $transport->sent[array_key_last($transport->sent)];
}

function loggingServer(?CallLogTransport &$transport = null): TodoServer
{
    $transport = new CallLogTransport;
    $server = new TodoServer($transport);
    $server->start();

    return $server;
}

function onlyCallLog(): McpCallLog
{
    expect(McpCallLog::count())->toBe(1);

    return McpCallLog::query()->firstOrFail();
}

beforeEach(function (): void {
    app()->forgetScopedInstances();
});

it('logs a successful read with its tool, arguments, duration, agent and request id, and leaves the response alone', function (): void {
    $server = loggingServer($transport);

    $response = callTool($server, $transport, 'todo_status');

    $log = onlyCallLog();

    expect($response)->not->toHaveKey('error')
        ->and($response['result']['isError'])->toBeFalse()
        ->and($log->tool)->toBe('todo_status')
        ->and($log->status)->toBe(McpCallLog::STATUS_OK)
        ->and($log->arguments)->toBe([])
        ->and($log->error_message)->toBeNull()
        ->and($log->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($log->agent_label)->toBe('claude-code')
        ->and(Str::isUuid($log->request_id))->toBeTrue();
});

it('begins an MCP activity context for the call so changes it makes share the request id', function (): void {
    $server = loggingServer($transport);

    callTool($server, $transport, 'todo_status');

    $context = app(ActivityContext::class);

    expect($context->source())->toBe(ChangeLog::SOURCE_MCP)
        ->and($context->actorType())->toBe(ChangeLog::ACTOR_AGENT)
        ->and($context->actorLabel())->toBe('claude-code')
        ->and($context->requestId())->toBe(onlyCallLog()->request_id);
});

it('gives every call its own request id', function (): void {
    $server = loggingServer($transport);

    callTool($server, $transport, 'todo_status');
    callTool($server, $transport, 'todo_status');

    expect(McpCallLog::query()->pluck('request_id')->unique())->toHaveCount(2);
});

it('logs the arguments a client sent', function (): void {
    $issue = Issue::factory()->create();
    $server = loggingServer($transport);

    callTool($server, $transport, 'todo_show', ['id' => $issue->id, 'withComments' => true]);

    expect(onlyCallLog()->arguments)->toBe(['id' => $issue->id, 'withComments' => true]);
});

it('never stores a capability token, and still logs the failed call', function (): void {
    $issue = Issue::factory()->create();
    $server = loggingServer($transport);

    $response = callTool($server, $transport, 'todo_heartbeat', ['issueId' => $issue->id, 'capabilityToken' => 'sekrit-token-123']);

    $log = onlyCallLog();

    expect($response['result']['isError'])->toBeTrue()
        ->and($log->status)->toBe(McpCallLog::STATUS_ERROR)
        ->and($log->arguments)->toBe(['issueId' => $issue->id, 'capabilityToken' => '[redacted]'])
        ->and($log->error_message)->not->toBeEmpty()
        ->and(json_encode($log->getAttributes()))->not->toContain('sekrit-token-123');
});

it('logs a call that fails validation as an error with the validation message', function (): void {
    $server = loggingServer($transport);

    $response = callTool($server, $transport, 'todo_revise', []);

    $log = onlyCallLog();

    expect($response['result']['isError'])->toBeTrue()
        ->and($log->status)->toBe(McpCallLog::STATUS_ERROR)
        ->and($log->error_message)->toBe($response['result']['content'][0]['text']);
});

it('logs a stale-revision response as a conflict rather than an error', function (): void {
    $issue = Issue::factory()->create(['revision' => 1]);
    $issue->update(['revision' => 2]);
    $server = loggingServer($transport);

    $response = callTool($server, $transport, 'todo_revise', ['id' => $issue->id, 'expectedRevision' => 1, 'title' => 'Mine']);

    $log = onlyCallLog();

    expect($response['result']['isError'])->toBeFalse()
        ->and($log->status)->toBe(McpCallLog::STATUS_CONFLICT)
        ->and($log->error_message)->toBeNull();
});

it('logs a call to an unknown tool and still answers with the protocol error', function (): void {
    $server = loggingServer($transport);

    $response = callTool($server, $transport, 'todo_nope');

    $log = onlyCallLog();

    expect($response['error']['code'])->toBe(-32602)
        ->and($log->tool)->toBe('todo_nope')
        ->and($log->status)->toBe(McpCallLog::STATUS_ERROR)
        ->and($log->error_message)->toBe('Tool [todo_nope] not found.');
});

it('logs a tools/call with no tool name and still answers with the protocol error', function (): void {
    $server = loggingServer($transport);

    $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => callLogMeta()]));

    $log = onlyCallLog();

    expect($transport->sent[0]['error']['code'])->toBe(-32602)
        ->and($log->tool)->toBe('(none)')
        ->and($log->status)->toBe(McpCallLog::STATUS_ERROR)
        ->and($log->error_message)->toBe('Missing [name] parameter.');
});

it('attributes a call from a legacy client to the name it gave at initialize', function (): void {
    $server = loggingServer($transport);

    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'opencode', 'version' => '1.0']],
    ]));
    $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'todo_status', 'arguments' => (object) []]]));

    expect(onlyCallLog()->agent_label)->toBe('opencode');
});

it('prefers the per-request client info over the one given at initialize', function (): void {
    $server = loggingServer($transport);

    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'opencode']],
    ]));
    callTool($server, $transport, 'todo_status', [], 'claude-code');

    expect(onlyCallLog()->agent_label)->toBe('claude-code');
});

it('logs an unknown agent as a null label instead of inventing one', function (): void {
    $server = loggingServer($transport);

    callTool($server, $transport, 'todo_status', [], null);

    expect(onlyCallLog()->agent_label)->toBeNull();
});

it('does not log methods other than tools/call', function (): void {
    $server = loggingServer($transport);

    $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => callLogMeta()]));

    expect(McpCallLog::count())->toBe(0);
});

it('logs a raw JSON-RPC error response (not thrown, not result.isError) as an error', function (): void {
    // Distinct from the "unknown tool" test above: this covers CallTool returning a
    // JsonRpcResponse whose content itself is a top-level {error: {message}} shape,
    // rather than throwing or returning {result: {isError: true}}.
    app()->bind(CallTool::class, fn (): CallTool => new class extends CallTool
    {
        public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
        {
            return JsonRpcResponse::error($request->id, -32000, 'raw protocol error');
        }
    });
    $server = loggingServer($transport);

    callTool($server, $transport, 'todo_status');

    $log = onlyCallLog();
    expect($log->status)->toBe(McpCallLog::STATUS_ERROR)
        ->and($log->error_message)->toBe('raw protocol error');
});

it('logs an ok status when the response has neither a result nor an error', function (): void {
    app()->bind(CallTool::class, fn (): CallTool => new class extends CallTool
    {
        public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
        {
            return new JsonRpcResponse(['jsonrpc' => '2.0', 'id' => $request->id]);
        }
    });
    $server = loggingServer($transport);

    callTool($server, $transport, 'todo_status');

    $log = onlyCallLog();
    expect($log->status)->toBe(McpCallLog::STATUS_OK)
        ->and($log->error_message)->toBeNull();
});

describe('when the underlying call blows up', function (): void {
    beforeEach(function (): void {
        app()->bind(CallTool::class, fn (): CallTool => new class extends CallTool
        {
            public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
            {
                throw new RuntimeException('boom');
            }
        });
    });

    it('records the exception and rethrows it in debug', function (): void {
        config(['app.debug' => true]);
        $server = loggingServer($transport);

        try {
            callTool($server, $transport, 'todo_status');
            $this->fail('The exception should have surfaced in debug.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('boom');
        }

        $log = onlyCallLog();

        expect($log->status)->toBe(McpCallLog::STATUS_EXCEPTION)
            ->and($log->error_message)->toBe('boom')
            ->and($log->tool)->toBe('todo_status');
    });

    it('records the exception and leaves the framework to answer with a generic error outside debug', function (): void {
        config(['app.debug' => false]);
        Exceptions::fake();
        $server = loggingServer($transport);

        $response = callTool($server, $transport, 'todo_status');

        expect($response['error']['message'])->toBe('Something went wrong while processing the request.')
            ->and(onlyCallLog()->status)->toBe(McpCallLog::STATUS_EXCEPTION);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'boom');
    });
});

describe('when the call streams its response', function (): void {
    it('logs a streamed call once, as ok, and passes every message through', function (): void {
        app()->bind(CallTool::class, fn (): CallTool => new class extends CallTool
        {
            /** @return Generator<JsonRpcResponse> */
            public function handle(JsonRpcRequest $request, ServerContext $context): Generator
            {
                yield JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);
                yield JsonRpcResponse::result($request->id, ['content' => [['type' => 'text', 'text' => 'done']], 'isError' => false]);
            }
        });
        $server = loggingServer($transport);

        callTool($server, $transport, 'todo_status');

        $log = onlyCallLog();

        expect($transport->sent)->toHaveCount(2)
            ->and($transport->sent[0]['method'])->toBe('notifications/progress')
            ->and($transport->sent[1]['result']['content'][0]['text'])->toBe('done')
            ->and($log->status)->toBe(McpCallLog::STATUS_OK);
    });

    it('logs a streamed call that reports an error as an error', function (): void {
        app()->bind(CallTool::class, fn (): CallTool => new class extends CallTool
        {
            /** @return Generator<JsonRpcResponse> */
            public function handle(JsonRpcRequest $request, ServerContext $context): Generator
            {
                yield JsonRpcResponse::result($request->id, ['content' => [['type' => 'text', 'text' => 'nope']], 'isError' => true]);
            }
        });
        $server = loggingServer($transport);

        callTool($server, $transport, 'todo_status');

        expect(onlyCallLog()->status)->toBe(McpCallLog::STATUS_ERROR)
            ->and(onlyCallLog()->error_message)->toBe('nope');
    });

    it('records a stream that throws midway as an exception and rethrows it in debug', function (): void {
        config(['app.debug' => true]);
        app()->bind(CallTool::class, fn (): CallTool => new class extends CallTool
        {
            /** @return Generator<JsonRpcResponse> */
            public function handle(JsonRpcRequest $request, ServerContext $context): Generator
            {
                yield JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);

                throw new RuntimeException('stream broke');
            }
        });
        $server = loggingServer($transport);

        expect(fn () => callTool($server, $transport, 'todo_status'))->toThrow(RuntimeException::class, 'stream broke');

        expect(onlyCallLog()->status)->toBe(McpCallLog::STATUS_EXCEPTION)
            ->and(onlyCallLog()->error_message)->toBe('stream broke');
    });
});

describe('when the log itself cannot be written', function (): void {
    it('still answers the call outside debug and reports the failure', function (): void {
        config(['app.debug' => false]);
        Exceptions::fake();
        Schema::drop('mcp_call_logs');
        $server = loggingServer($transport);

        $response = callTool($server, $transport, 'todo_status');

        expect($response)->not->toHaveKey('error')
            ->and($response['result']['isError'])->toBeFalse();
        Exceptions::assertReported(QueryException::class);
    });

    it('surfaces the failure in debug', function (): void {
        config(['app.debug' => true]);
        Schema::drop('mcp_call_logs');
        $server = loggingServer($transport);

        expect(fn () => callTool($server, $transport, 'todo_status'))->toThrow(QueryException::class);
    });
});
