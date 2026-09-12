<?php

declare(strict_types=1);

use App\Actions\DescribeGitHubPushQueue;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;

it('describes each target type with a meaningful label instead of a bare type and id', function (): void {
    $issue = Issue::factory()->create(['title' => 'Fix the sync bug', 'github_number' => 42]);
    $pendingIssue = Issue::factory()->create(['title' => 'Brand new task', 'github_number' => null]);
    $label = Label::factory()->create(['name' => 'urgent']);
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Homelab']);
    $projectItem = ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create();
    $comment = Comment::factory()->for($issue, 'issue')->create();

    $items = collect([
        GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => $issue->id]),
        GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => $pendingIssue->id]),
        GitHubPushQueueItem::factory()->create(['target_type' => 'label', 'target_id' => $label->id]),
        GitHubPushQueueItem::factory()->create(['target_type' => 'project_field_option', 'target_id' => $option->id]),
        GitHubPushQueueItem::factory()->create(['target_type' => 'project_item', 'target_id' => $projectItem->id]),
        GitHubPushQueueItem::factory()->create(['target_type' => 'comment', 'target_id' => $comment->id]),
        GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => 999_999]),
    ]);

    $descriptions = app(DescribeGitHubPushQueue::class)->describeTargets($items);

    expect($descriptions[$items[0]->id])->toBe('#42 Fix the sync bug')
        ->and($descriptions[$items[1]->id])->toBe('(not yet synced) Brand new task')
        ->and($descriptions[$items[2]->id])->toBe('Label "urgent"')
        ->and($descriptions[$items[3]->id])->toBe('Group option "Homelab"')
        ->and($descriptions[$items[4]->id])->toBe('"Fix the sync bug" in Personal Projects')
        ->and($descriptions[$items[5]->id])->toBe('Comment on "Fix the sync bug"')
        ->and($descriptions[$items[6]->id])->toBe('Task (no longer exists locally)');
});
