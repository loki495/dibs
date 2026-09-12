<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('todo', TodoServer::class);
