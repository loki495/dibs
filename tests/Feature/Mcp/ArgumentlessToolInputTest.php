<?php

declare(strict_types=1);

use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\DescribeTodoContext;
use App\Mcp\Tools\DescribeTodoServer;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;

dataset('argumentless tools', [
    'todo_status' => [DescribeTodoServer::class],
    'todo_context' => [DescribeTodoContext::class],
]);

it('requires a placeholder argument in its schema so clients never send an empty input object', function (string $tool): void {
    $schema = app($tool)->toArray()['inputSchema'];

    expect((array) $schema['properties'])->toHaveKey('noop')
        ->and($schema['properties']['noop']['type'])->toBe('boolean')
        ->and($schema['required'])->toContain('noop');
})->with('argumentless tools');

it('still returns data when a caller omits the placeholder argument', function (string $tool): void {
    GitHubRepository::factory()->create(['full_name' => 'loki495/Todo', 'is_available' => true]);
    GitHubProject::factory()->create(['title' => 'Personal Projects']);

    TodoServer::tool($tool)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee($tool === DescribeTodoServer::class ? 'loki495/Todo' : 'Personal Projects');
})->with('argumentless tools');

it('ignores the placeholder argument and still returns data', function (string $tool, mixed $value): void {
    GitHubRepository::factory()->create(['full_name' => 'loki495/Todo', 'is_available' => true]);
    GitHubProject::factory()->create(['title' => 'Personal Projects']);

    TodoServer::tool($tool, ['noop' => $value])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee($tool === DescribeTodoServer::class ? 'loki495/Todo' : 'Personal Projects');
})->with('argumentless tools')->with([true, false, 'not-a-boolean']);
