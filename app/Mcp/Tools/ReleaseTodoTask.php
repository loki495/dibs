<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ReleaseTaskClaim;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Releases the calling agent\'s own live claim without completing the task — use this when abandoning work so another agent can pick it up immediately instead of waiting for the lease to expire.')]
class ReleaseTodoTask extends Tool implements Errable
{
    protected string $name = 'todo_release';

    public function handle(Request $request, ReleaseTaskClaim $release): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
            'capabilityToken' => ['required', 'string'],
        ]);

        try {
            $claim = $release->handle($arguments['issueId'], posix_getppid(), $arguments['capabilityToken']);
        } catch (DomainException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured(['claimId' => $claim->id, 'issueId' => $claim->issue_id, 'released' => true]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->required()->description('The claimed issue\'s local id.'),
            'capabilityToken' => $schema->string()->required()->description('The token returned by todo_claim for this claim.'),
        ];
    }
}
