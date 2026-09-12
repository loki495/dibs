<?php

declare(strict_types=1);

use App\Actions\DescribeTodoServer;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;

it('reports repository identity and record counts without a local import', function (): void {
    $description = app(DescribeTodoServer::class)->handle();

    expect($description['repository']['owner'])->toBe(config('github.owner'))
        ->and($description['repository']['name'])->toBe(config('github.repository'))
        ->and($description['repository']['imported'])->toBeFalse()
        ->and($description['repository']['full_name'])->toBeNull()
        ->and($description['counts'])->toBe(['issues' => 0, 'labels' => 0, 'projects' => 0]);
});

it('reports the imported repository and counts only available records', function (): void {
    $repository = GitHubRepository::factory()->create(['full_name' => 'loki495/Todo', 'is_available' => true]);
    Issue::factory()->for($repository, 'repository')->create(['is_available' => true]);
    Issue::factory()->for($repository, 'repository')->create(['is_available' => false]);
    Label::factory()->for($repository, 'repository')->create(['is_available' => true]);
    GitHubProject::factory()->create(['is_available' => true]);
    GitHubProject::factory()->create(['is_available' => false]);

    $description = app(DescribeTodoServer::class)->handle();

    expect($description['repository']['imported'])->toBeTrue()
        ->and($description['repository']['full_name'])->toBe('loki495/Todo')
        ->and($description['counts'])->toBe(['issues' => 1, 'labels' => 1, 'projects' => 1]);
});

it('never includes the GitHub token or other secrets', function (): void {
    config(['github.token' => 'super-secret-token']);

    $description = app(DescribeTodoServer::class)->handle();

    expect(json_encode($description))->not->toContain('super-secret-token');
});
