<?php

declare(strict_types=1);

use App\Actions\BulkSetIssuesParent;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\Http;

it('sets the same parent on several tasks at once and enqueues each', function (): void {
    Http::fake();
    $parent = Issue::factory()->create();
    $childA = Issue::factory()->create();
    $childB = Issue::factory()->create();

    $updated = app(BulkSetIssuesParent::class)->handle([$childA->id, $childB->id], $parent->id);

    expect($updated)->toBe(2);
    expect($childA->refresh()->parent_issue_id)->toBe($parent->id);
    expect($childB->refresh()->parent_issue_id)->toBe($parent->id);
    expect(GitHubPushQueueItem::query()->where('operation', 'set_issue_parent')->count())->toBe(2);
});

it('skips a selected task that is the chosen parent instead of self-parenting it', function (): void {
    Http::fake();
    $parent = Issue::factory()->create();
    $child = Issue::factory()->create();

    $updated = app(BulkSetIssuesParent::class)->handle([$parent->id, $child->id], $parent->id);

    expect($updated)->toBe(1);
    expect($parent->refresh()->parent_issue_id)->toBeNull();
    expect($child->refresh()->parent_issue_id)->toBe($parent->id);
});

it('clears the parent on several tasks at once when no parent is chosen', function (): void {
    Http::fake();
    $oldParent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($oldParent, 'parent')->create(['github_parent_node_id' => 'I_parent']);

    $updated = app(BulkSetIssuesParent::class)->handle([$child->id], null);

    expect($updated)->toBe(1);
    expect($child->refresh())->parent_issue_id->toBeNull()->github_parent_node_id->toBeNull();
    expect(GitHubPushQueueItem::query()->where('operation', 'remove_issue_parent')->where('target_id', $child->id)->exists())->toBeTrue();
});

it('rejects a parent that is no longer available', function (): void {
    $child = Issue::factory()->create();

    expect(fn () => app(BulkSetIssuesParent::class)->handle([$child->id], 999999))
        ->toThrow(TodoValidationException::class);
});
