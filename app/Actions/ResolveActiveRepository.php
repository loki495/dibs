<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\GitHubRepository;
use App\Support\GitHubMirror;

/**
 * The repository new tasks and labels belong to. With GitHub mirroring configured it is the
 * imported repository; local-only, it is the single local repository row, created on first use.
 */
class ResolveActiveRepository
{
    public function handle(): GitHubRepository
    {
        if (! GitHubMirror::enabled()) {
            return $this->local();
        }

        $repository = GitHubRepository::query()->where('full_name', GitHubMirror::fullName())->first();
        if (! $repository instanceof GitHubRepository) {
            throw new TodoValidationException('GitHub mirroring is configured for '.GitHubMirror::fullName().', but that repository has not been imported yet. Run the GitHub import (scripts/github-pull or php artisan todo:sync) first.');
        }

        return $repository;
    }

    /** Idempotent: concurrent or repeated calls always end up with the same single row. */
    public function local(): GitHubRepository
    {
        $imported = GitHubRepository::query()->where('is_local', false)->value('full_name');
        if (is_string($imported)) {
            // Starting a second, local repository next to an imported one would split tasks and labels
            // across two repositories and strand the local ones outside the GitHub mirror.
            throw new TodoValidationException("This instance was imported from GitHub ({$imported}), but DIBS_GITHUB_OWNER and DIBS_GITHUB_REPO are blank. Set them again to keep working.");
        }

        return GitHubRepository::query()->createOrFirst(['github_node_id' => GitHubRepository::LOCAL_IDENTITY], [
            'owner' => GitHubRepository::LOCAL_IDENTITY, 'name' => GitHubRepository::LOCAL_IDENTITY,
            'full_name' => GitHubRepository::LOCAL_IDENTITY, 'url' => '', 'is_private' => true,
            'visibility' => null, 'is_local' => true, 'is_available' => true, 'last_seen_at' => now(),
        ]);
    }
}
