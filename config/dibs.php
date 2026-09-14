<?php

declare(strict_types=1);

$optionalId = static fn (?string $value): ?int => $value === null || $value === '' ? null : (int) $value;

return [
    'timezone' => env('DIBS_TIMEZONE', 'America/Los_Angeles'),
    'trusted_proxies' => array_filter(explode(',', env('TRUSTED_PROXIES', ''))),
    'push_queue_ui_enabled' => (bool) env('DIBS_PUSH_QUEUE_UI_ENABLED', true),

    // Where todo_report_bug files its issues. Unset (the default) leaves them unparented,
    // same as before this existed. See docs/agent-interface.md for the todo_report_bug tool.
    'agent_report_area_id' => $optionalId(env('DIBS_AGENT_REPORT_AREA_ID')),
    'agent_report_group_id' => $optionalId(env('DIBS_AGENT_REPORT_GROUP_ID')),
    'agent_report_parent_id' => $optionalId(env('DIBS_AGENT_REPORT_PARENT_ID')),

    // Public demo instance only -- see docs/demo-hosting.md. When true, ResolveDemoDatabase
    // gives every visitor their own private copy of demo_db_template_path (identified by a
    // cookie) instead of one database shared by every concurrent visitor, and demo:cleanup
    // is scheduled to delete stale per-visitor copies. Never set this outside that deployment.
    'demo_mode' => (bool) env('DIBS_DEMO_MODE', false),
    'demo_db_template_path' => env('DEMO_DB_TEMPLATE_PATH', storage_path('demo-template.sqlite')),
    'demo_db_storage_path' => env('DEMO_DB_STORAGE_PATH', storage_path('demo-dbs')),
];
