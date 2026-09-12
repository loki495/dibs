<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use DomainException;

/**
 * A human-authorized override, distinct from ReleaseTaskClaim: the workspace UI is behind
 * application login for its one operator, who already has full local database access — this
 * just gives them a safe, auditable way to recover a task they judge abandoned, without needing
 * the capability token a claiming agent process holds.
 */
class ReleaseAbandonedTaskClaim
{
    public function handle(int $issueId): TaskClaim
    {
        $claim = TaskClaim::query()->where('issue_id', $issueId)->whereNull('released_at')->first();
        if (! $claim instanceof TaskClaim) {
            throw new DomainException('This task has no live claim to release.');
        }
        $claim->update(['released_at' => now()]);

        return $claim->refresh();
    }
}
