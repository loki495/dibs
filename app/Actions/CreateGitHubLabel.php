<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubRepository;
use App\Models\Label;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CreateGitHubLabel
{
    public function handle(#[SensitiveParameter] string $token, string $name, string $color = '6B7280', ?string $description = null): Label
    {
        $name = trim($name);
        if ($name === '') {
            throw new GitHubSyncException('A label name is required.');
        }
        $repository = GitHubRepository::query()->where('full_name', config('github.owner').'/'.config('github.repository'))->where('is_available', true)->first();
        if (! $repository instanceof GitHubRepository) {
            throw new GitHubSyncException('The configured repository is not available in the local snapshot. Refresh and try again.');
        }
        $existing = $repository->labels()->where('is_available', true)->get()->first(fn (Label $label): bool => strcasecmp($label->name, $name) === 0);
        if ($existing instanceof Label) {
            return $existing;
        }
        $data = (new GitHubClient($token))->query(
            'mutation($repositoryId: ID!, $name: String!, $color: String!, $description: String) { createLabel(input: {repositoryId: $repositoryId, name: $name, color: $color, description: $description}) { label { id name color description url } } }',
            ['repositoryId' => $repository->github_node_id, 'name' => $name, 'color' => $color, 'description' => $description],
        );
        $remote = $data['createLabel']['label'] ?? null;
        if (! is_array($remote) || ! is_string($remote['id'] ?? null) || ! is_string($remote['name'] ?? null)) {
            throw new GitHubSyncException('GitHub did not return the created label. Refresh before retrying.');
        }

        return DB::transaction(fn (): Label => Label::query()->updateOrCreate(['github_node_id' => $remote['id']], ['repository_id' => $repository->id, 'name' => $remote['name'], 'color' => $remote['color'] ?? null, 'description' => $remote['description'] ?? null, 'last_synced_at' => now(), 'is_available' => true, 'last_seen_at' => now()]));
    }
}
