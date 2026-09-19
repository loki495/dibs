<?php

declare(strict_types=1);

use App\Models\ChangeLog;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Services\Activity\ActivityRecorder;

function helperRecorder(): ActivityRecorder
{
    return app(ActivityRecorder::class);
}

it('ignores the revision counter and last_seen_at when diffing a model', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old', 'revision' => 1]);
    $issue->update(['title' => 'New', 'revision' => 2, 'last_seen_at' => now()]);

    expect(helperRecorder()->diffModel($issue))->toBe(['title' => ['from' => 'Old', 'to' => 'New']]);
});

it('limits a model diff to the requested fields', function (): void {
    $issue = Issue::factory()->create(['title' => 'Brand new', 'body' => 'Body']);

    expect(helperRecorder()->diffModel($issue, only: ['title', 'body']))->toBe([
        'title' => ['from' => null, 'to' => 'Brand new'],
        'body' => ['from' => null, 'to' => 'Body'],
    ]);
});

it('records a model change with a summary naming the changed fields', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old', 'body' => 'Old body']);
    $issue->update(['title' => 'New', 'body' => 'New body']);

    $log = helperRecorder()->changeModel('UpdateTodoIssue', $issue);

    expect($log)->toBeInstanceOf(ChangeLog::class)
        ->and($log->summary)->toBe('Updated issue: title, body')
        ->and($log->subject_id)->toBe($issue->id)
        ->and($log->changes)->toBe([
            'title' => ['from' => 'Old', 'to' => 'New'],
            'body' => ['from' => 'Old body', 'to' => 'New body'],
        ]);
});

it('uses the given verb in a model change summary', function (): void {
    $issue = Issue::factory()->create(['title' => 'Old']);
    $issue->update(['title' => 'New']);

    expect(helperRecorder()->changeModel('ReviseTodoIssue', $issue, 'Revised')->summary)->toBe('Revised issue: title');
});

it('records nothing for a model that did not change', function (): void {
    $issue = Issue::factory()->create()->fresh();
    $issue->save();

    expect(helperRecorder()->changeModel('UpdateTodoIssue', $issue))->toBeNull()
        ->and(ChangeLog::count())->toBe(0);
});

it('looks up a record name by id, or null when there is none', function (): void {
    $project = GitHubProject::factory()->create(['title' => 'Personal Projects']);

    expect(helperRecorder()->nameOf(GitHubProject::class, $project->id))->toBe('Personal Projects')
        ->and(helperRecorder()->nameOf(GitHubProject::class, null))->toBeNull()
        ->and(helperRecorder()->nameOf(GitHubProject::class, 999_999))->toBeNull();
});

it('describes an issue as its title and id, falling back to the id when it is gone', function (): void {
    $issue = Issue::factory()->create(['title' => 'Fix the sink']);

    expect(helperRecorder()->issueRef($issue->id))->toBe("Fix the sink (#{$issue->id})")
        ->and(helperRecorder()->issueRef(999_999))->toBe('#999999')
        ->and(helperRecorder()->issueRef(null))->toBeNull();
});
