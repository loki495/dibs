<?php

declare(strict_types=1);

use App\Actions\DescribeTodoClaim;
use App\Actions\DescribeWorkOrder;
use App\Actions\FindNextPlanStep;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\TaskClaim;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['github.owner' => '', 'github.repository' => '']);
    $this->seed(DemoSeeder::class);
    $this->plan = Issue::query()->where('title', 'Plan: Add notification digests')->sole();
});

it('gives a new agent a useful work order with linked rules and knowledge', function (): void {
    $steps = $this->plan->children;
    expect($steps)->toHaveCount(5);

    foreach ($steps as $step) {
        $order = app(DescribeWorkOrder::class)->handle($step);
        expect($order['objective'])->not->toBeEmpty()
            ->and($order['doneWhen'])->not->toBeEmpty()
            ->and($order['evidence'])->not->toBeEmpty()
            ->and($order['filesLikely'])->not->toBeEmpty()
            ->and($order['rules'])->not->toBeEmpty()
            ->and($order['otherLinks'])->not->toBeEmpty();
    }

    $done = $steps->first();
    expect($done->state)->toBe('CLOSED')
        ->and($done->comments()->where('kind', Comment::KIND_CLOSING)->sole()->body)->toContain('Simulated');

    $claimed = $steps->firstWhere('title', 'Build the in-app notification bell');
    $order = app(DescribeWorkOrder::class)->handle($claimed, 'demo-test-agent');
    expect($order['rules'][0]['text'])->toContain('Rule:')
        ->and($order['lastCheckpoint']['text'])->toContain('Next:');
});

it('skips simulated claims and blocked integration work when picking the next step', function (): void {
    $next = app(FindNextPlanStep::class)->handle($this->plan->id);

    expect($next['ready']['step']['title'])->toBe('Document notification preferences and recovery')
        ->and($next['waiting'])->toHaveCount(3)
        ->and(collect($next['waiting'])->whereNotNull('claimedBy'))->toHaveCount(2);

    $integration = $this->plan->children()->where('title', 'Verify the notification flow end to end')->sole();
    expect(collect($next['waiting'])->firstWhere('id', $integration->id)['blockedBy'])->toHaveCount(2);

    $this->travel(1)->days();
    expect(TaskClaim::query()->count())->toBe(2);
    foreach (TaskClaim::query()->with('agentSession')->get() as $claim) {
        $status = app(DescribeTodoClaim::class)->handle($claim->issue_id);
        expect($status['agentName'])->toContain('demo')
            ->and($status['isVerifiedLive'])->toBeFalse()
            ->and($status['isCurrentlyAlive'])->toBeNull()
            ->and($status['isExpired'])->toBeFalse()
            ->and($claim->agentSession->pid)->toBeNull()
            ->and($claim->paths)->not->toBeEmpty();
    }
});

it('shows the seeded plan, claims, and linked context in the workspace', function (): void {
    Livewire::actingAs(User::query()->where('email', 'demo@example.com')->sole())
        ->test('pages::workspace')->set('selected', $this->plan->id)
        ->assertSee('Plan: Add notification digests')->assertSee('Simulated')
        ->assertSee('codex:demo:preferences')->assertSee('claude:demo:notification-bell')
        ->assertSee('Rule: Keep notification delivery local and opt-in')
        ->set('selected', $this->plan->children()->where('title', 'Build the in-app notification bell')->sole()->id)
        ->assertSee('liveness unverifiable')->assertSee('Next:')
        ->assertSee('Decision: One notification store, separate delivery channels');
});
