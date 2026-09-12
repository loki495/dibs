<?php

declare(strict_types=1);

return [
    'owner' => env('GITHUB_OWNER', 'loki495'),
    'repository' => env('GITHUB_REPOSITORY', 'Todo'),
    'token' => env('GITHUB_TOKEN'),
    'projects' => array_map(intval(...), explode(',', env('GITHUB_PROJECT_NUMBERS', '2,3,5,6'))),
];
