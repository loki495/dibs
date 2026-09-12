<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\HeartbeatTaskClaim;
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

#[Description('Renews the calling agent\'s own live claim lease. Call this periodically while work is in progress; it creates no GitHub comment or push-queue entry. Fails if the claim was released, expired, taken over, or if this process is no longer verifiably the one that holds it.')]
class HeartbeatTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_heartbeat';

    public function handle(Request $request, HeartbeatTaskClaim $heartbeat): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
            'capabilityToken' => ['required', 'string'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
        ]);

        try {
            $claim = $heartbeat->handle($arguments['issueId'], posix_getppid(), $arguments['capabilityToken'], $arguments['minutes'] ?? 30);
        } catch (DomainException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured([
            'claimId' => $claim->id,
            'issueId' => $claim->issue_id,
            'expiresAt' => Carbon::parse($claim->expires_at)->toAtomString(),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->required()->description('The claimed issue\'s local id.'),
            'capabilityToken' => $schema->string()->required()->description('The token returned by todo_claim for this claim.'),
            'minutes' => $schema->integer()->description('New claim duration in minutes, 1 to 480. Defaults to 30.'),
        ];
    }
}
