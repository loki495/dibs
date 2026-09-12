<?php

declare(strict_types=1);

use App\Actions\ClearProjectItemGroup;
use App\Actions\DeleteGitHubProjectItem;
use App\Models\GitHubProject;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('removes a Project membership in GitHub before hiding the local membership', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_project']);
    $item = ProjectItem::factory()->for($project, 'project')->create(['github_node_id' => 'PVTI_item']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['deleteProjectV2Item' => ['deletedItemId' => 'PVTI_item']]], 200));

    $removed = app(DeleteGitHubProjectItem::class)->handle('test-token', $item);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'deleteProjectV2Item')
        && (array) $request->data()['variables'] === ['projectId' => 'PVT_project', 'itemId' => 'PVTI_item']);
    expect($removed->is_available)->toBeFalse();
});

it('clears a Group field in GitHub before clearing the local Group assignment', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_project']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group', 'github_node_id' => 'PVTF_group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['github_node_id' => 'PVTI_item', 'group_option_id' => $group->id]);
    Http::fake(fn (Request $request) => Http::response(['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_item']]]], 200));

    $updated = app(ClearProjectItemGroup::class)->handle('test-token', $item);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'clearProjectV2ItemFieldValue')
        && (array) $request->data()['variables'] === ['projectId' => 'PVT_project', 'itemId' => 'PVTI_item', 'fieldId' => 'PVTF_group']);
    expect($updated->group_option_id)->toBeNull();
});
