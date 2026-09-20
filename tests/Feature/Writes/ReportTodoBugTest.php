<?php

declare(strict_types=1);

use App\Actions\ReportTodoBug;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('creates a labeled issue with a composed body from tool, arguments, and details', function (): void {
    $issue = app(ReportTodoBug::class)->handle(
        summary: 'todo_revise conflict message is wrong',
        details: 'Called todo_revise twice with the same expectedRevision; the second call returned a confusing message.',
        toolOrCommand: 'todo_revise',
        arguments: '{"id":42,"expectedRevision":1}',
    );

    expect($issue->title)->toBe('todo_revise conflict message is wrong')
        ->and($issue->body)->toContain('**Tool/command:** todo_revise')
        ->and($issue->body)->toContain('**Arguments:** {"id":42,"expectedRevision":1}')
        ->and($issue->body)->toContain('Called todo_revise twice')
        ->and($issue->labels->pluck('name')->all())->toBe(['agent report'])
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('creates a report with only the required summary and details', function (): void {
    $issue = app(ReportTodoBug::class)->handle(summary: 'Something felt off', details: 'Not sure what tool caused it, but the result looked wrong.');

    expect($issue->body)->toBe('Not sure what tool caused it, but the result looked wrong.')
        ->and($issue->labels->pluck('name')->all())->toBe(['agent report']);
});

it('reuses the agent report label case-insensitively across multiple reports', function (): void {
    app(ReportTodoBug::class)->handle(summary: 'First report', details: 'Details one.');
    app(ReportTodoBug::class)->handle(summary: 'Second report', details: 'Details two.');

    expect(Label::query()->whereRaw('LOWER(name) = ?', ['agent report'])->count())->toBe(1)
        ->and(Issue::query()->whereHas('labels', fn ($query) => $query->where('name', 'agent report'))->count())->toBe(2);
});

it('throws a validation exception when the repository is not configured', function (): void {
    GitHubRepository::query()->delete();

    expect(fn () => app(ReportTodoBug::class)->handle(summary: 'x', details: 'y'))
        ->toThrow(TodoValidationException::class);
});

it('still fails clearly when the repository is missing and no agent-report area/group/parent is configured either', function (): void {
    // Unlike the test above, this covers the fallback retry path itself (not just the
    // rethrow) - with no DIBS_AGENT_REPORT_*_ID configured at all, ReportTodoBug's catch
    // block takes the "retry unparented" branch instead of rethrowing immediately, and
    // that retry call fails for the same underlying reason (no repository configured).
    config(['dibs.agent_report_area_id' => null, 'dibs.agent_report_group_id' => null, 'dibs.agent_report_parent_id' => null]);
    GitHubRepository::query()->delete();

    expect(fn () => app(ReportTodoBug::class)->handle(summary: 'x', details: 'y'))
        ->toThrow(TodoValidationException::class);
});

it('is idempotent: retrying the same key does not duplicate the report', function (): void {
    $first = app(ReportTodoBug::class)->handle(summary: 'Dup check', details: 'x', idempotencyKey: 'report-1');
    $second = app(ReportTodoBug::class)->handle(summary: 'Dup check', details: 'x', idempotencyKey: 'report-1');

    expect($second->id)->toBe($first->id)
        ->and(Issue::query()->count())->toBe(1);
});

it('nests the report under the configured area, group, and parent when set', function (): void {
    $project = GitHubProject::factory()->create();
    $groupField = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($groupField, 'field')->create();
    $parent = Issue::factory()->create();

    config([
        'dibs.agent_report_area_id' => $project->id,
        'dibs.agent_report_group_id' => $group->id,
        'dibs.agent_report_parent_id' => $parent->id,
    ]);

    $issue = app(ReportTodoBug::class)->handle(summary: 'Nested report', details: 'x');

    expect($issue->parent_issue_id)->toBe($parent->id)
        ->and($issue->projectItems->sole()->group_option_id)->toBe($group->id);
});

it('falls back to an unparented report when the configured container is invalid', function (): void {
    config(['dibs.agent_report_area_id' => 999999, 'dibs.agent_report_parent_id' => 999999]);

    $issue = app(ReportTodoBug::class)->handle(summary: 'Still gets filed', details: 'x');

    expect($issue->exists)->toBeTrue()
        ->and($issue->parent_issue_id)->toBeNull()
        ->and($issue->labels->pluck('name')->all())->toBe(['agent report']);
});
