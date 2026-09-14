<?php

declare(strict_types=1);

use App\Actions\DeleteGroupOption;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\Http;

it('deletes a Group option and ungroups any tasks that were using it, without deleting them', function (): void {
    Http::fake();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => 'O_1']);
    $issue = Issue::factory()->create();
    $item = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $option->id]);

    app(DeleteGroupOption::class)->handle($option);

    expect(ProjectFieldOption::query()->find($option->id))->toBeNull();
    expect($item->refresh()->group_option_id)->toBeNull();
    expect($issue->refresh())->not->toBeNull();
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'delete_group_option')->sole();
    expect($enqueued->payload)->toBe(['github_option_id' => 'O_1', 'field_github_node_id' => $field->github_node_id]);
    Http::assertNothingSent();
});

it('does not enqueue a remote deletion when the Group option was never pushed to GitHub', function (): void {
    Http::fake();
    $field = ProjectField::factory()->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['github_option_id' => null]);

    app(DeleteGroupOption::class)->handle($option);

    expect(ProjectFieldOption::query()->count())->toBe(0);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
