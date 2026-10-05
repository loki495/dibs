<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GitHubRepository;

/**
 * Whether this instance mirrors to GitHub. Writes are mirrored once a repository has been imported
 * (a non-local `repositories` row exists): that is shared database state, so every process sees the
 * switch at once, including long-lived MCP servers that read their environment only at boot.
 * DIBS_GITHUB_OWNER/DIBS_GITHUB_REPO (enabled()) only say which repository to import and whether
 * this process may contact GitHub (import, Refresh, the push-queue drain).
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

    /** One small query per call, deliberately uncached, so a switch is seen on the very next write. */
    public static function mirrored(): bool
    {
        return GitHubRepository::query()->where('is_local', false)->exists();
    }

    /**
     * The imported repository writes belong to. With DIBS_GITHUB_OWNER/REPO set in this process it is
     * that repository or nothing (switching to a repository not yet imported must not keep writing to
     * the old one); with them blank it is whichever repository was imported, newest first.
     */
    public static function importedRepository(): ?GitHubRepository
    {
        return GitHubRepository::query()->where('is_local', false)
            ->when(self::enabled(), fn ($query) => $query->where('full_name', self::fullName()))
            ->orderByDesc('is_available')->orderByDesc('id')
            ->first();
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
