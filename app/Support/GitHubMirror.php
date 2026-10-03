<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether this instance mirrors to GitHub. Mirroring is on only when both DIBS_GITHUB_OWNER and
 * DIBS_GITHUB_REPO are set; otherwise Dibs runs local-only, against a single local repository row,
 * and never queues pushes or contacts GitHub.
 */
class GitHubMirror
{
    public static function enabled(): bool
    {
        return self::owner() !== '' && self::repository() !== '';
    }

    public static function fullName(): string
    {
        return self::owner().'/'.self::repository();
    }

    private static function owner(): string
    {
        return trim((string) config('github.owner'));
    }

    private static function repository(): string
    {
        return trim((string) config('github.repository'));
    }
}
