<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\TaskClaim;

class DescribeTodoContext
{
    public function __construct(private readonly DescribeGitHubPushQueue $describeQueue) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        return [
            'areas' => $this->areas(),
            'groups' => $this->groups(),
            'labels' => Label::query()->where('is_available', true)->orderBy('name')->pluck('name')->all(),
            'liveClaims' => $this->liveClaims(),
            'pushQueue' => $this->describeQueue->counts(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function areas(): array
    {
        return GitHubProject::query()->where('is_available', true)
            ->withCount(['items' => fn ($items) => $items->where('is_available', true)->whereNull('archived_at')
                ->whereHas('issue', fn ($issue) => $issue->where('is_available', true)->where('state', 'OPEN'))])
            ->orderBy('title')->get()
            ->map(fn (GitHubProject $project): array => [
                'id' => $project->id, 'title' => $project->title, 'color' => $project->color, 'openTaskCount' => $project->items_count,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        return ProjectFieldOption::query()->whereHas('field', fn ($field) => $field->where('is_available', true)->where('semantic_key', 'group'))
            ->with('field')->orderBy('position')->get()
            ->map(fn (ProjectFieldOption $option): array => [
                'id' => $option->id, 'name' => $option->name, 'areaId' => $option->field->project_id,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function liveClaims(): array
    {
        $claims = TaskClaim::query()->whereNull('released_at')->where('expires_at', '>', now())
            ->with(['agentSession', 'issue'])->get();

        return $claims->map(fn (TaskClaim $claim): array => [
            'issueId' => $claim->issue_id,
            'issueTitle' => $claim->issue instanceof Issue ? $claim->issue->title : null,
            'agentName' => $claim->agentSession?->agent_name,
            'expiresAt' => $claim->expires_at->toIso8601String(),
            'isVerifiedLive' => (bool) $claim->agentSession?->is_verified_live,
        ])->all();
    }
}
