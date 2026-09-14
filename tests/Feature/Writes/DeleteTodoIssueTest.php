<?php

declare(strict_types=1);

use App\Actions\DeleteTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\Http;

it('marks a leaf issue unavailable locally and enqueues its GitHub deletion without calling GitHub synchronously', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();

    $result = app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false);

    Http::assertNothingSent();
    expect($issue->refresh()->is_available)->toBeFalse()
        ->and($result)->toBe([$issue->id])
        ->and(GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->where('status', 'pending')->exists())->toBeTrue();
});

it('refuses to delete an issue that is already unavailable', function (): void {
    $issue = Issue::factory()->create(['is_available' => false]);

    expect(fn () => app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false))
        ->toThrow(TodoValidationException::class);
});

it('cascades deletion to every descendant, deepest first', function (): void {
    Http::fake();
    $grandparent = Issue::factory()->create();
    $parent = Issue::factory()->for($grandparent, 'parent')->for($grandparent->repository, 'repository')->create();
    $child = Issue::factory()->for($parent, 'parent')->for($grandparent->repository, 'repository')->create();

    $result = app(DeleteTodoIssue::class)->handle($grandparent, cascadeChildren: true);

    expect($grandparent->refresh()->is_available)->toBeFalse()
        ->and($parent->refresh()->is_available)->toBeFalse()
        ->and($child->refresh()->is_available)->toBeFalse()
        ->and($result)->toEqualCanonicalizing([$grandparent->id, $parent->id, $child->id])
        ->and(GitHubPushQueueItem::query()->where('operation', 'delete_issue')->count())->toBe(3);
});

it('promotes direct children to the deleted issue\'s own parent instead of cascading', function (): void {
    Http::fake();
    $grandparent = Issue::factory()->create();
    $parent = Issue::factory()->for($grandparent, 'parent')->for($grandparent->repository, 'repository')->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($parent, 'parent')->for($grandparent->repository, 'repository')->create(['github_parent_node_id' => 'I_parent']);

    $result = app(DeleteTodoIssue::class)->handle($parent, cascadeChildren: false);

    expect($parent->refresh()->is_available)->toBeFalse()
        ->and($child->refresh()->parent_issue_id)->toBe($grandparent->id)
        ->and($child->github_parent_node_id)->toBe($grandparent->github_node_id)
        ->and($result)->toBe([$parent->id])
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->where('target_id', $child->id)->where('payload', json_encode(['parent_issue_id' => $grandparent->id]))->exists())->toBeTrue();
});

it('promotes children to the root level when the deleted issue had no parent', function (): void {
    Http::fake();
    $root = Issue::factory()->create(['github_node_id' => 'I_root']);
    $child = Issue::factory()->for($root, 'parent')->for($root->repository, 'repository')->create(['github_parent_node_id' => 'I_root']);

    app(DeleteTodoIssue::class)->handle($root, cascadeChildren: false);

    expect($child->refresh()->parent_issue_id)->toBeNull()
        ->and($child->github_parent_node_id)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->where('target_id', $child->id)->where('payload', json_encode(['parent_github_node_id' => 'I_root']))->exists())->toBeTrue();
});

it('does not enqueue a parent-removal push when the promoted child had never synced a parent to GitHub', function (): void {
    Http::fake();
    $root = Issue::factory()->create();
    $child = Issue::factory()->for($root, 'parent')->for($root->repository, 'repository')->create(['github_parent_node_id' => null]);

    app(DeleteTodoIssue::class)->handle($root, cascadeChildren: false);

    expect($child->refresh()->parent_issue_id)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->where('target_id', $child->id)->exists())->toBeFalse();
});
