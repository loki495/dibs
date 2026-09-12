<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoServer;
use App\Models\GitHubRepository;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

it('reports server status through the todo_status tool', function (): void {
    GitHubRepository::factory()->create(['full_name' => 'loki495/Todo', 'is_available' => true]);

    TodoServer::tool(DescribeTodoServer::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('loki495/Todo');
});

it('exposes the tool under the todo_status name', function (): void {
    $tool = app(DescribeTodoServer::class);

    expect($tool->name())->toBe('todo_status');
});

it('rejects a call to a tool not registered on the server without crashing', function (): void {
    $unregistered = new class extends Tool
    {
        protected string $name = 'not_a_registered_tool';

        public function handle(Request $request): Response
        {
            return Response::text('unreachable');
        }
    };

    TodoServer::tool($unregistered)->assertHasErrors();
});
