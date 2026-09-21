<?php

declare(strict_types=1);

use App\Actions\ClearProjectItemGroup;
use App\Models\GitHubProject;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('clears a GitHub Project item group and updates the local projection after confirmation', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_personal']);
    $field = ProjectField::factory()->for($project, 'project')->create(['github_node_id' => 'PVTF_group', 'semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['github_node_id' => 'PVTI_item', 'group_option_id' => $group->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_item', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $updated = app(ClearProjectItemGroup::class)->handle('test-token', $item);

    Http::assertSent(function (Request $request): bool {
        $variables = (array) $request->data()['variables'];

        return str_contains((string) $request->data()['query'], 'clearProjectV2ItemFieldValue')
            && $variables === ['projectId' => 'PVT_personal', 'itemId' => 'PVTI_item', 'fieldId' => 'PVTF_group'];
    });
    expect($updated->group_option_id)->toBeNull();
});

it('is a no-op when the item has no group assigned', function (): void {
    Http::fake();
    $item = ProjectItem::factory()->create(['group_option_id' => null]);

    $result = app(ClearProjectItemGroup::class)->handle('test-token', $item);

    expect($result->is($item))->toBeTrue();
    Http::assertNothingSent();
});

it('refuses an unavailable project item before contacting GitHub', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['group_option_id' => $group->id, 'is_available' => false]);

    expect(fn () => app(ClearProjectItemGroup::class)->handle('test-token', $item))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('rejects when github does not confirm removal of the group', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['group_option_id' => $group->id]);
    Http::fake(fn () => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => null]]], 200));

    expect(fn () => app(ClearProjectItemGroup::class)->handle('test-token', $item))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});
