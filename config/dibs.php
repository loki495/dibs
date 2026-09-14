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
];
