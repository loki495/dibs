<?php

declare(strict_types=1);

return [
    'timezone' => env('TODO_TIMEZONE', 'America/Los_Angeles'),
    'trusted_proxies' => array_filter(explode(',', env('TRUSTED_PROXIES', ''))),
    'active_poll_seconds' => (int) env('TODO_ACTIVE_POLL_SECONDS', 10),
    'idle_poll_seconds' => (int) env('TODO_IDLE_POLL_SECONDS', 60),
    'active_sync_seconds' => (int) env('TODO_ACTIVE_SYNC_SECONDS', 30),
    'idle_sync_seconds' => (int) env('TODO_IDLE_SYNC_SECONDS', 60),
    'background_sync_seconds' => (int) env('TODO_BACKGROUND_SYNC_SECONDS', 300),
    'stale_seconds' => (int) env('TODO_STALE_SECONDS', 120),
];
