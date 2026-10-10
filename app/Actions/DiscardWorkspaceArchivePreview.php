<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Storage;

class DiscardWorkspaceArchivePreview
{
    /** @param array{path: string, hash: string, user: int, expires: int, counts: array<string, int>}|null $preview */
    public function handle(?array $preview, int $userId): void
    {
        if ($preview !== null && $preview['user'] === $userId && str_starts_with($preview['path'], 'workspace-imports/'.$userId.'/') && ! str_contains($preview['path'], '..')) {
            Storage::disk('local')->delete($preview['path']);
        }
    }
}
