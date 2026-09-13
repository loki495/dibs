<?php

declare(strict_types=1);

use App\Actions\DeleteTodoIssue;
use App\Actions\UndoDeleteTodoIssue;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\Http;

it('restores a deleted issue and discards its still-pending delete_issue push-queue row', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();
    $delete = app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false);

    $result = app(UndoDeleteTodoIssue::class)->handle($delete['deletedIds'], $delete['reparented']);

    expect($result)->toBe(['restoredIds' => [$issue->id], 'tooLate' => []])
        ->and($issue->refresh()->is_available)->toBeTrue()
        ->and(GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->exists())->toBeFalse();
});

it('reports an id as too late once its delete_issue row has already been pushed to GitHub', function (): void {
    Http::fake();
    $issue = Issue::factory()->create();
    $delete = app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false);
    GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->update(['status' => 'pushed']);

    $result = app(UndoDeleteTodoIssue::class)->handle($delete['deletedIds'], $delete['reparented']);

    expect($result)->toBe(['restoredIds' => [], 'tooLate' => [$issue->id]])
        ->and($issue->refresh()->is_available)->toBeFalse();
});

it('restores a promoted child\'s previous parent and discards its still-pending reparenting row', function (): void {
    Http::fake();
    $grandparent = Issue::factory()->create();
    $parent = Issue::factory()->for($grandparent, 'parent')->for($grandparent->repository, 'repository')->create();
    $child = Issue::factory()->for($parent, 'parent')->for($grandparent->repository, 'repository')->create();
    $delete = app(DeleteTodoIssue::class)->handle($parent, cascadeChildren: false);

    $result = app(UndoDeleteTodoIssue::class)->handle($delete['deletedIds'], $delete['reparented']);

    expect($result['restoredIds'])->toBe([$parent->id])
        ->and($child->refresh()->parent_issue_id)->toBe($parent->id)
        ->and(GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->where('target_id', $child->id)->exists())->toBeFalse();
});

it('reports a promoted child as too late once its reparenting row has already been pushed', function (): void {
    Http::fake();
    $grandparent = Issue::factory()->create();
    $parent = Issue::factory()->for($grandparent, 'parent')->for($grandparent->repository, 'repository')->create();
    $child = Issue::factory()->for($parent, 'parent')->for($grandparent->repository, 'repository')->create();
    $delete = app(DeleteTodoIssue::class)->handle($parent, cascadeChildren: false);
    GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->where('target_id', $child->id)->update(['status' => 'pushed']);

    $result = app(UndoDeleteTodoIssue::class)->handle($delete['deletedIds'], $delete['reparented']);

    expect($result['tooLate'])->toBe([$child->id])
        ->and($child->refresh()->parent_issue_id)->toBe($grandparent->id);
});
