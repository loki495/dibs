<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\DescribeTodoContext;
use App\Mcp\Tools\DescribeTodoIssue;
use App\Mcp\Tools\DescribeTodoQueueStatus;
use App\Mcp\Tools\DescribeTodoServer;
use App\Mcp\Tools\ListTodoTasks;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Todo')]
#[Version('0.1.0')]
#[Instructions('Host-local server for Andres\'s private Todo workspace. Local SQLite is authoritative; GitHub is an asynchronous mirror reached through a push queue. Call todo_status first to confirm the server and repository identity before using other tools.')]
class TodoServer extends Server
{
    protected array $tools = [
        DescribeTodoServer::class,
        DescribeTodoContext::class,
        ListTodoTasks::class,
        DescribeTodoIssue::class,
        DescribeTodoQueueStatus::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
