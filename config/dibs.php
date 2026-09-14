<?php

declare(strict_types=1);

return [
    'timezone' => env('DIBS_TIMEZONE', 'America/Los_Angeles'),
    'trusted_proxies' => array_filter(explode(',', env('TRUSTED_PROXIES', ''))),
    'push_queue_ui_enabled' => (bool) env('DIBS_PUSH_QUEUE_UI_ENABLED', true),
];
