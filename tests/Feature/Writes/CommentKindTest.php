<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Issue;

it('defaults kind to null for an ordinary comment', function (): void {
    expect(Comment::factory()->create()->kind)->toBeNull();
});

it('marks a comment as a closing note through the closing state and constant', function (): void {
    $comment = Comment::factory()->closing()->create();

    expect($comment->kind)->toBe(Comment::KIND_CLOSING)
        ->and(Comment::KIND_CLOSING)->toBe('closing');
});

it('scopes to only closing comments for one issue, in either order', function (): void {
    $issue = Issue::factory()->create();
    $closing = Comment::factory()->for($issue, 'issue')->closing()->create();
    Comment::factory()->for($issue, 'issue')->create();
    Comment::factory()->closing()->create();

    expect(Comment::query()->closing()->where('issue_id', $issue->id)->get()->modelKeys())->toBe([$closing->id])
        ->and($issue->comments()->closing()->get()->modelKeys())->toBe([$closing->id]);
});

it('combines with an explicit availability filter the same way other comment queries do', function (): void {
    $issue = Issue::factory()->create();
    $available = Comment::factory()->for($issue, 'issue')->closing()->create();
    Comment::factory()->for($issue, 'issue')->closing()->create(['is_available' => false]);

    expect($issue->comments()->closing()->where('is_available', true)->get()->modelKeys())->toBe([$available->id]);
});
