<?php

declare(strict_types=1);

use App\Actions\MoveIssueUnderParent;
use App\Exceptions\TodoValidationException;
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

it('refuses to make an issue its own parent', function (): void {
    $issue = Issue::factory()->create();

    expect(fn () => app(MoveIssueUnderParent::class)->handle($issue, $issue))
        ->toThrow(TodoValidationException::class, 'A task cannot be its own parent.');

    expect($issue->refresh()->parent_issue_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('refuses a parent that is already below the issue, however deep', function (int $depth): void {
    $issue = Issue::factory()->create();
    $descendant = $issue;
    foreach (range(1, $depth) as $ignored) {
        $descendant = Issue::factory()->for($issue->repository, 'repository')->create(['parent_issue_id' => $descendant->id]);
    }

    expect(fn () => app(MoveIssueUnderParent::class)->handle($issue, $descendant))
        ->toThrow(TodoValidationException::class, 'This parent would create a hierarchy cycle: it is already a sub-task of this task.');

    expect($issue->refresh()->parent_issue_id)->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
})->with([1, 2, 5]);

it('still allows a parent elsewhere in the same tree, such as a sibling or an ancestor', function (): void {
    $root = Issue::factory()->create();
    $issue = Issue::factory()->for($root->repository, 'repository')->create(['parent_issue_id' => $root->id]);
    $sibling = Issue::factory()->for($root->repository, 'repository')->create(['parent_issue_id' => $root->id]);
    $cousin = Issue::factory()->for($root->repository, 'repository')->create(['parent_issue_id' => $sibling->id]);

    app(MoveIssueUnderParent::class)->handle($issue, $cousin);
    expect($issue->refresh()->parent_issue_id)->toBe($cousin->id);

    app(MoveIssueUnderParent::class)->handle($issue, $root);
    expect($issue->refresh()->parent_issue_id)->toBe($root->id);
});

it('fails with a specific error, instead of looping forever, when the hierarchy above the new parent already contains a cycle', function (): void {
    $a = Issue::factory()->create();
    $b = Issue::factory()->for($a->repository, 'repository')->create(['parent_issue_id' => $a->id]);
    $a->update(['parent_issue_id' => $b->id]);
    $issue = Issue::factory()->for($a->repository, 'repository')->create();

    expect(fn () => app(MoveIssueUnderParent::class)->handle($issue, $a))
        ->toThrow(TodoValidationException::class, 'The local hierarchy already contains a cycle. Refresh before editing it.');

    expect($issue->refresh()->parent_issue_id)->toBeNull();
});

it('does not count clearing a parent as a cycle', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent->repository, 'repository')->create(['parent_issue_id' => $parent->id]);

    app(MoveIssueUnderParent::class)->handle($issue, null);

    expect($issue->refresh()->parent_issue_id)->toBeNull();
});
