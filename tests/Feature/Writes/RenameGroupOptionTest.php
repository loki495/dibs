<?php

declare(strict_types=1);

use App\Actions\RenameGroupOption;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use Illuminate\Support\Facades\Http;

it('renames a Group option locally and enqueues the rename for GitHub', function (): void {
    Http::fake();
    $field = ProjectField::factory()->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Old name', 'github_option_id' => 'O_1']);

    $renamed = app(RenameGroupOption::class)->handle($option, 'New name');

    expect($renamed->name)->toBe('New name');
    expect(GitHubPushQueueItem::query()->where('operation', 'rename_group_option')->where('target_id', $option->id)->where('payload', json_encode(['name' => 'New name']))->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('rejects a blank name', function (): void {
    $field = ProjectField::factory()->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Existing']);

    expect(fn () => app(RenameGroupOption::class)->handle($option, '   '))
        ->toThrow(TodoValidationException::class);
});

it('rejects a name already used by another Group in the same field', function (): void {
    $field = ProjectField::factory()->create(['semantic_key' => 'group']);
    ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Home']);

    expect(fn () => app(RenameGroupOption::class)->handle($option, 'career'))
        ->toThrow(TodoValidationException::class);
});

it('does nothing and enqueues nothing when the name is unchanged', function (): void {
    Http::fake();
    $field = ProjectField::factory()->create(['semantic_key' => 'group']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Career']);

    app(RenameGroupOption::class)->handle($option, 'Career');

    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
