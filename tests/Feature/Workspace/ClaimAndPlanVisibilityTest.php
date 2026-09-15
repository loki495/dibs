<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\TaskClaim;
use App\Models\User;
use Livewire\Livewire;

it('shows an active claim with a release action', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Claimed by codex')->assertSee('Release claim');
});

it('shows a claimed pill on the task list row without opening the detail panel', function (): void {
    $issue = Issue::factory()->create(['title' => 'Claimed row task']);
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->assertSee('Claimed row task')
        ->assertSeeHtml('data-claim-pill="'.$issue->id.'"')
        ->assertSee('codex');
});

it('releases an abandoned claim from the UI without a capability token', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->call('releaseClaim')->assertDontSee('Claimed by codex');

    expect(TaskClaim::query()->sole()->released_at)->not->toBeNull();
});

it('navigates to a parent and child task from the detail panel', function (): void {
    $parent = Issue::factory()->create(['title' => 'Website plan']);
    $issue = Issue::factory()->for($parent, 'parent')->create();
    $child = Issue::factory()->for($issue, 'parent')->create(['title' => 'Do the thing']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Website plan')->assertSee('Do the thing')
        ->call('$set', 'selected', $child->id)->assertSet('selected', $child->id);
});

it('surfaces a related knowledge issue from a sibling task', function (): void {
    $parent = Issue::factory()->create();
    $issue = Issue::factory()->for($parent, 'parent')->create();
    $knowledge = Issue::factory()->for($parent, 'parent')->create(['title' => 'How this website deploys']);
    $knowledge->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create(['name' => 'guide']));

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Related knowledge')->assertSee('How this website deploys');
});

it('shows a pending push-queue badge linking to the push-queue page', function (): void {
    $issue = Issue::factory()->create();
    GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => $issue->id, 'status' => 'needs_attention']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Pending GitHub sync')->assertSeeHtml(route('push-queue'));
});
