<?php

declare(strict_types=1);

use App\Actions\SyncIssueLabels;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;

it('replaces the issue labels and enqueues only the add/remove difference', function (): void {
    $issue = Issue::factory()->create();
    $kept = Label::factory()->for($issue->repository, 'repository')->create();
    $removed = Label::factory()->for($issue->repository, 'repository')->create();
    $added = Label::factory()->for($issue->repository, 'repository')->create();
    $issue->labels()->attach([$kept->id, $removed->id]);

    app(SyncIssueLabels::class)->handle($issue, collect([$kept, $added]));

    expect($issue->labels()->pluck('labels.id')->sort()->values()->all())->toBe(collect([$kept->id, $added->id])->sort()->values()->all());
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->where('target_id', $issue->id)->sole();
    expect($enqueued->payload)->toBe(['add_label_ids' => [$added->id], 'remove_label_ids' => [$removed->id]]);
});

it('does nothing and enqueues nothing when the labels are unchanged', function (): void {
    $issue = Issue::factory()->create();
    $label = Label::factory()->for($issue->repository, 'repository')->create();
    $issue->labels()->attach($label);

    app(SyncIssueLabels::class)->handle($issue, collect([$label]));

    expect($issue->labels()->count())->toBe(1);
    expect(GitHubPushQueueItem::query()->count())->toBe(0);
});

it('removes every label when given an empty set', function (): void {
    $issue = Issue::factory()->create();
    $label = Label::factory()->for($issue->repository, 'repository')->create();
    $issue->labels()->attach($label);

    app(SyncIssueLabels::class)->handle($issue, collect());

    expect($issue->labels()->count())->toBe(0);
    $enqueued = GitHubPushQueueItem::query()->where('operation', 'set_issue_labels')->sole();
    expect($enqueued->payload)->toBe(['add_label_ids' => [], 'remove_label_ids' => [$label->id]]);
});
