<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Issue;

/**
 * Decides which issue and comment text agents may read. Everything is imported and the web UI shows all
 * of it; only agent-facing output (MCP tools and the todo:agent:* CLI) withholds text written on GitHub
 * by anyone other than the mirrored repository's owner (the one writes go to, GitHubMirror::importedRepository()) or a DIBS_TRUSTED_GITHUB_AUTHORS login, since an
 * agent may follow instructions it reads. Text created through Dibs has no GitHub author yet and is
 * always trusted, so local-only instances are unaffected.
 */
final class TrustedAuthors
{
    /** Stored for imported text whose GitHub account was deleted, so it can't pass as created through Dibs. */
    public const string DELETED_ACCOUNT = 'ghost';

    /** @var list<string>|null */
    private ?array $logins = null;

    public function allows(?string $login): bool
    {
        return $login === null || in_array(strtolower($login), $this->logins(), true);
    }

    public function title(Issue $issue): string
    {
        return $this->allows($issue->author_login) ? $issue->title : self::withheld($issue->author_login);
    }

    public static function withheld(?string $login): string
    {
        return "[withheld from agents: written on GitHub by @{$login}, not a trusted author]";
    }

    /** @return list<string> lowercase logins */
    public function logins(): array
    {
        return $this->logins ??= array_values(array_unique(array_filter(array_map(
            fn (string $login): string => strtolower(trim($login)),
            [
                GitHubMirror::importedRepository()->owner ?? '',
                ...explode(',', (string) config('dibs.trusted_github_authors')),
            ],
        ))));
    }
}
