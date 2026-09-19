<?php

declare(strict_types=1);

use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\User;
use Livewire\Livewire;

it('keeps the edit form open with a clear message and saves nothing when the chosen parent is one of the task\'s own descendants', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title']);
    $child = Issue::factory()->for($repository, 'repository')->create(['parent_issue_id' => $issue->id]);
    $grandchild = Issue::factory()->for($repository, 'repository')->create(['parent_issue_id' => $child->id]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editTitle', 'Should not save')->set('editParent', $grandchild->id)->call('saveIssue')
        ->assertSet('editingIssue', true)
        ->assertSet('editError', 'This parent would create a hierarchy cycle: it is already a sub-task of this task.');

    expect($issue->refresh())->title->toBe('Old title')->parent_issue_id->toBeNull();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects the task itself as its own parent', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
    $issue = Issue::factory()->for($repository, 'repository')->create();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editParent', $issue->id)->call('saveIssue')
        ->assertSet('editingIssue', true)->assertSet('editError', 'A task cannot be its own parent.');

    expect($issue->refresh()->parent_issue_id)->toBeNull();
});
