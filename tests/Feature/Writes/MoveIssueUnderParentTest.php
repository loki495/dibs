<?php

declare(strict_types=1);

use App\Actions\MoveIssueUnderParent;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;

it('sets a new parent after its existing children and enqueues the change', function (): void {
    $parent = Issue::factory()->create();
    Issue::factory()->for($parent->repository, 'repository')->create(['parent_issue_id' => $parent->id, 'sibling_position' => 4]);
    $issue = Issue::factory()->for($parent->repository, 'repository')->create();

    app(MoveIssueUnderParent::class)->handle($issue, $parent);

    expect($issue->refresh())->parent_issue_id->toBe($parent->id)->sibling_position->toBe(5);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->where('target_id', $issue->id)->sole();
    expect($enqueued->payload)->toBe(['parent_issue_id' => $parent->id]);
});

it('does nothing when the issue already has that parent', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent->repository, 'repository')->create(['parent_issue_id' => $parent->id, 'sibling_position' => 2]);

    app(MoveIssueUnderParent::class)->handle($issue, $parent);

    expect($issue->refresh()->sibling_position)->toBe(2);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('clears a pushed parent and enqueues its removal with the parent GitHub id', function (): void {
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $issue = Issue::factory()->for($parent->repository, 'repository')->create(['parent_issue_id' => $parent->id, 'github_parent_node_id' => 'I_parent', 'sibling_position' => 3]);

    app(MoveIssueUnderParent::class)->handle($issue, null);

    expect($issue->refresh())->parent_issue_id->toBeNull()->github_parent_node_id->toBeNull()->sibling_position->toBe(0);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->where('target_id', $issue->id)->sole();
    expect($enqueued->payload)->toBe(['parent_github_node_id' => 'I_parent']);
});

it('clears a parent that was never pushed without enqueueing a remote removal', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent->repository, 'repository')->create(['parent_issue_id' => $parent->id, 'github_parent_node_id' => null]);

    app(MoveIssueUnderParent::class)->handle($issue, null);

    expect($issue->refresh()->parent_issue_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('does nothing for an issue with no parent when none is requested', function (): void {
    $issue = Issue::factory()->create();

    app(MoveIssueUnderParent::class)->handle($issue, null);

    expect($issue->refresh()->parent_issue_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
