<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReadWorkspaceArchivePreview
{
    /** @param array{path: string, hash: string, user: int, expires: int, counts: array<string, int>}|null $preview */
    public function handle(?array $preview, int $userId): string
    {
        if ($preview === null || $preview['user'] !== $userId || $preview['expires'] <= now()->timestamp || ! str_starts_with($preview['path'], 'workspace-imports/'.$userId.'/') || str_contains($preview['path'], '..')) {
            $this->invalid();
        }
        $encrypted = Storage::disk('local')->get($preview['path']);
        if (! is_string($encrypted)) {
            $this->invalid();
        }
        try {
            $json = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            $this->invalid();
        }
        if (! hash_equals($preview['hash'], hash('sha256', $json))) {
            $this->invalid();
        }

        return $json;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['archive' => __('The import preview expired or changed. Upload the archive again.')]);
    }
}
