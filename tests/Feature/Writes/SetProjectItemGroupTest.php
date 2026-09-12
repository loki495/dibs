<?php

declare(strict_types=1);

use App\Actions\SetProjectItemGroup;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sets a GitHub Project item group and updates the local projection after confirmation', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_personal']);
    $field = ProjectField::factory()->for($project, 'project')->create(['github_node_id' => 'PVTF_group', 'semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'group-homelab', 'name' => 'Homelab']);
    $issue = Issue::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['github_node_id' => 'PVTI_item']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_item', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $updated = app(SetProjectItemGroup::class)->handle('test-token', $item, $group);

    Http::assertSent(function (Request $request): bool {
        $variables = (array) $request->data()['variables'];

        return str_contains((string) $request->data()['query'], 'updateProjectV2ItemFieldValue')
            && $variables === ['projectId' => 'PVT_personal', 'itemId' => 'PVTI_item', 'fieldId' => 'PVTF_group', 'optionId' => 'group-homelab'];
    });
    expect($updated->group_option_id)->toBe($group->id);
});

it('refuses a group from another project before contacting GitHub', function (): void {
    Http::fake();
    $item = ProjectItem::factory()->create();
    $otherGroup = ProjectFieldOption::factory()->create();

    expect(fn () => app(SetProjectItemGroup::class)->handle('test-token', $item, $otherGroup))
        ->toThrow(GitHubSyncException::class, 'same Project');
    Http::assertNothingSent();
});
