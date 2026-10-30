<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\FindNextPlanStep;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoValidationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Picks the next step of a plan: the first child, in plan order, that is open, not held by a live claim and not blocked (all its blockedBy steps closed). Returns {ready: <work order>} — the step\'s objective, Done-when, Evidence, Files likely, blockers, linked rules (id and title; todo_claim sends their text), other links and last checkpoint, capped at about 1500 tokens — or, when nothing is ready, {ready: null, reason: plan_done | all_claimed | blocked | mixed, waiting: [{id, title, claimedBy?, blockedBy?}]}. Read-only; claim the step with todo_claim to start it.')]
class NextTodoStep extends Tool implements Errable
{
    protected string $name = 'todo_next';

    public function handle(Request $request, FindNextPlanStep $next): Response|ResponseFactory
    {
        $arguments = $request->validate(['planId' => ['required', 'integer', 'min:1']]);

        try {
            return Response::structured($next->handle($arguments['planId']));
        } catch (TodoRecordNotFoundException|TodoValidationException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'planId' => $schema->integer()->required()->description('The local id of the plan (any issue whose children are its steps).'),
        ];
    }
}
