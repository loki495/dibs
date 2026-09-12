<?php

declare(strict_types=1);

use App\Actions\EnsureGitHubGroupOption;
use App\Models\GitHubProject;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('returns an existing group without changing GitHub', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $existing = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    $option = app(EnsureGitHubGroupOption::class)->handle('test-token', $project, ' career ');

    expect($option->is($existing))->toBeTrue();
    Http::assertNothingSent();
});

it('preserves remote group options when it creates a new group', function (): void {
    $project = GitHubProject::factory()->create(['github_node_id' => 'PVT_personal']);
    $field = ProjectField::factory()->for($project, 'project')->create(['github_node_id' => 'PVTF_group', 'semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'existing', 'name' => 'Career']);
    Http::fake([
        '*' => Http::sequence()
            ->push(['data' => ['node' => ['id' => 'PVTF_group', 'options' => [['id' => 'existing', 'name' => 'Career', 'color' => 'BLUE', 'description' => 'Career work']]]]])
            ->push(['data' => ['updateProjectV2Field' => ['projectV2Field' => ['id' => 'PVTF_group', 'options' => [
                ['id' => 'existing', 'name' => 'Career', 'color' => 'BLUE', 'description' => 'Career work'],
                ['id' => 'new-group', 'name' => 'Homelab', 'color' => 'GRAY', 'description' => ''],
            ]]]]], 200),
    ]);

    $option = app(EnsureGitHubGroupOption::class)->handle('test-token', $project, 'Homelab');

    Http::assertSent(function (Request $request): bool {
        $variables = (array) $request->data()['variables'];

        return str_contains((string) $request->data()['query'], 'updateProjectV2Field')
            && $variables['fieldId'] === 'PVTF_group'
            && $variables['options'] === [
                ['id' => 'existing', 'name' => 'Career', 'color' => 'BLUE', 'description' => 'Career work'],
                ['name' => 'Homelab', 'color' => 'GRAY', 'description' => ''],
            ];
    });
    expect($option->github_option_id)->toBe('new-group')->and($option->name)->toBe('Homelab');
});
