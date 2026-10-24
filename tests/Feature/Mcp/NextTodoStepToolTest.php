<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\CloseTodoIssue;
use App\Actions\DescribeWorkOrder;
use App\Actions\FindNextPlanStep;
use App\Actions\ScaffoldTodoPlan;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoValidationException;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\ClaimTodoTask;
use App\Mcp\Tools\NextTodoStep;
use App\Models\Comment;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\TaskClaim;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

/** @param  list<array<string, mixed>>  $children */
function nextPlan(array $children = [['title' => 'A'], ['title' => 'B'], ['title' => 'C']]): Issue
{
    return app(ScaffoldTodoPlan::class)->handle(title: 'Plan', children: $children);
}

function ruleRecord(string $title, string $body = 'Rule text.'): Issue
{
    $rule = Issue::factory()->create(['title' => $title, 'body' => $body, 'revision' => 1, 'author_login' => null]);
    $rule->labels()->attach(Label::query()->firstOrCreate(['repository_id' => $rule->repository_id, 'name' => 'rule'], Label::factory()->raw(['repository_id' => $rule->repository_id, 'name' => 'rule'])));

    return $rule;
}

it('exposes the tool under the todo_next name', function (): void {
    expect(app(NextTodoStep::class)->name())->toBe('todo_next');
});

it('returns the first open step in plan order with its work order', function (): void {
    $rule = ruleRecord('Rule: claim first');
    $lesson = Issue::factory()->create(['title' => 'Lesson: SQLite locks', 'author_login' => null]);
    $plan = nextPlan([
        ['title' => 'Done already'],
        ['title' => 'Build it', 'body' => "Objective: build the thing.\nTier: Sonnet\nEvidence: SHA and tests.", 'step' => ['doneWhen' => 'It builds.', 'filesLikely' => 'app/Thing.php'], 'addLinks' => [['issueId' => $rule->id], ['issueId' => $lesson->id]]],
        ['title' => 'Later'],
    ]);
    [$done, $build] = $plan->children->all();
    app(CloseTodoIssue::class)->handle($done);
    Comment::factory()->for($build, 'issue')->create(['body' => 'Checkpoint: halfway.', 'author_login' => null, 'kind' => null]);
    Comment::factory()->for($build, 'issue')->create(['body' => 'closing words', 'author_login' => null, 'kind' => Comment::KIND_CLOSING]);

    $result = app(FindNextPlanStep::class)->handle($plan->id);

    expect($result['reason'])->toBeNull()
        ->and($result['ready'])->toMatchArray([
            'step' => ['id' => $build->id, 'title' => 'Build it', 'revision' => $build->fresh()->revision, 'state' => 'OPEN'],
            'plan' => ['id' => $plan->id, 'title' => 'Plan'],
            'objective' => 'build the thing.', 'tier' => 'Sonnet', 'doneWhen' => 'It builds.', 'evidence' => 'SHA and tests.', 'filesLikely' => 'app/Thing.php',
            'rules' => [['id' => $rule->id, 'title' => 'Rule: claim first']],
            'truncated' => false,
        ])
        ->and($result['ready']['otherLinks'][0])->toMatchArray(['id' => $lesson->id, 'title' => 'Lesson: SQLite locks'])
        ->and($result['ready']['lastCheckpoint']['text'])->toBe('Checkpoint: halfway.');

    TodoServer::tool(NextTodoStep::class, ['planId' => $plan->id])->assertOk()->assertHasNoErrors()->assertSee('Build it')->assertDontSee('Rule text.');
});

it('skips a step held by a live claim, but treats an expired claim as free', function (): void {
    $plan = nextPlan();
    [$a, $b] = $plan->children->all();
    app(ClaimTaskForAgent::class)->handle($a, 'claude:one', getmypid(), 30);

    expect(app(FindNextPlanStep::class)->handle($plan->id))
        ->ready->step->id->toBe($b->id)
        ->waiting->toBe([['id' => $a->id, 'title' => 'A', 'claimedBy' => 'claude:one']]);

    TaskClaim::query()->update(['expires_at' => now()->subMinute()]);
    expect(app(FindNextPlanStep::class)->handle($plan->id)['ready']['step']['id'])->toBe($a->id);
});

it('skips a blocked step until every blocker closes', function (): void {
    $plan = nextPlan([['title' => 'A'], ['title' => 'B', 'blockedBy' => [0]]]);
    [$a, $b] = $plan->children->all();
    app(ClaimTaskForAgent::class)->handle($a, 'claude:one', getmypid(), 30);

    expect(app(FindNextPlanStep::class)->handle($plan->id))
        ->ready->toBeNull()
        ->reason->toBe('mixed')
        ->waiting->toBe([['id' => $a->id, 'title' => 'A', 'claimedBy' => 'claude:one'], ['id' => $b->id, 'title' => 'B', 'blockedBy' => [$a->id]]]);

    TaskClaim::query()->update(['released_at' => now()]);
    app(CloseTodoIssue::class)->handle($a);
    expect(app(FindNextPlanStep::class)->handle($plan->id)['ready']['step']['id'])->toBe($b->id);
});

it('says why nothing is ready', function (Closure $arrange, string $reason): void {
    $plan = nextPlan([['title' => 'A'], ['title' => 'B', 'blockedBy' => [0]]]);
    $arrange(...$plan->children->all());

    expect(app(FindNextPlanStep::class)->handle($plan->id))->ready->toBeNull()->reason->toBe($reason);
})->with([
    'plan done' => [function (Issue $a, Issue $b): void {
        app(CloseTodoIssue::class)->handle($a);
        app(CloseTodoIssue::class)->handle($b);
    }, 'plan_done'],
    'all claimed' => [function (Issue $a, Issue $b): void {
        $b->blockedBy()->detach();
        app(ClaimTaskForAgent::class)->handle($a, 'x', getmypid(), 30);
        app(ClaimTaskForAgent::class)->handle($b, 'y', getmypid(), 30);
    }, 'all_claimed'],
    'blocked' => [function (Issue $a, Issue $b): void {
        $a->blockedBy()->attach($b->id);
        $b->blockedBy()->sync([$a->id]);
    }, 'blocked'],
]);

it('skips container children and steps written by untrusted authors', function (): void {
    $plan = nextPlan();
    [$a, $b, $c] = $plan->children->all();
    Issue::factory()->create(['parent_issue_id' => $a->id]);
    $b->update(['author_login' => 'mallory']);

    expect(app(FindNextPlanStep::class)->handle($plan->id)['ready']['step']['id'])->toBe($c->id);
});

it('errors for a missing plan or one without steps', function (): void {
    expect(fn () => app(FindNextPlanStep::class)->handle(999_999))->toThrow(TodoRecordNotFoundException::class)
        ->and(fn () => app(FindNextPlanStep::class)->handle(Issue::factory()->create()->id))->toThrow(TodoValidationException::class, 'has no steps');

    TodoServer::tool(NextTodoStep::class, ['planId' => 999_999])->assertHasErrors();
    TodoServer::tool(NextTodoStep::class, [])->assertHasErrors();
});

it('sends a rule text once per agent and revision through todo_claim, again after a revision or a context reset', function (): void {
    $rule = ruleRecord('Rule: tests', 'Cover sad paths.');
    $plan = nextPlan([['title' => 'A', 'addLinks' => [['issueId' => $rule->id]]], ['title' => 'B', 'addLinks' => [['issueId' => $rule->id]]], ['title' => 'C', 'addLinks' => [['issueId' => $rule->id]]]]);
    [$a, $b, $c] = $plan->children->all();

    TodoServer::tool(ClaimTodoTask::class, ['issueId' => $a->id, 'agentName' => 'claude:s:1'])->assertOk()->assertSee('Cover sad paths.');
    TodoServer::tool(ClaimTodoTask::class, ['issueId' => $b->id, 'agentName' => 'claude:s:1'])->assertOk()->assertSee('Rule: tests')->assertDontSee('Cover sad paths.');
    TodoServer::tool(ClaimTodoTask::class, ['issueId' => $c->id, 'agentName' => 'codex:t:2'])->assertOk()->assertSee('Cover sad paths.');

    $rule->update(['body' => 'Cover sad paths, revised.', 'revision' => 2]);
    expect(app(DescribeWorkOrder::class)->handle($a, 'claude:s:1')['rules'][0])->toBe(['id' => $rule->id, 'title' => 'Rule: tests', 'revision' => 2, 'text' => 'Cover sad paths, revised.'])
        ->and(app(DescribeWorkOrder::class)->handle($a, 'claude:s:1')['rules'][0])->not->toHaveKey('text')
        ->and(app(DescribeWorkOrder::class)->handle($a, 'claude:s:1', contextReset: true)['rules'][0]['text'])->toBe('Cover sad paths, revised.');
});

it('leaves out rule text that does not fit the cap, without marking it delivered', function (): void {
    $rule = ruleRecord('Rule: huge', str_repeat('long ', 3000));
    $plan = nextPlan([['title' => 'A', 'addLinks' => [['issueId' => $rule->id]]]]);
    $a = $plan->children->first();

    $order = app(DescribeWorkOrder::class)->handle($a, 'claude:s:1');

    expect($order['rules'][0])->not->toHaveKey('text')
        ->and($order['truncated'])->toBeTrue()
        ->and(mb_strlen((string) json_encode($order)))->toBeLessThanOrEqual(DescribeWorkOrder::MAX_CHARS + 100);
    $rule->update(['body' => 'short now', 'revision' => 2]);
    expect(app(DescribeWorkOrder::class)->handle($a, 'claude:s:1')['rules'][0]['text'])->toBe('short now');
});

it('caps a long body and checkpoint', function (): void {
    $plan = nextPlan([['title' => 'A', 'body' => str_repeat('objective words ', 1000), 'step' => ['filesLikely' => str_repeat('file.php ', 500)]]]);
    $a = $plan->children->first();
    Comment::factory()->for($a, 'issue')->create(['body' => str_repeat('checkpoint ', 500), 'author_login' => null, 'kind' => null]);

    $order = app(DescribeWorkOrder::class)->handle($a);

    expect(mb_strlen((string) json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->toBeLessThanOrEqual(DescribeWorkOrder::MAX_CHARS)
        ->and($order['truncated'])->toBeTrue()
        ->and(mb_strlen($order['objective']))->toBeLessThanOrEqual(DescribeWorkOrder::OBJECTIVE_LENGTH);
});

function liveShapedRule(string $title): Issue
{
    return ruleRecord($title, "Rule: ALWAYS do the thing for {$title}. ".str_repeat('Detail sentence. ', 12)."\nTrigger: before commit.\nCheck: ".str_repeat('a check ', 20)."\nApplies to: all projects.\nSource: ai/RULES.md sections.\nMerged candidates: R1, R2");
}

it('sends only the rule statement, so 20 live-shaped rules fit the cap', function (): void {
    $rules = collect(range(1, 20))->map(fn (int $n): Issue => liveShapedRule("Rule {$n}"));
    $plan = nextPlan([['title' => 'A', 'addLinks' => $rules->map(fn (Issue $r): array => ['issueId' => $r->id])->all()]]);

    $order = app(DescribeWorkOrder::class)->handle($plan->children->first(), 'claude:s:1');

    expect(collect($order['rules'])->every(fn (array $r): bool => isset($r['text']) && str_starts_with($r['text'], 'Rule: ALWAYS') && ! str_contains($r['text'], 'Trigger:')))->toBeTrue()
        ->and($order)->not->toHaveKey('rulesPending')
        ->and($order['truncated'])->toBeFalse()
        ->and(collect($order['rules'])->sum(fn (array $r): int => mb_strlen($r['text'])))->toBeLessThanOrEqual(DescribeWorkOrder::RULE_TEXT_MAX_CHARS);
});

it('keeps a rule without metadata whole, and a metadata-only rule as is', function (): void {
    $plain = ruleRecord('Rule: plain', 'Just the rule.');
    $bare = ruleRecord('Rule: bare', "Trigger: always.\nCheck: never.");
    $plan = nextPlan([['title' => 'A', 'addLinks' => [['issueId' => $plain->id], ['issueId' => $bare->id]]]]);

    $rules = app(DescribeWorkOrder::class)->handle($plan->children->first(), 'claude:s:1')['rules'];

    expect($rules[0]['text'])->toBe('Just the rule.')
        ->and($rules[1]['text'])->toBe("Trigger: always.\nCheck: never.");
});

it('lists rules left out for space in rulesPending, and they arrive on the next claim', function (): void {
    $big = ruleRecord('Rule: big', 'Rule: '.str_repeat('word ', 3000));
    $small = ruleRecord('Rule: small', 'Rule: small one.');
    $plan = nextPlan([['title' => 'A', 'addLinks' => [['issueId' => $big->id], ['issueId' => $small->id]]]]);
    $a = $plan->children->first();

    $order = app(DescribeWorkOrder::class)->handle($a, 'claude:s:1');

    expect($order['rulesPending'])->toBe([$big->id])
        ->and($order['hint'])->toContain('todo_show')
        ->and($order['rules'][1]['text'])->toBe('Rule: small one.');
    $big->update(['body' => 'Rule: short now.', 'revision' => 2]);
    $next = app(DescribeWorkOrder::class)->handle($a, 'claude:s:1');
    expect($next['rules'][0]['text'])->toBe('Rule: short now.')
        ->and($next)->not->toHaveKey('rulesPending');
});

it('parses Tier and Repos from a mid-line body, with the plan body as the repos fallback', function (): void {
    $plan = nextPlan([
        ['title' => 'A', 'body' => 'Repos: ~/www/personal/dibs, ~/dotfiles (hook). Tier: Sonnet. Objective: x.'],
        ['title' => 'B', 'body' => 'Objective: y.'],
        ['title' => 'C'],
    ]);
    [$a, $b, $c] = $plan->children->all();
    $plan->update(['body' => 'Repo: ~/www/plan-repo']);

    $first = app(DescribeWorkOrder::class)->handle($a);
    expect($first['tier'])->toBe('Sonnet')
        ->and($first['repos'])->toBe('~/www/personal/dibs, ~/dotfiles (hook)')
        ->and(app(DescribeWorkOrder::class)->handle($b)['repos'])->toBe('~/www/plan-repo')
        ->and(app(DescribeWorkOrder::class)->handle($b)['tier'])->toBeNull();

    $plan->update(['body' => null]);
    expect(app(DescribeWorkOrder::class)->handle($c)['repos'])->toBeNull();
});
