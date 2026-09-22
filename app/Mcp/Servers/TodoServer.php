<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Methods\InitializeLegacyClient;
use App\Mcp\Methods\LogToolCall;
use App\Mcp\Tools\ClaimTodoTask;
use App\Mcp\Tools\CommentOnTodoTask;
use App\Mcp\Tools\CompleteTodoTaskTool;
use App\Mcp\Tools\CreateTodoTask;
use App\Mcp\Tools\DescribeTodoClaimTool;
use App\Mcp\Tools\DescribeTodoContext;
use App\Mcp\Tools\DescribeTodoIssue;
use App\Mcp\Tools\DescribeTodoMetadata;
use App\Mcp\Tools\DescribeTodoQueueStatus;
use App\Mcp\Tools\DescribeTodoServer;
use App\Mcp\Tools\HeartbeatTodoTask;
use App\Mcp\Tools\ListTodoTasks;
use App\Mcp\Tools\ReleaseTodoTask;
use App\Mcp\Tools\ReportBugTool;
use App\Mcp\Tools\ReviseTodoTask;
use App\Mcp\Tools\ScaffoldTodoPlan;
use App\Mcp\Tools\SearchTodoIssues;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Dibs')]
#[Version('0.1.0')]
#[Instructions('Host-local server for this Dibs workspace. Local SQLite is authoritative; GitHub is an asynchronous mirror reached through a push queue. Call todo_status first to confirm the server and repository identity before using other tools.')]
class TodoServer extends Server
{
    protected array $tools = [
        DescribeTodoServer::class,
        DescribeTodoContext::class,
        DescribeTodoMetadata::class,
        ListTodoTasks::class,
        SearchTodoIssues::class,
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
        $this->addMethod('tools/call', LogToolCall::class);
    }
}
