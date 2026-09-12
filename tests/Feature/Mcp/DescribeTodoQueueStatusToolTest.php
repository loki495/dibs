<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoQueueStatus;
use App\Models\GitHubPushQueueItem;

it('exposes the tool under the todo_queue_status name', function (): void {
    expect(app(DescribeTodoQueueStatus::class)->name())->toBe('todo_queue_status');
});

it('reports queue status through the todo_queue_status tool', function (): void {
    GitHubPushQueueItem::factory()->create(['status' => 'needs_attention', 'last_error' => 'conflict']);

    TodoServer::tool(DescribeTodoQueueStatus::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('conflict');
});

it('rejects a limit above the maximum', function (): void {
    TodoServer::tool(DescribeTodoQueueStatus::class, ['limit' => 1000])
        ->assertHasErrors();
});
