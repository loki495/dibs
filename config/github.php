<?php

declare(strict_types=1);

return [
    // Deliberately not GITHUB_OWNER/GITHUB_REPOSITORY: GitHub Actions (and Codespaces)
    // unconditionally export their own GITHUB_REPOSITORY env var ("owner/repo") to every
    // job, silently overriding an app .env value of the same name.
    'owner' => env('DIBS_GITHUB_OWNER', ''),
    'repository' => env('DIBS_GITHUB_REPO', ''),
    'token' => env('GITHUB_TOKEN'),
    // Comma-separated GitHub Projects (v2) numbers to sync as areas; blank syncs none.
    'projects' => array_values(array_filter(
        array_map(intval(...), explode(',', (string) env('GITHUB_PROJECT_NUMBERS', ''))),
        static fn (int $number): bool => $number > 0,
    )),
];
