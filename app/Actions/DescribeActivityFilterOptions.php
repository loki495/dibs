<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\ActivityLog;

/** The values recorded so far that an activity log can be filtered by, for the page's dropdowns. */
class DescribeActivityFilterOptions
{
    /** @return array{types: list<string>, statuses: list<string>, categories: list<string>, sources: list<string>} */
    public function handle(string $log): array
    {
        $isMcp = $log === ActivityLog::MCP_CALLS;

        return [
            'types' => $this->distinct($log, $isMcp ? 'tool' : 'action'),
            'statuses' => $isMcp ? $this->distinct($log, 'status') : [],
            'categories' => $isMcp ? [] : $this->distinct($log, 'category'),
            'sources' => $isMcp ? [] : $this->distinct($log, 'source'),
        ];
    }

    /** @return list<string> */
    private function distinct(string $log, string $column): array
    {
        return ActivityLog::query($log)->distinct()->orderBy($column)->pluck($column)->map(fn (mixed $value): string => (string) $value)->values()->all();
    }
}
