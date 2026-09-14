<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Label;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a label -- unlike Group options, Label already carries an is_available flag,
 * and every consumer already filters by it (task label lists, the label-filter pill row), so
 * no pivot cleanup is needed. The local row (and its github_node_id) stays intact for the
 * push worker to use when it deletes the label on GitHub via its own node id.
 */
class DeleteLabel
{
    public function handle(Label $label): void
    {
        DB::transaction(function () use ($label): void {
            $label->update(['is_available' => false]);
            if ($label->github_node_id !== null) {
                app(EnqueueGitHubPush::class)->handle('delete_label', 'label', $label->id, [], 'label:delete:'.$label->id);
            }
        });
    }
}
