<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReleaseTaskClaim
{
    public function handle(int $issueId, string $sessionKey): TaskClaim
    {
        return DB::transaction(function () use ($issueId, $sessionKey): TaskClaim {
            $claim = TaskClaim::query()->where('issue_id', $issueId)->whereNull('released_at')->whereHas('agentSession', fn ($query) => $query->where('session_key', $sessionKey))->first();
            if (! $claim instanceof TaskClaim) {
                throw new DomainException('This session does not hold a live claim for the task.');
            }
            $claim->update(['released_at' => now()]);

            return $claim->refresh();
        });
    }
}
