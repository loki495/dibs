<?php

declare(strict_types=1);

function githubConfigWithProjectNumbers(?string $raw): array
{
    $previous = [$_ENV['GITHUB_PROJECT_NUMBERS'] ?? null, $_SERVER['GITHUB_PROJECT_NUMBERS'] ?? null];
    // env() also reads the process environment, which a container's env_file populates.
    $previousProcess = getenv('GITHUB_PROJECT_NUMBERS');
    unset($_ENV['GITHUB_PROJECT_NUMBERS'], $_SERVER['GITHUB_PROJECT_NUMBERS']);
    putenv('GITHUB_PROJECT_NUMBERS');
    if ($raw !== null) {
        $_ENV['GITHUB_PROJECT_NUMBERS'] = $_SERVER['GITHUB_PROJECT_NUMBERS'] = $raw;
        putenv('GITHUB_PROJECT_NUMBERS='.$raw);
    }

    try {
        return require base_path('config/github.php');
    } finally {
        unset($_ENV['GITHUB_PROJECT_NUMBERS'], $_SERVER['GITHUB_PROJECT_NUMBERS']);
        putenv($previousProcess === false ? 'GITHUB_PROJECT_NUMBERS' : 'GITHUB_PROJECT_NUMBERS='.$previousProcess);
        if ($previous[0] !== null) {
            $_ENV['GITHUB_PROJECT_NUMBERS'] = $previous[0];
        }
        if ($previous[1] !== null) {
            $_SERVER['GITHUB_PROJECT_NUMBERS'] = $previous[1];
        }
    }
}

it('parses GITHUB_PROJECT_NUMBERS into project numbers', function (): void {
    expect(githubConfigWithProjectNumbers('1, 2,5')['projects'])->toBe([1, 2, 5]);
});

it('configures no projects when GITHUB_PROJECT_NUMBERS is unset, blank or not numbers', function (?string $raw): void {
    expect(githubConfigWithProjectNumbers($raw)['projects'])->toBe([]);
})->with([null, '', ' , ', 'abc', '0,-3']);
