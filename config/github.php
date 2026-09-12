<?php

declare(strict_types=1);

return [
    // Deliberately not GITHUB_OWNER/GITHUB_REPOSITORY: GitHub Actions (and Codespaces)
    // unconditionally export their own GITHUB_REPOSITORY env var ("owner/repo") to every
    // job, silently overriding an app .env value of the same name.
    'owner' => env('TODO_GITHUB_OWNER', 'loki495'),
    'repository' => env('TODO_GITHUB_REPO', 'Todo'),
    'token' => env('GITHUB_TOKEN'),
    'projects' => array_map(intval(...), explode(',', env('GITHUB_PROJECT_NUMBERS', '2,3,5,6'))),
];
