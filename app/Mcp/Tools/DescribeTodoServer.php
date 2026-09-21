<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoServer as DescribeTodoServerAction;
use App\Mcp\Tools\Concerns\DeclaresPlaceholderArgument;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reports the Todo MCP server and repository identity: app name/environment, the configured GitHub owner/repository, whether it has been imported locally, and basic record counts. Use this to verify the server and database are reachable before calling other tools.')]
class DescribeTodoServer extends Tool
{
    use DeclaresPlaceholderArgument;

    protected string $name = 'todo_status';

    public function handle(Request $request, DescribeTodoServerAction $describe): ResponseFactory
    {
        return Response::structured($describe->handle());
    }
}
