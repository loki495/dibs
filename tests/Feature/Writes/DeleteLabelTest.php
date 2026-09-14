<?php

declare(strict_types=1);

use App\Actions\DeleteLabel;
use App\Models\GitHubPushQueueItem;
use App\Models\Label;
use Illuminate\Support\Facades\Http;

it('soft-deletes a label and enqueues its remote deletion', function (): void {
    Http::fake();
    $label = Label::factory()->create(['github_node_id' => 'L_1']);

    app(DeleteLabel::class)->handle($label);

    expect($label->fresh()->is_available)->toBeFalse();
    expect(GitHubPushQueueItem::query()->where('operation', 'delete_label')->where('target_id', $label->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('does not enqueue a remote deletion when the label was never pushed to GitHub', function (): void {
    Http::fake();
    $label = Label::factory()->create(['github_node_id' => null]);

    app(DeleteLabel::class)->handle($label);

    expect($label->fresh()->is_available)->toBeFalse();
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});
