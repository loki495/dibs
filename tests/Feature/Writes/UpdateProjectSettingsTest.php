<?php

declare(strict_types=1);

use App\Actions\UpdateGitHubProject;
use App\Actions\UpdateProjectSettings;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Services\GitHub\GitHubSyncException;
use Mockery\MockInterface;

function mockGitHubProjectUpdate(): MockInterface
{
    $update = Mockery::mock(UpdateGitHubProject::class);
    app()->instance(UpdateGitHubProject::class, $update);

    return $update;
}

it('renames the project through GitHub and stores the color lowercased', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);
    mockGitHubProjectUpdate()->shouldReceive('handle')->once()
        ->with('test-token', Mockery::on(fn (GitHubProject $candidate): bool => $candidate->is($project)), 'New area')
        ->andReturnUsing(function () use ($project): GitHubProject {
            $project->update(['title' => 'New area']);

            return $project->refresh();
        });

    $updated = app(UpdateProjectSettings::class)->handle('test-token', $project->id, 'New area', '7C3AED');

    expect($updated)->title->toBe('New area')->color->toBe('7c3aed');
    expect($project->refresh())->title->toBe('New area')->color->toBe('7c3aed');
});

it('changes only the color without contacting GitHub when the title is unchanged, ignoring surrounding whitespace', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);
    mockGitHubProjectUpdate()->shouldNotReceive('handle');

    app(UpdateProjectSettings::class)->handle('test-token', $project->id, '  Old area  ', '111111');

    expect($project->refresh())->title->toBe('Old area')->color->toBe('111111');
});

it('leaves the color alone and surfaces the error when GitHub rejects the rename', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);
    mockGitHubProjectUpdate()->shouldReceive('handle')->once()->andThrow(new GitHubSyncException('GitHub is temporarily unavailable.'));

    expect(fn () => app(UpdateProjectSettings::class)->handle('test-token', $project->id, 'New area', '7c3aed'))
        ->toThrow(GitHubSyncException::class, 'GitHub is temporarily unavailable.');

    expect($project->refresh())->title->toBe('Old area')->color->toBe('0f766e');
});

it('refuses to run without a server-side GitHub token and changes nothing', function (): void {
    $project = GitHubProject::factory()->create(['color' => '0f766e']);
    mockGitHubProjectUpdate()->shouldNotReceive('handle');

    expect(fn () => app(UpdateProjectSettings::class)->handle('', $project->id, $project->title, '7c3aed'))
        ->toThrow(GitHubSyncException::class, 'Project settings need a server-side GitHub token. Set GITHUB_TOKEN and try again.');

    expect($project->refresh()->color)->toBe('0f766e');
});

it('refuses an unavailable or unknown project and changes nothing', function (): void {
    $gone = GitHubProject::factory()->create(['color' => '0f766e', 'is_available' => false]);
    mockGitHubProjectUpdate()->shouldNotReceive('handle');

    expect(fn () => app(UpdateProjectSettings::class)->handle('test-token', $gone->id, $gone->title, '7c3aed'))
        ->toThrow(TodoRecordUnavailableException::class, 'The selected project is no longer available. Refresh and try again.');
    expect(fn () => app(UpdateProjectSettings::class)->handle('test-token', 999999, 'Anything', '7c3aed'))
        ->toThrow(TodoRecordUnavailableException::class, 'The selected project is no longer available. Refresh and try again.');

    expect($gone->refresh()->color)->toBe('0f766e');
});

it('rejects a color that is not six hex digits before doing anything', function (string $color): void {
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);
    mockGitHubProjectUpdate()->shouldNotReceive('handle');

    expect(fn () => app(UpdateProjectSettings::class)->handle('test-token', $project->id, 'New area', $color))
        ->toThrow(TodoValidationException::class, 'The color must be six hex digits.');

    expect($project->refresh())->title->toBe('Old area')->color->toBe('0f766e');
})->with(['', 'not-a-color', '12345', '1234567', '#7c3aed']);

it('rejects a blank project name before doing anything', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Old area', 'color' => '0f766e']);
    mockGitHubProjectUpdate()->shouldNotReceive('handle');

    expect(fn () => app(UpdateProjectSettings::class)->handle('test-token', $project->id, '   ', '7c3aed'))
        ->toThrow(TodoValidationException::class, 'A project name is required.');

    expect($project->refresh())->title->toBe('Old area')->color->toBe('0f766e');
});
