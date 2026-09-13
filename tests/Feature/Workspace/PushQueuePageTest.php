<?php

declare(strict_types=1);

use App\Models\GitHubPushQueueItem;
use App\Models\User;
use Livewire\Livewire;

it('lists queue rows and lets a signed-in user retry a stuck one', function (): void {
    $user = User::factory()->create();
    $stuck = GitHubPushQueueItem::factory()->create(['operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => 9, 'status' => 'needs_attention', 'attempts' => 3, 'last_error' => 'GitHub HTTP 422']);

    Livewire::actingAs($user)->test('pages::push-queue')
        ->assertSee('create_issue')->assertSee('needs_attention')->assertSee('GitHub HTTP 422')
        ->call('retry', $stuck->id);

    expect($stuck->refresh())->status->toBe('pending')->attempts->toBe(0);
});

it('lets a signed-in user discard a pending row without pushing it', function (): void {
    $user = User::factory()->create();
    $pending = GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    Livewire::actingAs($user)->test('pages::push-queue')
        ->assertSeeHtml('discard('.$pending->id.')')
        ->call('discard', $pending->id);

    expect(GitHubPushQueueItem::query()->find($pending->id))->toBeNull();
});

it('lets a signed-in user discard an individual pushed row too', function (): void {
    $user = User::factory()->create();
    $pushed = GitHubPushQueueItem::factory()->create(['status' => 'pushed']);

    Livewire::actingAs($user)->test('pages::push-queue')
        ->assertSeeHtml('discard('.$pushed->id.')')
        ->call('discard', $pushed->id);

    expect(GitHubPushQueueItem::query()->find($pushed->id))->toBeNull();
});

it('lets a signed-in user clear every pushed row at once', function (): void {
    $user = User::factory()->create();
    GitHubPushQueueItem::factory()->count(3)->create(['status' => 'pushed']);
    $pending = GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    Livewire::actingAs($user)->test('pages::push-queue')
        ->assertSee('Clear pushed')
        ->call('clearPushed');

    expect(GitHubPushQueueItem::query()->where('status', 'pushed')->count())->toBe(0)
        ->and(GitHubPushQueueItem::query()->find($pending->id))->not->toBeNull();
});

it('hides the clear-pushed action when there is nothing pushed', function (): void {
    $user = User::factory()->create();
    GitHubPushQueueItem::factory()->create(['status' => 'pending']);

    Livewire::actingAs($user)->test('pages::push-queue')->assertDontSee('Clear pushed');
});

it('is unreachable to a guest', function (): void {
    $this->get('/push-queue')->assertRedirect('/login');
});

it('is hidden entirely when the feature flag is off', function (): void {
    config(['todo.push_queue_ui_enabled' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/push-queue')->assertNotFound();
});
