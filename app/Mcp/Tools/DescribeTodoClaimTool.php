<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoClaim;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Reports whether a task currently has a live claim — agent name, pid, whether liveness is verified, and when the lease expires — without exposing any capability token. Use before todo_claim to check if a task is free.')]
class DescribeTodoClaimTool extends Tool implements Errable
{
    protected string $name = 'todo_claim_status';

    public function handle(Request $request, DescribeTodoClaim $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required', 'integer', 'min:1'],
        ]);

        $claim = $describe->handle($arguments['issueId']);

        return Response::structured(['claimed' => $claim !== null, 'claim' => $claim]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->required()->description('The local issue id to check.'),
        ];
    }
}
