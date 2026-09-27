<?php

declare(strict_types=1);

use App\Models\GitHubRepository;
use App\Models\Label;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function labelRepository(): GitHubRepository
{
    return GitHubRepository::factory()->create(['full_name' => config('github.owner').'/'.config('github.repository')]);
}

it('opens via the event the gear menu and sidebar dispatch, and renames a label', function (): void {
    Http::fake();
    $label = Label::factory()->for(labelRepository(), 'repository')->create(['name' => 'urgent']);

    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->assertSet('manageLabelsOpen', false)
        ->dispatch('open-manage-labels')
        ->assertSet('manageLabelsOpen', true)->assertSee('urgent')
        ->call('beginRenameLabel', $label->id)->assertSet('managingLabelName', 'urgent')
        ->set('managingLabelName', 'Blocked')->call('saveLabelRename')
        ->assertSet('managingLabelId', 0)->assertSee('blocked')
        ->assertDispatched('labels-changed');

    expect($label->refresh()->name)->toBe('blocked');
});

it('shows a validation error inline instead of closing when a label rename collides', function (): void {
    $repository = labelRepository();
    Label::factory()->for($repository, 'repository')->create(['name' => 'urgent']);
    $blocked = Label::factory()->for($repository, 'repository')->create(['name' => 'blocked']);

    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')
        ->call('beginRenameLabel', $blocked->id)->set('managingLabelName', 'Urgent')->call('saveLabelRename')
        ->assertSet('manageLabelsOpen', true)->assertSee('Another label already has this name.')
        ->assertNotDispatched('labels-changed');

    expect($blocked->refresh()->name)->toBe('blocked');
});

it('deletes a label and announces which one, so open pages can drop it from their filters', function (): void {
    Http::fake();
    $label = Label::factory()->for(labelRepository(), 'repository')->create(['name' => 'urgent']);

    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')->assertSee('urgent')
        ->call('deleteLabelOption', $label->id)
        ->assertDispatched('label-deleted', id: $label->id, name: 'urgent')->assertDontSee('urgent');

    expect($label->refresh()->is_available)->toBeFalse();
});

it('ignores deleting or renaming a label that no longer exists', function (): void {
    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')
        ->call('deleteLabelOption', 999999)->assertNotDispatched('label-deleted')
        ->call('beginRenameLabel', 999999)->assertSet('managingLabelId', 0);
});

it('creates a label from the popup and shows it in the list', function (): void {
    Http::fake();
    labelRepository();

    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')
        ->set('newManageLabelName', 'Waiting on Vendor')->call('createManageLabel')
        ->assertSet('newManageLabelName', '')->assertSee('waiting on vendor')->assertDispatched('labels-changed');

    expect(Label::query()->where('name', 'waiting on vendor')->exists())->toBeTrue();
});

it('shows a validation error inline instead of closing when creating a duplicate label', function (): void {
    Label::factory()->for(labelRepository(), 'repository')->create(['name' => 'bug']);

    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')
        ->set('newManageLabelName', 'Bug')->call('createManageLabel')
        ->assertSet('manageLabelsOpen', true)->assertSee('A label with this name already exists.');

    expect(Label::query()->where('name', 'bug')->count())->toBe(1);
});

it('clears the new-label field and any error each time it is opened', function (): void {
    Label::factory()->for(labelRepository(), 'repository')->create(['name' => 'bug']);

    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')->set('newManageLabelName', 'Bug')->call('createManageLabel')
        ->assertSee('A label with this name already exists.')
        ->call('openManageLabels')
        ->assertSet('newManageLabelName', '')->assertDontSee('A label with this name already exists.');
});

it('lets the popup scroll internally so a long label list never hides the Close button off-screen', function (): void {
    Livewire::actingAs(User::factory()->create())->test('manage-labels')
        ->call('openManageLabels')
        ->assertSeeHtml('data-modal="manage-labels"')
        ->assertSeeHtml('data-flux-modal-overflow');
});

it('is available on every signed-in page, not just the workspace', function (string $path): void {
    $this->actingAs(User::factory()->create())->get($path)
        ->assertOk()
        ->assertSeeHtml('data-modal="manage-labels"');
})->with(['/', '/push-queue', '/activity']);

it('is not shipped to signed-out visitors', function (): void {
    $this->get('/login')->assertOk()->assertDontSeeHtml('data-modal="manage-labels"');
});
