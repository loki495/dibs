<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\TaskClaim;
use Illuminate\Support\Facades\DB;

class ReleaseTaskClaim
{
    public function __construct(
        private readonly AuthorizeAgentClaim $authorize,
        private readonly CreateTodoComment $comment,
    ) {}

    /** $left (what is left and the exact next step) is required and becomes the release's one comment, so the next session can continue. */
    public function handle(int $issueId, int $pid, string $capabilityToken, string $left): TaskClaim
    {
        if (trim($left) === '') {
            throw new TodoValidationException('Release refused: pass left, saying what is left and the exact next step, so the next session can continue.');
        }

        return DB::transaction(function () use ($issueId, $pid, $capabilityToken, $left): TaskClaim {
            $claim = $this->authorize->handle($issueId, $pid, $capabilityToken);
            $claim->update(['released_at' => now()]);
            $this->comment->handle($issueId, '**Released, left to do:** '.trim($left));

            return $claim->refresh();
        });
    }
}
