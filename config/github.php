<?php

declare(strict_types=1);

return [
    // Deliberately not GITHUB_OWNER/GITHUB_REPOSITORY: GitHub Actions (and Codespaces)
    // unconditionally export their own GITHUB_REPOSITORY env var ("owner/repo") to every
    // job, silently overriding an app .env value of the same name.
    'owner' => env('DIBS_GITHUB_OWNER', ''),
    'repository' => env('DIBS_GITHUB_REPO', ''),
    'token' => env('GITHUB_TOKEN'),
    'projects' => array_map(intval(...), explode(',', env('GITHUB_PROJECT_NUMBERS', '2,3,5,6'))),
];
