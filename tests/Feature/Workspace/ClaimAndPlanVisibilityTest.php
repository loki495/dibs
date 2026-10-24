<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\TaskClaim;
use App\Models\User;
use App\Services\Process\LinuxProcessLiveness;
use Livewire\Livewire;

it('shows an active claim with a release action', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Claimed by codex')->assertSee('Release claim');
});

it('shows the watcher\'s recorded liveness in the detail panel where host PIDs are not visible', function (?bool $recorded, int $ageSeconds, string $expected): void {
    $this->freezeSecond();
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    TaskClaim::query()->sole()->update(['liveness_alive' => $recorded, 'liveness_checked_at' => $recorded === null ? null : now()->subSeconds($ageSeconds)]);
    app()->instance(LinuxProcessLiveness::class, new LinuxProcessLiveness(enabled: false));

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Claimed by codex')->assertSee($expected);
})->with([
    'recorded alive' => [true, 20, 'alive · checked 20 seconds ago'],
    'recorded gone' => [false, 20, 'process gone · checked 20 seconds ago'],
    'never recorded' => [null, 0, '(liveness unverifiable)'],
    'stale recording' => [true, 600, '(liveness unverifiable)'],
]);

it('shows no liveness note for a claim it checked directly and found alive', function (): void {
    $issue = Issue::factory()->create();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Claimed by codex')->assertDontSee('(liveness unverifiable)')->assertDontSeeHtml('data-claim-liveness');
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

it('shows a pending push-queue badge linking to the push-queue page', function (): void {
    $issue = Issue::factory()->create();
    GitHubPushQueueItem::factory()->create(['target_type' => 'issue', 'target_id' => $issue->id, 'status' => 'needs_attention']);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Pending GitHub sync')->assertSeeHtml(route('push-queue'));
});

it('lists explicit links, adds one from a search and removes it from the detail panel', function (): void {
    $issue = Issue::factory()->create();
    $guide = Issue::factory()->create(['title' => 'How this website deploys']);
    $guide->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create(['name' => 'guide']));

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('No links yet.')
        ->set('linkSearch', 'deploys')->assertSee('How this website deploys')
        ->call('addLink', $guide->id)->assertSee('How this website deploys')->assertDontSee('No links yet.')->assertSet('linkSearch', '')
        ->call('removeLink', $guide->id)->assertSee('No links yet.');

    expect($issue->links()->count())->toBe(0);
});

it('shows the incoming side of a link and a handled error for a bad link', function (): void {
    $issue = Issue::factory()->create();
    $source = Issue::factory()->create(['title' => 'Points at me']);
    $source->links()->create(['to_issue_id' => $issue->id]);

    Livewire::actingAs(User::factory()->create())->test('pages::workspace')->set('selected', $issue->id)
        ->assertSee('Linked from')->assertSee('Points at me')
        ->call('addLink', 999999)->assertSee('does not exist');
});
