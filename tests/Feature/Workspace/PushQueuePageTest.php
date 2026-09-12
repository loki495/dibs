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

it('is unreachable to a guest', function (): void {
    $this->get('/push-queue')->assertRedirect('/login');
});

it('is hidden entirely when the feature flag is off', function (): void {
    config(['todo.push_queue_ui_enabled' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/push-queue')->assertNotFound();
});
