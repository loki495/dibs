<?php

declare(strict_types=1);

use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Label;

it('lists labels stored with capitals or odd spacing and changes nothing without --apply', function (): void {
    Label::factory()->create(['name' => 'Resume', 'github_node_id' => 'L_1']);
    Label::factory()->create(['name' => 'agent task', 'github_node_id' => 'L_2']);

    $this->artisan('labels:normalize')
        ->expectsOutputToContain('Resume => resume')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(Label::query()->where('name', 'Resume')->exists())->toBeTrue()
        ->and(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('renames them through the normal push queue with --apply', function (): void {
    $label = Label::factory()->create(['name' => 'Resume', 'github_node_id' => 'L_1']);
    Label::factory()->create(['name' => 'Needs   Research', 'github_node_id' => 'L_2']);
    Label::factory()->create(['name' => 'bug', 'github_node_id' => 'L_3']);

    $this->artisan('labels:normalize --apply')
        ->expectsOutputToContain('Renamed 2 label(s)')
        ->assertExitCode(0);

    expect($label->fresh()->name)->toBe('resume')
        ->and(Label::query()->where('github_node_id', 'L_2')->sole()->name)->toBe('needs research')
        ->and(Label::query()->where('github_node_id', 'L_3')->sole()->name)->toBe('bug')
        ->and(GitHubPushQueueItem::query()->where('operation', 'rename_label')->count())->toBe(2)
        ->and(GitHubPushQueueItem::query()->where('operation', 'rename_label')->where('target_id', $label->id)->sole()->payload)->toBe(['name' => 'resume']);
});

it('says so when every label is already normalized', function (): void {
    Label::factory()->create(['name' => 'agent task']);

    $this->artisan('labels:normalize --apply')
        ->expectsOutputToContain('All label names are already lowercase')
        ->assertExitCode(0);

    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('reports a name collision, skips that label, still renames the others, and exits non-zero', function (): void {
    $repository = GitHubRepository::factory()->create();
    $blocked = Label::factory()->for($repository, 'repository')->create(['name' => 'Resume', 'github_node_id' => 'L_1']);
    Label::factory()->for($repository, 'repository')->create(['name' => 'resume', 'github_node_id' => 'L_2']);
    $other = Label::factory()->for($repository, 'repository')->create(['name' => 'Bug', 'github_node_id' => 'L_3']);

    $this->artisan('labels:normalize --apply')
        ->expectsOutputToContain('Skipped Resume: Another label already has this name.')
        ->expectsOutputToContain('Renamed 1 label(s)')
        ->assertExitCode(1);

    expect($blocked->fresh()->name)->toBe('Resume')
        ->and($other->fresh()->name)->toBe('bug');
});

it('ignores labels that no longer exist on GitHub', function (): void {
    Label::factory()->create(['name' => 'Resume', 'is_available' => false]);

    $this->artisan('labels:normalize --apply')->expectsOutputToContain('All label names are already lowercase')->assertExitCode(0);

    expect(Label::query()->where('name', 'Resume')->exists())->toBeTrue();
});
