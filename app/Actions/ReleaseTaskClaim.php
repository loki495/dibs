<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TaskClaim;
use Illuminate\Support\Facades\DB;

class ReleaseTaskClaim
{
    public function __construct(private readonly AuthorizeAgentClaim $authorize) {}

    public function handle(int $issueId, int $pid, string $capabilityToken): TaskClaim
    {
        return DB::transaction(function () use ($issueId, $pid, $capabilityToken): TaskClaim {
            $claim = $this->authorize->handle($issueId, $pid, $capabilityToken);
            $claim->update(['released_at' => now()]);

            return $claim->refresh();
        });
    }
}
