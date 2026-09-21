<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\Activity\ActivityRecorder;
use App\Support\ActivityLog;

/**
 * Empties one activity log on request (the Activity page's Clear button). Clearing is itself
 * recorded in the change log, after the delete so the audit row survives, saying who cleared
 * how many entries. An already-empty log is a no-op and leaves no row.
 */
class ClearActivityLog
{
    public const string MCP_CALLS = ActivityLog::MCP_CALLS;

    public const string CHANGES = ActivityLog::CHANGES;

    public function __construct(private readonly ActivityRecorder $recorder) {}

    /** $log arrives from a client-controlled Livewire property, so anything but the two names is rejected at runtime. */
    public function handle(string $log): int
    {
        $query = ActivityLog::query($log);
        $name = $log === self::MCP_CALLS ? 'MCP call log' : 'change log';

        $deleted = $query->delete();

        if ($deleted === 0) {
            return 0;
        }

        $this->recorder->change(
            'ClearActivityLog',
            null,
            sprintf('Cleared the %s (%d %s)', $name, $deleted, $deleted === 1 ? 'entry' : 'entries'),
            ['entries_deleted' => ['from' => $deleted, 'to' => 0]],
        );

        return $deleted;
    }
}
