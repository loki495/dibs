<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\GitHubRepository;
use App\Support\GitHubMirror;

/**
 * The repository new tasks and labels belong to: the imported repository once one exists (decided
 * from the database, so a process that booted before the switch still follows it), otherwise the
 * single local repository row, created on first use. A process configured for a repository that is
 * not imported yet refuses the write.
 */
class ResolveActiveRepository
{
    public function handle(): GitHubRepository
    {
        $imported = GitHubMirror::importedRepository();
        if ($imported instanceof GitHubRepository) {
            return $imported;
        }
        if (GitHubMirror::enabled()) {
            throw new TodoValidationException('GitHub mirroring is configured for '.GitHubMirror::fullName().', but that repository has not been imported yet. Run the GitHub import (scripts/github-pull or php artisan todo:sync) first.');
        }

        return $this->local();
    }

    /** Idempotent: concurrent or repeated calls always end up with the same single row. */
    public function local(): GitHubRepository
    {
        $imported = GitHubRepository::query()->where('is_local', false)->value('full_name');
        if (is_string($imported)) {
            // Starting a second, local repository next to an imported one would split tasks and labels
            // across two repositories and strand the local ones outside the GitHub mirror.
            throw new TodoValidationException("This instance was imported from GitHub ({$imported}); its tasks and labels belong to that repository, not a new local one.");
        }

        return GitHubRepository::query()->createOrFirst(['github_node_id' => GitHubRepository::LOCAL_IDENTITY], [
            'owner' => GitHubRepository::LOCAL_IDENTITY, 'name' => GitHubRepository::LOCAL_IDENTITY,
            'full_name' => GitHubRepository::LOCAL_IDENTITY, 'url' => '', 'is_private' => true,
            'visibility' => null, 'is_local' => true, 'is_available' => true, 'last_seen_at' => now(),
        ]);
    }
}
