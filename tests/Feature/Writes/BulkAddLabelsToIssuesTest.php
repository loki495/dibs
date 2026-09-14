<?php

declare(strict_types=1);

use App\Actions\BulkAddLabelsToIssues;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use Illuminate\Support\Facades\Http;

it('adds labels to several tasks at once, keeping labels they already had', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create();
    $kept = Label::factory()->for($repository, 'repository')->create();
    $added = Label::factory()->for($repository, 'repository')->create();
    $withLabel = Issue::factory()->for($repository, 'repository')->create();
    $withLabel->labels()->attach($kept);
    $withoutLabel = Issue::factory()->for($repository, 'repository')->create();

    $updated = app(BulkAddLabelsToIssues::class)->handle([$withLabel->id, $withoutLabel->id], [$added->id]);

    expect($updated)->toBe(2);
    expect($withLabel->labels()->pluck('labels.id')->all())->toEqualCanonicalizing([$kept->id, $added->id]);
    expect($withoutLabel->labels()->pluck('labels.id')->all())->toBe([$added->id]);
    expect(GitHubPushQueueItem::query()->where('operation', 'add_issue_labels')->count())->toBe(2);
});

it('skips a task that already has every requested label, without enqueueing a no-op push', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create();
    $label = Label::factory()->for($repository, 'repository')->create();
    $issue = Issue::factory()->for($repository, 'repository')->create();
    $issue->labels()->attach($label);

    $updated = app(BulkAddLabelsToIssues::class)->handle([$issue->id], [$label->id]);

    expect($updated)->toBe(0);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('rejects a label that is no longer available', function (): void {
    $issue = Issue::factory()->create();

    expect(fn () => app(BulkAddLabelsToIssues::class)->handle([$issue->id], [999999]))
        ->toThrow(TodoValidationException::class);
});
