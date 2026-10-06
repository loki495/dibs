<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BuildIssueTree;
use App\Models\Issue;
use App\Support\TrustedAuthors;
use Illuminate\Console\Command;

class AgentListTasksCommand extends Command
{
    protected $signature = 'todo:agent:list {--area=0} {--group=0} {--priority=0} {--knowledge : Include knowledge records}';

    protected $description = 'Read Todo tasks as JSON for a host-local agent';

    public function handle(BuildIssueTree $tree, TrustedAuthors $trust): int
    {
        $result = $tree->handle((int) $this->option('area'), $this->option('knowledge') ? 'knowledge' : 'tasks', group: (int) $this->option('group'), priority: (int) $this->option('priority'));
        $this->line(json_encode(['tasks' => $this->withholdUntrustedTitles($result['rows'], $trust), 'sync' => $result['sync']?->last_success_at?->toAtomString()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * BuildIssueTree's rows are shared with the web UI, which shows every title; agents get the same
     * withholding as the MCP tools (see TrustedAuthors).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withholdUntrustedTitles(array $rows, TrustedAuthors $trust): array
    {
        $authors = Issue::query()->whereIn('id', array_filter([...array_column($rows, 'id'), ...array_column($rows, 'parent')], is_int(...)))->pluck('author_login', 'id');
        $withhold = fn (mixed $id): bool => is_int($id) && ! $trust->allows($authors[$id] ?? null);

        return array_map(fn (array $row): array => [
            ...$row,
            'title' => $withhold($row['id']) ? TrustedAuthors::withheld($authors[$row['id']]) : $row['title'],
            'parentTitle' => isset($row['parentTitle']) && $withhold($row['parent'] ?? null) ? TrustedAuthors::withheld($authors[$row['parent']] ?? null) : ($row['parentTitle'] ?? null),
        ], $rows);
    }
}
