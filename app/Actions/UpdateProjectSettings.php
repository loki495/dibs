<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Services\GitHub\GitHubSyncException;
use SensitiveParameter;

class UpdateProjectSettings
{
    public function __construct(private readonly UpdateGitHubProject $renameProject) {}

    /**
     * The name lives in GitHub and is renamed there first; the display color is local-only, so it is
     * written last and a rejected rename leaves it untouched.
     *
     * @throws TodoValidationException
     * @throws TodoRecordUnavailableException
     * @throws GitHubSyncException
     */
    public function handle(#[SensitiveParameter] string $token, int $projectId, string $title, string $color): GitHubProject
    {
        $title = trim($title);
        if ($title === '') {
            throw new TodoValidationException('A project name is required.');
        }
        if (preg_match('/^[a-fA-F0-9]{6}\z/', $color) !== 1) {
            throw new TodoValidationException('The color must be six hex digits.');
        }
        $project = GitHubProject::query()->where('is_available', true)->find($projectId);
        if (! $project instanceof GitHubProject) {
            throw new TodoRecordUnavailableException('The selected project is no longer available. Refresh and try again.');
        }
        if ($token === '') {
            throw new GitHubSyncException('Project settings need a server-side GitHub token. Set GITHUB_TOKEN and try again.');
        }

        if ($title !== $project->title) {
            $project = $this->renameProject->handle($token, $project, $title);
        }
        $project->update(['color' => strtolower($color)]);

        return $project;
    }
}
