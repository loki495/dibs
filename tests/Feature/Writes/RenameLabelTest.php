<?php

declare(strict_types=1);

use App\Actions\RenameLabel;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\Label;
use Illuminate\Support\Facades\Http;

it('lowercases the new name, renames the label locally, and enqueues the rename for GitHub', function (): void {
    Http::fake();
    $label = Label::factory()->create(['name' => 'old-name', 'github_node_id' => 'L_1']);

    $renamed = app(RenameLabel::class)->handle($label, 'New Name');

    expect($renamed->name)->toBe('new name');
    expect(GitHubPushQueueItem::query()->where('operation', 'rename_label')->where('target_id', $label->id)->where('payload', json_encode(['name' => 'new name']))->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('rejects a blank name', function (): void {
    $label = Label::factory()->create(['name' => 'existing']);

    expect(fn () => app(RenameLabel::class)->handle($label, '   '))
        ->toThrow(TodoValidationException::class);
});

it('rejects a name already used by another label in the same repository', function (): void {
    $label = Label::factory()->create(['name' => 'urgent']);
    $other = Label::factory()->for($label->repository, 'repository')->create(['name' => 'blocked']);

    expect(fn () => app(RenameLabel::class)->handle($other, 'Urgent'))
        ->toThrow(TodoValidationException::class);
});

it('does nothing and enqueues nothing when the lowercased name is unchanged', function (): void {
    Http::fake();
    $label = Label::factory()->create(['name' => 'urgent']);

    app(RenameLabel::class)->handle($label, 'URGENT');

    expect($label->fresh()->name)->toBe('urgent');
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
