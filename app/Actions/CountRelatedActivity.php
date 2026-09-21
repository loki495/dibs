<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ChangeLog;
use App\Models\McpCallLog;

/** How many entries in each activity log came from one request, to link an MCP call to the changes it made. */
class CountRelatedActivity
{
    /** @return array{mcp: int, changes: int} */
    public function handle(string $requestId): array
    {
        if ($requestId === '') {
            return ['mcp' => 0, 'changes' => 0];
        }

        return [
            'mcp' => McpCallLog::query()->where('request_id', $requestId)->count(),
            'changes' => ChangeLog::query()->where('request_id', $requestId)->count(),
        ];
    }
}
