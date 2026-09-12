<?php

declare(strict_types=1);

return [
    'timezone' => env('TODO_TIMEZONE', 'America/Los_Angeles'),
    'trusted_proxies' => array_filter(explode(',', env('TRUSTED_PROXIES', ''))),
];
