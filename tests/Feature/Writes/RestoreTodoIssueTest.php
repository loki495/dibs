<?php

declare(strict_types=1);

use App\Actions\DeleteTodoIssue;
use App\Actions\RestoreTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use Illuminate\Support\Facades\Http;

it('restores an issue and keeps its GitHub identity when the deletion never actually reached GitHub', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['github_node_id' => 'I_test', 'github_number' => 42]);
    app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false);

    $restored = app(RestoreTodoIssue::class)->handle($issue);

    expect($restored->is_available)->toBeTrue()
        ->and($restored->github_node_id)->toBe('I_test')
        ->and($restored->github_number)->toBe(42)
        ->and(GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->exists())->toBeFalse();
});

it('queues a fresh create_issue once the deletion has already been pushed to GitHub, since that cannot be reversed', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['github_node_id' => 'I_test', 'github_number' => 42, 'title' => 'Bring this back', 'body' => null]);
    app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false);
    GitHubPushQueueItem::query()->where('operation', 'delete_issue')->where('target_id', $issue->id)->update(['status' => 'pushed']);

    $restored = app(RestoreTodoIssue::class)->handle($issue);

    expect($restored->is_available)->toBeTrue()
        ->and($restored->github_node_id)->toBeNull()
        ->and($restored->github_number)->toBeNull()
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $issue->id)->where('payload', json_encode(['title' => 'Bring this back', 'body' => null]))->exists())->toBeTrue();
});

it('refuses to restore an issue that is not deleted', function (): void {
    $issue = Issue::factory()->create();

    expect(fn () => app(RestoreTodoIssue::class)->handle($issue))
        ->toThrow(TodoValidationException::class);
});

it('can restore an issue that was never pushed to GitHub in the first place', function (): void {
    Http::fake();
    $issue = Issue::factory()->create(['github_node_id' => null, 'github_number' => null]);
    app(DeleteTodoIssue::class)->handle($issue, cascadeChildren: false);

    $restored = app(RestoreTodoIssue::class)->handle($issue);

    expect($restored->is_available)->toBeTrue()
        ->and($restored->github_node_id)->toBeNull();
});
