<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What to narrow an activity log by. Blank strings mean "no filter". `type` is the tool name for the
 * MCP log and the action name for the change log; `status` applies to the MCP log only, `category`
 * and `source` to the change log only, and a filter that doesn't apply to the log is ignored.
 * Dates are Y-m-d, inclusive, in the DIBS_TIMEZONE zone.
 */
final readonly class ActivityLogFilters
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?string $type = null,
        public ?string $status = null,
        public ?string $category = null,
        public ?string $source = null,
        public ?string $search = null,
        public ?string $requestId = null,
    ) {}
}
