<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\ClientIdentity;
use App\Models\McpCallLog;
use App\Services\Activity\ActivityContext;
use App\Services\Activity\ActivityRecorder;
use Generator;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Registered for `tools/call` in place of the framework's CallTool: starts the MCP activity context
 * for the call, delegates to the real CallTool, and records the outcome. Never changes the response
 * and never swallows an exception -- it records, then rethrows.
 *
 * A tool that throws is already turned into an error response by the framework's ToolInvoker before
 * it gets here, so that case is logged as an error; `exception` is for anything that escapes CallTool.
 */
class LogToolCall implements Method
{
    private const string UNKNOWN_TOOL = '(none)';

    public function __construct(
        private readonly CallTool $callTool,
        private readonly ActivityContext $context,
        private readonly ActivityRecorder $recorder,
        private readonly ClientIdentity $client,
    ) {}

    /** @return Generator<JsonRpcResponse>|JsonRpcResponse */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $agent = $this->agentLabel($request);
        $this->context->beginMcp($agent);
        $startedAt = hrtime(true);

        try {
            $response = $this->callTool->handle($request, $context);
        } catch (Throwable $e) {
            $this->record($request, $agent, $startedAt, ...$this->outcomeOfFailure($e));

            throw $e;
        }

        if ($response instanceof Generator) {
            return $this->recordStream($response, $request, $agent, $startedAt);
        }

        $this->record($request, $agent, $startedAt, ...$this->outcomeOf($response));

        return $response;
    }

    /**
     * @param  Generator<JsonRpcResponse>  $stream
     * @return Generator<JsonRpcResponse>
     */
    private function recordStream(Generator $stream, JsonRpcRequest $request, ?string $agent, int|float $startedAt): Generator
    {
        $outcome = [McpCallLog::STATUS_ERROR, 'The response stream was closed before it finished.'];
        $final = null;

        try {
            foreach ($stream as $message) {
                if (array_key_exists('result', $message->content) || array_key_exists('error', $message->content)) {
                    $final = $message;
                }

                yield $message;
            }

            $outcome = $final instanceof JsonRpcResponse ? $this->outcomeOf($final) : [McpCallLog::STATUS_OK, null];
        } catch (Throwable $e) {
            $outcome = $this->outcomeOfFailure($e);

            throw $e;
        } finally {
            $this->record($request, $agent, $startedAt, ...$outcome);
        }
    }

    private function record(JsonRpcRequest $request, ?string $agent, int|float $startedAt, string $status, ?string $error): void
    {
        $name = $request->params['name'] ?? null;
        $arguments = $request->params['arguments'] ?? [];

        $this->recorder->mcpCall(
            is_string($name) && $name !== '' ? $name : self::UNKNOWN_TOOL,
            is_array($arguments) ? $arguments : [],
            $status,
            $error,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
            $agent,
        );
    }

    /** @return array{string, string} */
    private function outcomeOfFailure(Throwable $e): array
    {
        return [$e instanceof JsonRpcException ? McpCallLog::STATUS_ERROR : McpCallLog::STATUS_EXCEPTION, $e->getMessage()];
    }

    /** @return array{string, string|null} */
    private function outcomeOf(JsonRpcResponse $response): array
    {
        if (isset($response->content['error']['message']) && is_string($response->content['error']['message'])) {
            return [McpCallLog::STATUS_ERROR, $response->content['error']['message']];
        }

        $result = $response->content['result'] ?? null;

        if (! is_array($result)) {
            return [McpCallLog::STATUS_OK, null];
        }

        if (($result['isError'] ?? false) === true) {
            return [McpCallLog::STATUS_ERROR, $this->textOf($result)];
        }

        return [$this->isConflict($result) ? McpCallLog::STATUS_CONFLICT : McpCallLog::STATUS_OK, null];
    }

    /** @param  array<array-key, mixed>  $result */
    private function isConflict(array $result): bool
    {
        $structured = $result['structuredContent'] ?? null;

        if (is_array($structured)) {
            return ($structured['conflict'] ?? false) === true;
        }

        $decoded = json_decode($this->textOf($result), true);

        return is_array($decoded) && ($decoded['conflict'] ?? false) === true;
    }

    /** @param  array<array-key, mixed>  $result */
    private function textOf(array $result): string
    {
        $texts = [];
        foreach ((array) ($result['content'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
            }
        }

        return implode("\n", $texts);
    }

    private function agentLabel(JsonRpcRequest $request): ?string
    {
        $name = ($request->meta()[MetaKey::CLIENT_INFO->value]['name'] ?? null);

        return is_string($name) && $name !== '' ? $name : $this->client->name();
    }
}
