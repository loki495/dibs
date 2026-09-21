<?php

declare(strict_types=1);

use App\Actions\ClearProjectItemPriority;
use App\Models\GitHubProject;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('clears a GitHub Project item priority and updates the local projection after confirmation', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_personal']);
    $field = ProjectField::factory()->for($project, 'project')->create(['github_node_id' => 'PVTF_priority', 'semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['github_node_id' => 'PVTI_item', 'priority_option_id' => $priority->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_item', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $updated = app(ClearProjectItemPriority::class)->handle('test-token', $item);

    Http::assertSent(function (Request $request): bool {
        $variables = (array) $request->data()['variables'];

        return str_contains((string) $request->data()['query'], 'clearProjectV2ItemFieldValue')
            && $variables === ['projectId' => 'PVT_personal', 'itemId' => 'PVTI_item', 'fieldId' => 'PVTF_priority'];
    });
    expect($updated->priority_option_id)->toBeNull();
});

it('is a no-op when the item has no priority assigned', function (): void {
    Http::fake();
    $item = ProjectItem::factory()->create(['priority_option_id' => null]);

    $result = app(ClearProjectItemPriority::class)->handle('test-token', $item);

    expect($result->is($item))->toBeTrue();
    Http::assertNothingSent();
});

it('refuses an unavailable project item before contacting GitHub', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['priority_option_id' => $priority->id, 'is_available' => false]);

    expect(fn () => app(ClearProjectItemPriority::class)->handle('test-token', $item))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('rejects when github does not confirm removal of the priority', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'priority']);
    $priority = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['priority_option_id' => $priority->id]);
    Http::fake(fn () => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => null]]], 200));

    expect(fn () => app(ClearProjectItemPriority::class)->handle('test-token', $item))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});
