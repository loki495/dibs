<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DescribeTodoQueueStatus as DescribeTodoQueueStatusAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Reports the durable outbound GitHub push-queue status: pending/failed/needs_attention counts, plus a bounded, most-recently-updated list of failed and needs_attention items with a human-readable target description, attempt count, and last error. A local write always succeeds immediately in SQLite; this tool is how an agent checks whether that write has actually reached GitHub yet.')]
class DescribeTodoQueueStatus extends Tool implements Errable
{
    protected string $name = 'todo_queue_status';

    public function handle(Request $request, DescribeTodoQueueStatusAction $describe): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.DescribeTodoQueueStatusAction::MAX_ITEMS],
        ]);

        return Response::structured($describe->handle(limit: $arguments['limit'] ?? DescribeTodoQueueStatusAction::DEFAULT_ITEMS));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(DescribeTodoQueueStatusAction::MAX_ITEMS)->default(DescribeTodoQueueStatusAction::DEFAULT_ITEMS)->description('Maximum number of actionable (failed/needs_attention) items to return.'),
        ];
    }
}
