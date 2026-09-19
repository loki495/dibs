<?php

declare(strict_types=1);

use App\Actions\ApplyProjectItemFields;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

function optionFor(GitHubProject $project, string $semanticKey): ProjectFieldOption
{
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => $semanticKey]);

    return ProjectFieldOption::factory()->for($field, 'field')->create();
}

it('sets a Group and a Priority and enqueues each', function (): void {
    $project = GitHubProject::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->create(['group_option_id' => null, 'priority_option_id' => null]);
    $group = optionFor($project, 'group');
    $priority = optionFor($project, 'priority');

    app(ApplyProjectItemFields::class)->handle($item, $group, $priority);

    expect($item->refresh())->group_option_id->toBe($group->id)->priority_option_id->toBe($priority->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'set_project_item_group')->where('target_id', $item->id)->sole()->payload)->toBe(['group_option_id' => $group->id]);
    expect(GitHubPushQueueItem::query()->where('operation', 'set_project_item_priority')->where('target_id', $item->id)->sole()->payload)->toBe(['priority_option_id' => $priority->id]);
});

it('clears a set Group and Priority and enqueues each clear', function (): void {
    $project = GitHubProject::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->create([
        'group_option_id' => optionFor($project, 'group')->id, 'priority_option_id' => optionFor($project, 'priority')->id,
    ]);

    app(ApplyProjectItemFields::class)->handle($item, null, null);

    expect($item->refresh())->group_option_id->toBeNull()->priority_option_id->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_group')->where('target_id', $item->id)->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_priority')->where('target_id', $item->id)->count())->toBe(1);
});

it('does not enqueue a clear when there was nothing to clear', function (): void {
    $item = ProjectItem::factory()->create(['group_option_id' => null, 'priority_option_id' => null]);

    app(ApplyProjectItemFields::class)->handle($item, null, null);

    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('handles Group and Priority independently', function (): void {
    $project = GitHubProject::factory()->create();
    $priority = optionFor($project, 'priority');
    $item = ProjectItem::factory()->for($project, 'project')->create(['group_option_id' => optionFor($project, 'group')->id, 'priority_option_id' => null]);

    app(ApplyProjectItemFields::class)->handle($item, null, $priority);

    expect($item->refresh())->group_option_id->toBeNull()->priority_option_id->toBe($priority->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_group')->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->where('operation', 'clear_project_item_priority')->count())->toBe(0);
    expect(GitHubPushQueueItem::query()->where('operation', 'set_project_item_priority')->count())->toBe(1);
});
