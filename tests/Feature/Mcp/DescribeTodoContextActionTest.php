<?php

declare(strict_types=1);

use App\Actions\DescribeTodoContext;
use App\Models\AgentSession;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\TaskClaim;

it('describes areas with open task counts', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects', 'color' => '0f766e']);
    $open = Issue::factory()->create(['state' => 'OPEN']);
    ProjectItem::factory()->for($project, 'project')->for($open, 'issue')->create();
    $closed = Issue::factory()->create(['state' => 'CLOSED']);
    ProjectItem::factory()->for($project, 'project')->for($closed, 'issue')->create();

    $result = app(DescribeTodoContext::class)->handle();

    expect($result['areas'])->toHaveCount(1)
        ->and($result['areas'][0]['title'])->toBe('Personal Projects')
        ->and($result['areas'][0]['openTaskCount'])->toBe(1);
});

it('describes groups scoped to their area', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'dotfiles']);
    $otherField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    ProjectFieldOption::factory()->for($otherField, 'field')->create(['name' => 'High']);

    $result = app(DescribeTodoContext::class)->handle();

    expect($result['groups'])->toHaveCount(1)
        ->and($result['groups'][0]['name'])->toBe('dotfiles')
        ->and($result['groups'][0]['areaId'])->toBe($project->id);
});

it('lists available label names', function (): void {
    Label::factory()->create(['name' => 'next']);
    Label::factory()->create(['name' => 'gone', 'is_available' => false]);

    $result = app(DescribeTodoContext::class)->handle();

    expect($result['labels'])->toBe(['next']);
});

it('lists live claims with agent identity and verified-live status', function (): void {
    $issue = Issue::factory()->create(['title' => 'Ship it']);
    $session = AgentSession::query()->create(['agent_name' => 'codex', 'session_key' => 'session-a', 'last_seen_at' => now(), 'expires_at' => now()->addHour(), 'is_verified_live' => true]);
    TaskClaim::query()->create(['issue_id' => $issue->id, 'agent_session_id' => $session->id, 'expires_at' => now()->addMinutes(10)]);
    $expiredIssue = Issue::factory()->create();
    TaskClaim::query()->create(['issue_id' => $expiredIssue->id, 'agent_session_id' => $session->id, 'expires_at' => now()->subMinute()]);

    $result = app(DescribeTodoContext::class)->handle();

    expect($result['liveClaims'])->toHaveCount(1)
        ->and($result['liveClaims'][0]['issueTitle'])->toBe('Ship it')
        ->and($result['liveClaims'][0]['agentName'])->toBe('codex')
        ->and($result['liveClaims'][0]['isVerifiedLive'])->toBeTrue();
});

it('includes the push-queue counts', function (): void {
    $result = app(DescribeTodoContext::class)->handle();

    expect($result['pushQueue'])->toBe(['pending' => 0, 'failed' => 0, 'needsAttention' => 0, 'actionable' => 0]);
});
