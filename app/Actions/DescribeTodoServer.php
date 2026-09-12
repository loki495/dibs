<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;

class DescribeTodoServer
{
    /** @return array<string, mixed> */
    public function handle(): array
    {
        $repository = GitHubRepository::query()->where('is_available', true)->first();

        return [
            'app' => (string) config('app.name'),
            'environment' => (string) config('app.env'),
            'repository' => [
                'owner' => (string) config('github.owner'),
                'name' => (string) config('github.repository'),
                'imported' => $repository instanceof GitHubRepository,
                'full_name' => $repository?->full_name,
            ],
            'counts' => [
                'issues' => Issue::query()->where('is_available', true)->count(),
                'labels' => Label::query()->where('is_available', true)->count(),
                'projects' => GitHubProject::query()->where('is_available', true)->count(),
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }
}
