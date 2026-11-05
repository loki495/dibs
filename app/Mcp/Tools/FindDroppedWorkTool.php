<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\FindDroppedWork;
use App\Mcp\Tools\Concerns\AcceptsIssueFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Tool;

#[Description('Lists open tasks that were started and left: the latest claim lapsed without release or completion (idle session, quota end, crash) or was released as abandoned, and nobody holds the task now. Each item has issueId, title, temporary, how (lapsed or abandoned), agentName, lastSeenAt, endedAt and the claim\'s paths. With no arguments it is global; narrow it with areas/areaNames (project), groups/groupNames, labels, or parentId (+ descendants for the whole tree), or by a path prefix, and limit the count. Check it at session start and before filing new work, then todo_claim an item to resume it.')]
class FindDroppedWorkTool extends Tool implements Errable
{
    use AcceptsIssueFilters;

    protected string $name = 'todo_dropped';

    public function handle(Request $request, FindDroppedWork $dropped): Response|ResponseFactory
    {
        $arguments = $request->validate([
            'path' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.FindDroppedWork::MAX_LIMIT],
            'parentId' => ['nullable', 'integer', 'min:1'],
            ...$this->filterRules(),
        ], [], $this->filterAttributes());

        return Response::structured($dropped->handle($arguments['path'] ?? null, (int) ($arguments['limit'] ?? 50), $this->issueFiltersFrom($arguments)));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Only items whose claim paths contain or lie under this absolute path (the workdir).'),
            'parentId' => $schema->integer()->min(1)->description('Only tasks under this local issue id: its direct children, or with `descendants` the whole tree beneath it.'),
            ...$this->filterSchema($schema),
            'limit' => $schema->integer()->min(1)->max(FindDroppedWork::MAX_LIMIT)->description('At most this many items, default 50.'),
        ];
    }
}
