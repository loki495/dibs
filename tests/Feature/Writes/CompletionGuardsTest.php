<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\CompleteTodoTask;
use App\Exceptions\TodoValidationException;
use App\Models\ChangeLog;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\TaskClaim;

/** @param  list<string>  $labels */
function guardedIssue(array $labels = [], ?string $body = null, ?Issue $parent = null): Issue
{
    $issue = Issue::factory()->create(['state' => 'OPEN', 'body' => $body, 'parent_issue_id' => $parent?->id]);
    foreach ($labels as $name) {
        $issue->labels()->attach(Label::query()->where('name', $name)->first() ?? Label::factory()->create(['name' => $name]));
    }

    return $issue;
}

function planStep(string $stepBody = "Done-when: it works.\nFiles likely: a.php\nEvidence: SHA", ?string $planBody = null): Issue
{
    return guardedIssue(['agent task'], $stepBody, guardedIssue(['plan'], $planBody));
}

/** @param  array<string, mixed>  $arguments */
function completeGuarded(Issue $issue, array $arguments = []): Issue
{
    $claim = app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);

    return app(CompleteTodoTask::class)->handle(...['issueId' => $issue->id, 'pid' => getmypid(), 'capabilityToken' => $claim['capability_token'], 'summary' => 'Did the work.', ...$arguments]);
}

function fullEvidence(): array
{
    return ['commit' => 'abc1234', 'tests' => ['command' => 'composer pest', 'exit_code' => 0], 'docs' => ['docs/agent-interface.md'], 'done_when_held' => true];
}

it('accepts a plan step with full evidence, keeping it on the closing note and adding no other comment', function (): void {
    $step = planStep();

    $closed = completeGuarded($step, ['result' => fullEvidence() + ['extra' => 'dropped']]);

    $comment = Comment::query()->where('issue_id', $step->id)->sole();
    expect($closed->state)->toBe('CLOSED')
        ->and($comment->kind)->toBe(Comment::KIND_CLOSING)
        ->and($comment->result)->toBe(fullEvidence());
});

it('accepts a regular task with only a summary', function (): void {
    expect(completeGuarded(guardedIssue())->state)->toBe('CLOSED');
});

it('accepts NOT_PLANNED on an agent task with only a summary', function (): void {
    expect(completeGuarded(guardedIssue(['agent task']), ['reason' => 'NOT_PLANNED'])->state)->toBe('CLOSED');
});

it('refuses with every missing field named, leaving the task open and claimed', function (array $labels, array $result, string $expected): void {
    $issue = guardedIssue($labels);

    expect(fn () => completeGuarded($issue, ['result' => $result]))->toThrow(TodoValidationException::class, $expected);
    expect($issue->fresh()->state)->toBe('OPEN')
        ->and(TaskClaim::query()->sole()->released_at)->toBeNull()
        ->and(Comment::query()->count())->toBe(0);
})->with([
    'agent task without a commit' => [['agent task'], [], 'result.commit'],
    'bug without a commit' => [['bug'], [], 'result.commit'],
    'malformed SHA' => [['agent task'], ['commit' => 'not-a-sha'], 'result.commit: expected'],
    'SHA without tests' => [[], ['commit' => 'abc1234', 'docs' => ['README.md']], 'result.tests'],
    'SHA without docs' => [[], ['commit' => 'abc1234', 'tests' => ['command' => 'pest', 'exit_code' => 0]], 'result.docs'],
    'failing tests' => [[], ['commit' => 'abc1234', 'tests' => ['command' => 'pest', 'exit_code' => 1], 'docs' => ['README.md']], 'exit_code 1'],
    'tests without a command' => [[], ['commit' => 'abc1234', 'tests' => ['exit_code' => 0], 'docs' => ['README.md']], 'non-empty command'],
    'empty docs list' => [[], ['commit' => 'abc1234', 'tests' => ['command' => 'pest', 'exit_code' => 0], 'docs' => []], 'at least one doc'],
    'filler none reason' => [['agent task'], ['commit' => 'none: not needed'], 'real reason'],
    'filler-only none reason' => [['agent task'], ['commit' => 'none: just close it please ok'], 'only asks for the close'],
]);

it('accepts "none" with a real reason', function (): void {
    $closed = completeGuarded(guardedIssue(['agent task']), ['result' => ['commit' => 'none: research only, the findings live in the knowledge record']]);

    expect($closed->state)->toBe('CLOSED');
});

it('refuses a plan step whose body has no Done-when line, or whose Done-when is not confirmed', function (string $body, array $result, string $expected): void {
    expect(fn () => completeGuarded(planStep($body), ['result' => $result]))->toThrow(TodoValidationException::class, $expected);
})->with([
    'no Done-when line' => ["Files likely: a.php\nEvidence: SHA", fullEvidence(), 'no Done-when'],
    'done_when_held missing' => ["Done-when: it works.\nEvidence: SHA", array_diff_key(fullEvidence(), ['done_when_held' => true]), 'done_when_held'],
    'done_when_held false' => ["Done-when: it works.\nEvidence: SHA", ['done_when_held' => false] + fullEvidence(), 'done_when_held'],
]);

it('lets force with a real reason override the guard, logging it and noting it on the closing note', function (): void {
    $issue = guardedIssue(['agent task']);

    $closed = completeGuarded($issue, ['force' => true, 'forceReason' => 'the commit lives in the private dotfiles repository, not shareable here']);

    $log = ChangeLog::query()->where('action', 'CompleteTodoTask')->sole();
    expect($closed->state)->toBe('CLOSED')
        ->and($log->summary)->toContain('Guard overridden: the commit lives')
        ->and(json_encode($log->changes))->toContain('result.commit')
        ->and(Comment::query()->sole()->body)->toBe("Did the work.\n\nGuard overridden: the commit lives in the private dotfiles repository, not shareable here");
});

it('refuses force with a filler or missing reason before touching anything', function (?string $reason): void {
    $issue = guardedIssue(['agent task']);

    expect(fn () => completeGuarded($issue, ['force' => true, 'forceReason' => $reason]))->toThrow(TodoValidationException::class, 'force needs a real reason');
    expect($issue->fresh()->state)->toBe('OPEN')
        ->and(ChangeLog::query()->where('action', 'CompleteTodoTask')->exists())->toBeFalse();
})->with([null, 'force', 'just close it please, force override ok']);

it('applies the Group profile, and a plan or task Guards line beats it', function (): void {
    config(['dibs.guards.groups' => ['homelab' => 'floor']]);
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create(['name' => 'Homelab']);
    $inGroup = function (Issue $issue) use ($project, $group): Issue {
        ProjectItem::factory()->for($project, 'project')->for($issue, 'issue')->create(['group_option_id' => $group->id]);

        return $issue;
    };

    expect(completeGuarded($inGroup(guardedIssue(['agent task'])))->state)->toBe('CLOSED');

    $strictPlanStep = $inGroup(planStep("Done-when: ok.\nEvidence: x", 'Guards: strict'));
    expect(fn () => completeGuarded($strictPlanStep, ['result' => ['commit' => 'abc1234', 'tests' => 'none: there is no test harness in this repository', 'docs' => ['README.md'], 'done_when_held' => true]]))
        ->toThrow(TodoValidationException::class, 'strict profile does not accept "none"');

    TaskClaim::query()->delete();
    $floorTask = $inGroup(planStep("Guards: floor\nDone-when: ok.", 'Guards: strict'));
    expect(completeGuarded($floorTask)->state)->toBe('CLOSED');
});

it('uses the default profile from config and refuses an unknown profile name', function (): void {
    config(['dibs.guards.default' => 'floor']);
    expect(completeGuarded(guardedIssue(['agent task']))->state)->toBe('CLOSED');

    expect(fn () => completeGuarded(guardedIssue(['agent task'], 'Guards: strcit')))->toThrow(TodoValidationException::class, 'guard profile "strcit" is unknown');
});

it('ignores a Guards line written by an untrusted GitHub author, so it cannot lower the guard', function (): void {
    $issue = guardedIssue(['agent task'], 'Guards: floor');
    $issue->update(['author_login' => 'mallory']);

    expect(fn () => completeGuarded($issue))->toThrow(TodoValidationException::class, 'result.commit');
});
