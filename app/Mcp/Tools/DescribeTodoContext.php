<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoContext as DescribeTodoContextAction;
use App\Mcp\Tools\Concerns\DeclaresPlaceholderArgument;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('The entry point for a newly started worker or subagent: the available areas (GitHub Projects) with open-task counts, Groups scoped to their area, available labels, currently live task claims (who holds what, and whether their process liveness was verified), and the push-queue snapshot. Call this first to orient, then use todo_list/todo_show to go deeper into one area or task.')]
class DescribeTodoContext extends Tool
{
    use DeclaresPlaceholderArgument;

    protected string $name = 'todo_context';

    public function handle(Request $request, DescribeTodoContextAction $describe): Response|ResponseFactory
    {
        return Response::structured($describe->handle());
    }
}
