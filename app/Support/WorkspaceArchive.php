<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class WorkspaceArchive
{
    public const string FORMAT = 'dibs-workspace';

    public const int VERSION = 1;

    /** @var list<string> */
    public const array TABLES = [
        'repositories', 'projects', 'project_fields', 'project_field_options',
        'issues', 'labels', 'issue_label', 'project_items', 'comments',
        'capture_settings', 'todo_capture_requests', 'mcp_call_logs', 'change_logs',
    ];

    /** @var array<string, string> */
    public const array SECTION_LABELS = [
        'repositories' => 'Repositories', 'projects' => 'Projects',
        'project_fields' => 'Project fields', 'project_field_options' => 'Groups, statuses and priorities',
        'issues' => 'Tasks and knowledge records', 'labels' => 'Labels',
        'issue_label' => 'Task label assignments', 'project_items' => 'Project memberships and schedules',
        'comments' => 'Comments and closing notes', 'capture_settings' => 'Capture settings',
        'todo_capture_requests' => 'Capture drafts', 'mcp_call_logs' => 'MCP activity history',
        'change_logs' => 'Change history',
    ];

    public static function isEmpty(): bool
    {
        return array_all([...self::TABLES, 'github_push_queue', 'sync_states', 'agent_sessions', 'task_claims', 'mcp_write_receipts'], fn (string $table): bool => ! DB::table($table)->exists());
    }
}
