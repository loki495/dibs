<?php

declare(strict_types=1);

use App\Actions\SetProjectItemPriority;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sets a GitHub Project item priority and updates the local projection after confirmation', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_personal']);
    $field = ProjectField::factory()->for($project, 'project')->create(['github_node_id' => 'PVTF_priority', 'semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'priority-homelab', 'name' => 'Homelab']);
    $issue = Issue::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_item']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_item', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $updated = app(SetProjectItemPriority::class)->handle('test-token', $item, $priority);

    Http::assertSent(function (Request $request): bool {
        $variables = (array) $request->data()['variables'];

        return str_contains((string) $request->data()['query'], 'updateProjectV2ItemFieldValue')
            && $variables === ['projectId' => 'PVT_personal', 'itemId' => 'PVTI_item', 'fieldId' => 'PVTF_priority', 'optionId' => 'priority-homelab'];
    });
    expect($updated->priority_option_id)->toBe($priority->id);
});

it('refuses a priority from another project before contacting GitHub', function (): void {
    Http::fake();
    $item = ProjectItem::factory()->create();
    $otherPriority = ProjectFieldOption::factory()->create();

    expect(fn () => app(SetProjectItemPriority::class)->handle('test-token', $item, $otherPriority))
        ->toThrow(GitHubSyncException::class, 'same Project');
    Http::assertNothingSent();
});

it('refuses an unavailable project item before contacting GitHub', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['is_available' => false]);

    expect(fn () => app(SetProjectItemPriority::class)->handle('test-token', $item, $priority))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('is a no-op when the requested priority is already the current one', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['priority_option_id' => $priority->id]);

    $result = app(SetProjectItemPriority::class)->handle('test-token', $item, $priority);

    expect($result->is($item))->toBeTrue();
    Http::assertNothingSent();
});

it('rejects when github does not confirm the priority assignment', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create();
    Http::fake(fn () => Http::response(['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => null]]], 200));

    expect(fn () => app(SetProjectItemPriority::class)->handle('test-token', $item, $priority))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});
