<?php

declare(strict_types=1);

use App\Actions\ReportTodoBug;
use App\Exceptions\TodoValidationException;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;

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
        ->and($issue->labels->pluck('name')->all())->toBe(['agent-report'])
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('creates a report with only the required summary and details', function (): void {
    $issue = app(ReportTodoBug::class)->handle(summary: 'Something felt off', details: 'Not sure what tool caused it, but the result looked wrong.');

    expect($issue->body)->toBe('Not sure what tool caused it, but the result looked wrong.')
        ->and($issue->labels->pluck('name')->all())->toBe(['agent-report']);
});

it('reuses the agent-report label case-insensitively across multiple reports', function (): void {
    app(ReportTodoBug::class)->handle(summary: 'First report', details: 'Details one.');
    app(ReportTodoBug::class)->handle(summary: 'Second report', details: 'Details two.');

    expect(Label::query()->whereRaw('LOWER(name) = ?', ['agent-report'])->count())->toBe(1)
        ->and(Issue::query()->whereHas('labels', fn ($query) => $query->where('name', 'agent-report'))->count())->toBe(2);
});

it('throws a validation exception when the repository is not configured', function (): void {
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
