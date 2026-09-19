<?php

declare(strict_types=1);

use App\Actions\ReviseTodoIssue;
use App\Exceptions\TodoStaleRevisionException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\User;
use Livewire\Livewire;

function revisionTestIssue(): Issue
{
    $repository = GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);

    return Issue::factory()->for($repository, 'repository')->create(['title' => 'Old title', 'body' => 'Old body', 'revision' => 3]);
}

it('bumps the revision on a UI save, so an agent holding the old revision gets a conflict instead of overwriting the edit', function (): void {
    $issue = revisionTestIssue();

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->set('editTitle', 'Edited in the UI')->call('saveIssue')->assertSet('editingIssue', false);

    expect($issue->refresh())->title->toBe('Edited in the UI')->revision->toBe(4);
    expect(fn () => app(ReviseTodoIssue::class)->handle($issue->id, 3, title: 'Agent overwrite'))->toThrow(TodoStaleRevisionException::class);
    expect($issue->refresh()->title)->toBe('Edited in the UI');
});

it('keeps the edit form open with a clear message and saves nothing when the task changed elsewhere since it was opened', function (): void {
    $issue = revisionTestIssue();

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('beginEdit')->assertSet('editRevision', 3);
    app(ReviseTodoIssue::class)->handle($issue->id, 3, title: 'Agent edit');

    $component->set('editTitle', 'My edit')->call('saveIssue')
        ->assertSet('editingIssue', true)
        ->assertSet('editTitle', 'My edit')
        ->assertSet('editError', 'This task was changed elsewhere since you started editing. Cancel, reopen it to see the latest, and apply your edit again.');

    expect($issue->refresh())->title->toBe('Agent edit')->revision->toBe(4);
    expect(GitHubPushQueueItem::query()->where('operation', 'update_issue_body')->count())->toBe(1);
});

it('saves after cancelling and reopening the edit form on the latest revision', function (): void {
    $issue = revisionTestIssue();

    $component = Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)->call('beginEdit');
    app(ReviseTodoIssue::class)->handle($issue->id, 3, title: 'Agent edit');
    $component->set('editTitle', 'My edit')->call('saveIssue')->assertSet('editingIssue', true);

    $component->call('cancelEdit')->assertSet('editRevision', 0)
        ->call('beginEdit')->assertSet('editRevision', 4)->assertSet('editTitle', 'Agent edit')
        ->set('editTitle', 'My edit')->call('saveIssue')->assertSet('editingIssue', false)->assertSet('editError', null);

    expect($issue->refresh())->title->toBe('My edit')->revision->toBe(5);
});
