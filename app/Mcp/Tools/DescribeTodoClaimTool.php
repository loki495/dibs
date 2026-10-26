<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoClaim;
use App\Actions\DescribeTodoClaims;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Supply issueId for the original single response, or issueIds (1–100) for ordered items with id, claimed and claim, or a per-id error for missing/unavailable issues. Duplicates are preserved. Reports whether a task currently has a live claim — agent name, pid, whether liveness is verified, a direct liveness check (isCurrentlyAlive), the background watcher\'s last recorded check (recordedLiveness, display-only), and when the lease expires — without exposing any capability token. Use before todo_claim to check if a task is free.')]
class DescribeTodoClaimTool extends Tool implements Errable
{
    protected string $name = 'todo_claim_status';

    public function handle(Request $request, DescribeTodoClaim $describe, DescribeTodoClaims $batch): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'issueId' => ['required_without:issueIds', 'prohibits:issueIds', 'integer', 'min:1'],
            'issueIds' => ['required_without:issueId', 'prohibits:issueId', 'array', 'list', 'min:1', 'max:'.DescribeTodoClaims::MAX_IDS],
            'issueIds.*' => ['required', 'integer', 'min:1'],
        ]);

        if (isset($arguments['issueIds'])) {
            return Response::structured($batch->handle(array_map(intval(...), $arguments['issueIds'])));
        }

        $claim = $describe->handle($arguments['issueId']);

        return Response::structured(['claimed' => $claim !== null, 'claim' => $claim]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issueId' => $schema->integer()->min(1)->description('One local issue id. Supply exactly one of issueId or issueIds; issueId keeps the original response.'),
            'issueIds' => $schema->array()->items($schema->integer()->min(1))->min(1)->max(DescribeTodoClaims::MAX_IDS)->description('Up to 100 local issue ids, in result order, including duplicates. Returns items with id, claimed and claim, or a per-id error for missing/unavailable issues. Never returns capability tokens.'),
        ];
    }
}
