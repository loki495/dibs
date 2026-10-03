<?php

declare(strict_types=1);

use App\Actions\CreateLabel;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Label;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['github.owner' => 'example-owner', 'github.repository' => 'example-tasks']);
    GitHubRepository::factory()->create(['owner' => 'example-owner', 'name' => 'example-tasks', 'full_name' => 'example-owner/example-tasks']);
});

it('creates a lowercased label and enqueues its GitHub creation', function (): void {
    Http::fake();

    $label = app(CreateLabel::class)->handle('Agent Task');

    expect($label->name)->toBe('agent task')
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_label')->where('target_id', $label->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('normalizes irregular whitespace the same way every other label write does', function (): void {
    $label = app(CreateLabel::class)->handle('  needs   research ');

    expect($label->name)->toBe('needs research');
});

it('rejects a blank name, creating nothing', function (): void {
    expect(fn () => app(CreateLabel::class)->handle('   '))
        ->toThrow(TodoValidationException::class, 'A label needs a name.');
    expect(Label::query()->count())->toBe(0);
});

it('rejects a name that already exists, case-insensitively, creating nothing', function (): void {
    Label::factory()->create(['repository_id' => GitHubRepository::query()->sole()->id, 'name' => 'bug']);

    expect(fn () => app(CreateLabel::class)->handle('BUG'))
        ->toThrow(TodoValidationException::class, 'A label with this name already exists.');
    expect(Label::query()->count())->toBe(1);
});

it('rejects a name matching a label GitHub no longer has, since a create would still collide locally', function (): void {
    Label::factory()->create(['repository_id' => GitHubRepository::query()->sole()->id, 'name' => 'bug', 'is_available' => false]);

    expect(fn () => app(CreateLabel::class)->handle('bug'))
        ->toThrow(TodoValidationException::class, 'A label with this name already exists.');
});

it('refuses to create a label when the configured GitHub repository has not been imported yet', function (): void {
    GitHubRepository::query()->delete();

    expect(fn () => app(CreateLabel::class)->handle('bug'))
        ->toThrow(TodoValidationException::class, 'GitHub mirroring is configured for example-owner/example-tasks, but that repository has not been imported yet.');
    expect(Label::query()->count())->toBe(0);
});
