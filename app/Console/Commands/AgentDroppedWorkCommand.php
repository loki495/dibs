<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\FindDroppedWork;
use App\Support\IssueFilters;
use Illuminate\Console\Command;

class AgentDroppedWorkCommand extends Command
{
    protected $signature = 'todo:agent:dropped {--path= : Only claims whose paths contain or lie under this absolute path} {--area=* : Project (Area) id or title, repeatable} {--group=* : Group id or name, repeatable} {--parent= : Only tasks under this local issue id} {--descendants : With --parent, the whole tree} {--limit=50 : At most this many (max 100)}';

    protected $description = 'List open tasks that were started and left (lease lapsed unreleased, or released as abandoned) as JSON';

    public function handle(FindDroppedWork $dropped): int
    {
        $ids = fn (array $values): array => array_map(intval(...), array_values(array_filter($values, is_numeric(...))));
        $names = fn (array $values): array => array_values(array_filter($values, fn ($v): bool => ! is_numeric($v)));
        $areas = (array) $this->option('area');
        $groups = (array) $this->option('group');
        $filters = new IssueFilters(
            areas: $ids($areas), areaNames: $names($areas), groups: $ids($groups), groupNames: $names($groups),
            parentId: $this->option('parent') ? (int) $this->option('parent') : null, descendants: (bool) $this->option('descendants'),
        );
        $this->line(json_encode($dropped->handle($this->option('path') ?: null, (int) $this->option('limit'), $filters), JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
