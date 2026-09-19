<?php

declare(strict_types=1);

use App\Actions\UpdateGitHubProject;
use App\Models\GitHubProject;
use App\Models\User;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function projectSettingsFor(GitHubProject $project): Testable
{
    return Livewire::actingAs(User::factory()->create())->test('pages::workspace')
        ->call('chooseArea', $project->id)->call('openProjectSettings')->assertSet('projectSettingsOpen', true);
}

function neverCallGitHubProjectUpdate(): void
{
    $update = Mockery::mock(UpdateGitHubProject::class);
    $update->shouldNotReceive('handle');
    app()->instance(UpdateGitHubProject::class, $update);
}

it('changes only the display color without contacting GitHub, lowercasing it', function (): void {
    config(['github.token' => 'test-token']);
    Http::fake();
    neverCallGitHubProjectUpdate();
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);

    projectSettingsFor($project)->set('projectSettingsColor', '7C3AED')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', false)->assertSet('projectSettingsError', null);

    expect($project->refresh())->color->toBe('7c3aed')->title->toBe('Old area');
    Http::assertNothingSent();
});

it('treats a title that only differs by surrounding whitespace as unchanged', function (): void {
    config(['github.token' => 'test-token']);
    neverCallGitHubProjectUpdate();
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);

    projectSettingsFor($project)->set('projectSettingsTitle', '  Old area  ')->set('projectSettingsColor', '111111')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', false);

    expect($project->refresh())->color->toBe('111111')->title->toBe('Old area');
});

it('keeps the old color when GitHub rejects the rename', function (): void {
    config(['github.token' => 'test-token']);
    $update = Mockery::mock(UpdateGitHubProject::class);
    $update->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));
    app()->instance(UpdateGitHubProject::class, $update);
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);

    projectSettingsFor($project)->set('projectSettingsTitle', 'New area')->set('projectSettingsColor', '7c3aed')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', true)->assertSet('projectSettingsError', 'GitHub is temporarily unavailable.');

    expect($project->refresh())->color->toBe('0f766e')->title->toBe('Old area');
});

it('refuses to save without a server-side GitHub token and changes nothing', function (): void {
    config(['github.token' => null]);
    neverCallGitHubProjectUpdate();
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);

    projectSettingsFor($project)->set('projectSettingsColor', '7c3aed')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', true)
        ->assertSet('projectSettingsError', 'Project settings need a server-side GitHub token. Set GITHUB_TOKEN and try again.');

    expect($project->refresh()->color)->toBe('0f766e');
});

it('rejects a color that is not six hex digits and changes nothing', function (): void {
    config(['github.token' => 'test-token']);
    neverCallGitHubProjectUpdate();
    $project = GitHubProject::factory()->create(['color' => '0f766e']);

    projectSettingsFor($project)->set('projectSettingsColor', 'not-a-color')->call('saveProjectSettings')
        ->assertHasErrors(['projectSettingsColor' => 'regex'])->assertSet('projectSettingsOpen', true);

    expect($project->refresh()->color)->toBe('0f766e');
});

it('rejects a blank project name with a validation error and changes nothing', function (): void {
    config(['github.token' => 'test-token']);
    neverCallGitHubProjectUpdate();
    $project = GitHubProject::factory()->create(['title' => 'Old area']);

    projectSettingsFor($project)->set('projectSettingsTitle', '')->call('saveProjectSettings')
        ->assertHasErrors(['projectSettingsTitle' => 'required'])->assertSet('projectSettingsOpen', true);

    expect($project->refresh()->title)->toBe('Old area');
});

it('reports a project that became unavailable and changes nothing', function (): void {
    config(['github.token' => 'test-token']);
    neverCallGitHubProjectUpdate();
    $project = GitHubProject::factory()->create(['color' => '0f766e']);

    $component = projectSettingsFor($project);
    $project->update(['is_available' => false]);
    $component->set('projectSettingsColor', '7c3aed')->call('saveProjectSettings')
        ->assertSet('projectSettingsOpen', true)
        ->assertSet('projectSettingsError', 'The selected project is no longer available. Refresh and try again.');

    expect($project->refresh()->color)->toBe('0f766e');
});
