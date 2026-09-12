<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ClaimTaskForAgent;
use App\Models\Issue;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Claims a task for the calling agent process, so other agents cannot pick up the same work. The caller\'s own OS process is identified automatically — an MCP server\'s parent process IS the connecting agent, so no pid argument is needed. Returns a capability token: keep it in memory and pass it to todo_heartbeat, todo_release, and todo_complete for this same claim. A task already claimed by a live process cannot be claimed again.')]
class ClaimTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_claim';

    public function handle(Request $request, ClaimTaskForAgent $claim): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
            'agentName' => ['required', 'string', 'max:100'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
        ]);

        $issue = Issue::query()->where('is_available', true)->find($arguments['issueId']);
        if (! $issue instanceof Issue) {
            return Response::error("Issue {$arguments['issueId']} was not found or is not available.");
        }

        try {
            $result = $claim->handle($issue, $arguments['agentName'], posix_getppid(), $arguments['minutes'] ?? 30);
        } catch (DomainException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured([
            'claimId' => $result['claim']->id,
            'issueId' => $result['claim']->issue_id,
            'expiresAt' => Carbon::parse($result['claim']->expires_at)->toAtomString(),
            'capabilityToken' => $result['capability_token'],
            'isVerifiedLive' => $result['is_verified_live'],
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->required()->description('The local issue id to claim.'),
            'agentName' => $schema->string()->required()->description('A human-readable name for this agent/session.'),
            'minutes' => $schema->integer()->description('Claim duration in minutes, 1 to 480. Defaults to 30.'),
        ];
    }
}
