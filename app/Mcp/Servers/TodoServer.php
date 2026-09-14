<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Methods\InitializeLegacyClient;
use App\Mcp\Tools\ClaimTodoTask;
use App\Mcp\Tools\CommentOnTodoTask;
use App\Mcp\Tools\CompleteTodoTaskTool;
use App\Mcp\Tools\CreateTodoTask;
use App\Mcp\Tools\DescribeTodoClaimTool;
use App\Mcp\Tools\DescribeTodoContext;
use App\Mcp\Tools\DescribeTodoIssue;
use App\Mcp\Tools\DescribeTodoQueueStatus;
use App\Mcp\Tools\DescribeTodoServer;
use App\Mcp\Tools\HeartbeatTodoTask;
use App\Mcp\Tools\ListTodoTasks;
use App\Mcp\Tools\ReleaseTodoTask;
use App\Mcp\Tools\ReportBugTool;
use App\Mcp\Tools\ReviseTodoTask;
use App\Mcp\Tools\ScaffoldTodoPlan;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

#[Name('Dibs')]
#[Version('0.1.0')]
#[Instructions('Host-local server for this Dibs workspace. Local SQLite is authoritative; GitHub is an asynchronous mirror reached through a push queue. Call todo_status first to confirm the server and repository identity before using other tools.')]
class TodoServer extends Server
{
    /**
     * Set once a pre-2026-07-28 client is detected (no `_meta` on any request, starting with
     * `initialize` itself) so every later request on this same connection is also exempted from
     * the new spec's `_meta` requirement. Safe as instance state: one TodoServer instance only
     * ever serves the single stdio connection it was spawned for.
     */
    private bool $legacyClient = false;

    protected array $tools = [
        DescribeTodoServer::class,
        DescribeTodoContext::class,
        ListTodoTasks::class,
        DescribeTodoIssue::class,
        DescribeTodoQueueStatus::class,
        CreateTodoTask::class,
        ScaffoldTodoPlan::class,
        ReviseTodoTask::class,
        CommentOnTodoTask::class,
        ClaimTodoTask::class,
        HeartbeatTodoTask::class,
        ReleaseTodoTask::class,
        CompleteTodoTaskTool::class,
        DescribeTodoClaimTool::class,
        ReportBugTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];

    protected function boot(): void
    {
        $this->addMethod('initialize', InitializeLegacyClient::class);
    }

    protected function validateProtocolMeta(JsonRpcRequest $request, ServerContext $context): void
    {
        if ($this->legacyClient || ($request->method === 'initialize' && $request->meta() === null)) {
            $this->legacyClient = true;

            return;
        }

        parent::validateProtocolMeta($request, $context);
    }
}
