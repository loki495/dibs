<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BuildIssueTree;
use Illuminate\Console\Command;

class AgentListTasksCommand extends Command
{
    protected $signature = 'todo:agent:list {--area=0} {--group=0} {--priority=0} {--knowledge : Include knowledge records}';

    protected $description = 'Read Todo tasks as JSON for a host-local agent';

    public function handle(BuildIssueTree $tree): int
    {
        $result = $tree->handle((int) $this->option('area'), $this->option('knowledge') ? 'knowledge' : 'tasks', group: (int) $this->option('group'), priority: (int) $this->option('priority'));
        $this->line(json_encode(['tasks' => $result['rows'], 'sync' => $result['sync']?->last_success_at?->toAtomString()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
