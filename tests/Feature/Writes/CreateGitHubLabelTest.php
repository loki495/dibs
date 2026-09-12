<?php

declare(strict_types=1);

use App\Actions\CreateGitHubLabel;
use App\Models\GitHubRepository;
use App\Models\Label;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates a GitHub label then projects it locally', function (): void {
    config(['github.owner' => 'loki495', 'github.repository' => 'Todo']);
    $repository = GitHubRepository::factory()->create(['full_name' => 'loki495/Todo', 'github_node_id' => 'R_todo']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['createLabel' => ['label' => ['id' => 'LA_next', 'name' => 'next', 'color' => '00aa99', 'description' => 'Soon', 'url' => 'https://github.test/next']]]], 200));

    $label = app(CreateGitHubLabel::class)->handle('test-token', 'next', '00aa99', 'Soon');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request->data()['query'], 'createLabel')
        && (array) $request->data()['variables'] === ['repositoryId' => 'R_todo', 'name' => 'next', 'color' => '00aa99', 'description' => 'Soon']);
    expect($label->repository_id)->toBe($repository->id)->and($label->github_node_id)->toBe('LA_next');
});

it('reuses an existing label without a GitHub write', function (): void {
    config(['github.owner' => 'loki495', 'github.repository' => 'Todo']);
    $repository = GitHubRepository::factory()->create(['full_name' => 'loki495/Todo']);
    $label = Label::factory()->for($repository, 'repository')->create(['name' => 'next']);
    Http::fake();

    expect(app(CreateGitHubLabel::class)->handle('test-token', ' NEXT ')->is($label))->toBeTrue();
    Http::assertNothingSent();
});
