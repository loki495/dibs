<?php

declare(strict_types=1);

$optionalId = static fn (?string $value): ?int => $value === null || $value === '' ? null : (int) $value;

return [
    'timezone' => env('DIBS_TIMEZONE', 'UTC'),
    'trusted_proxies' => array_filter(explode(',', env('TRUSTED_PROXIES', ''))),
    // Seconds a Livewire request may run before the browser gives up on it, shows the reconnect banner and
    // frees the request queue. 0 disables the timeout (failed requests still show the banner).
    'livewire_request_timeout_seconds' => (int) env('DIBS_LIVEWIRE_REQUEST_TIMEOUT', 20),
    // Milliseconds a request or page navigation must take before the loading bar shows and the lists dim,
    // so quick ones don't flicker.
    'loading_indicator_delay_ms' => (int) env('DIBS_LOADING_INDICATOR_DELAY_MS', 150),
    'push_queue_ui_enabled' => (bool) env('DIBS_PUSH_QUEUE_UI_ENABLED', true),

    // Whether this process can see the agents' PIDs (/proc of the host PID namespace) to verify claim
    // liveness. docker-compose.yml sets it false for the web service, which runs without pid: host;
    // there, claims show "liveness unverifiable" instead of being judged dead. See LinuxProcessLiveness.
    'process_liveness' => (bool) env('DIBS_PROCESS_LIVENESS', true),

    // dibs:claims:watch (the app container's main process) re-checks every live claim's process this
    // often and records the result, so the web UI can show it. The UI ignores a recording older than
    // stale_after_seconds (default three intervals) and shows "liveness unverifiable" instead.
    'claim_liveness' => [
        'watch_interval_seconds' => (int) env('DIBS_CLAIM_LIVENESS_INTERVAL', 30),
        'stale_after_seconds' => (int) env('DIBS_CLAIM_LIVENESS_STALE_AFTER', 90),
    ],

    // Retry budget for a push-queue row whose GitHub call failed transiently (network, 5xx, rate
    // limit). Each failure waits base * 2^(failures - 1) seconds, capped at backoff_cap_seconds, or
    // until the time GitHub gives for a rate limit; the row needs attention once max_attempts
    // calls have failed. The defaults spread 8 attempts over about two hours.
    'push_queue' => [
        'max_attempts' => (int) env('DIBS_PUSH_MAX_ATTEMPTS', 8),
        'backoff_base_seconds' => (int) env('DIBS_PUSH_BACKOFF_BASE_SECONDS', 30),
        'backoff_cap_seconds' => (int) env('DIBS_PUSH_BACKOFF_CAP_SECONDS', 3600),
    ],

    // Where todo_report_bug files its issues. Unset (the default) leaves them unparented,
    // same as before this existed. See docs/agent-interface.md for the todo_report_bug tool.
    'agent_report_area_id' => $optionalId(env('DIBS_AGENT_REPORT_AREA_ID')),
    'agent_report_group_id' => $optionalId(env('DIBS_AGENT_REPORT_GROUP_ID')),
    'agent_report_parent_id' => $optionalId(env('DIBS_AGENT_REPORT_PARENT_ID')),

    // Activity log (MCP call log + change log). Retention is in days per log; 0 keeps rows forever.
    // Stored argument/diff values longer than max_value_bytes are truncated, and any key containing
    // one of redact_keys (case-insensitive) is replaced with a placeholder before it is stored.
    'activity' => [
        'mcp_retention_days' => (int) env('DIBS_ACTIVITY_MCP_RETENTION_DAYS', 30),
        'change_retention_days' => (int) env('DIBS_ACTIVITY_CHANGE_RETENTION_DAYS', 30),
        'max_value_bytes' => (int) env('DIBS_ACTIVITY_MAX_VALUE_BYTES', 2048),
        'redact_keys' => ['token', 'secret', 'password', 'authorization', 'key'],
    ],

    // Public demo instance only -- see docs/demo-hosting.md. When true, ResolveDemoDatabase
    // gives every visitor their own private copy of demo_db_template_path (identified by a
    // cookie) instead of one database shared by every concurrent visitor, and demo:cleanup
    // is scheduled to delete stale per-visitor copies. Never set this outside that deployment.
    'demo_mode' => (bool) env('DIBS_DEMO_MODE', false),
    'demo_db_template_path' => env('DEMO_DB_TEMPLATE_PATH', storage_path('demo-template.sqlite')),
    'demo_db_storage_path' => env('DEMO_DB_STORAGE_PATH', storage_path('demo-dbs')),
    // Most per-visitor copies kept at once; making one more deletes the least recently written. Bounds
    // demo_db_storage_path to this many times the template's size.
    'demo_max_instances' => (int) env('DEMO_MAX_INSTANCES', 2000),

    // Opt-in owner auto-login (any deployment), see AutoLoginForTrustedRequests. All off by default.
    // Account to sign in as; defaults to the demo account in demo mode. Never created, must already exist.
    'auto_login_email' => env('AUTO_LOGIN_EMAIL'),
    'auto_login_lan' => (bool) env('AUTO_LOGIN_LAN', false),
    'demo_login_email' => env('DEMO_LOGIN_EMAIL', 'demo@example.com'),
];
