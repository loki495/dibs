<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StageWorkspaceArchive
{
    public function __construct(private readonly ValidateWorkspaceArchive $validate) {}

    /** @return array{path: string, hash: string, user: int, expires: int, counts: array<string, int>} */
    public function handle(string $json, int $userId): array
    {
        $data = $this->validate->handle($json);
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) config('workspace-transfer.preview_minutes'))->timestamp;
        foreach ($disk->files('workspace-imports/'.$userId) as $previous) {
            if ($disk->lastModified($previous) <= $cutoff) {
                $disk->delete($previous);
            }
        }
        $path = 'workspace-imports/'.$userId.'/'.Str::uuid().'.json.enc';
        if (! Storage::disk('local')->put($path, Crypt::encryptString($json))) {
            throw new RuntimeException('Unable to store the workspace import preview.');
        }

        return ['path' => $path, 'hash' => hash('sha256', $json), 'user' => $userId, 'expires' => now()->addMinutes((int) config('workspace-transfer.preview_minutes'))->timestamp, 'counts' => array_map(count(...), $data)];
    }
}
