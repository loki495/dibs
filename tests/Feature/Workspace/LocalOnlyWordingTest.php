<?php

declare(strict_types=1);

use App\Actions\ClaimTaskForAgent;
use App\Actions\ResolveActiveRepository;
use App\Models\AgentSession;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\User;
use App\Support\IssueNumber;
use Database\Seeders\DemoSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['github.owner' => '', 'github.repository' => '']);
});

function localIssue(array $attributes = []): Issue
{
    return Issue::factory()->for(app(ResolveActiveRepository::class)->local(), 'repository')
        ->create(['github_node_id' => null, 'github_number' => null, 'url' => null, ...$attributes]);
}

function mirroredIssue(array $attributes = []): Issue
{
    return Issue::factory()->for(GitHubRepository::factory()->create(['is_local' => false]), 'repository')->create($attributes);
}

function workspace(): Testable
{
    return Livewire::actingAs(User::factory()->create())->test('pages::workspace');
}

it('formats a missing issue number as nothing', function (): void {
    expect(IssueNumber::label(null))->toBe('')
        ->and(IssueNumber::label(7))->toBe('#7')
        ->and(IssueNumber::withTitle(null, 'Title'))->toBe('Title')
        ->and(IssueNumber::withTitle(7, 'Title'))->toBe('#7 Title');
});

it('shows an unknown process instead of a bare "pid" for a claim without a pid', function (): void {
    $issue = mirroredIssue();
    app(ClaimTaskForAgent::class)->handle($issue, 'codex', getmypid(), 30);
    AgentSession::query()->update(['pid' => null]);

    workspace()->set('selected', $issue->id)->assertSee('unknown')->assertDontSee('pid ');

    AgentSession::query()->update(['pid' => 4242]);
    workspace()->set('selected', $issue->id)->assertSee('pid 4242');
});

it('describes the sidebar sync state for local-only and mirrored instances', function (): void {
    localIssue();
    workspace()->assertSee('Local only · not mirrored to GitHub')->assertDontSee('Waiting for the first import');

    mirroredIssue();
    workspace()->assertSee('Waiting for the first import')->assertDontSee('not mirrored to GitHub');
});

it('words the delete dialog for local-only and mirrored instances', function (): void {
    $local = localIssue();
    workspace()->set('selected', $local->id)->call('openDeleteConfirm')
        ->assertSee('This deletes the task. You can undo it right after.')->assertDontSee('on GitHub');

    $mirrored = mirroredIssue();
    workspace()->set('selected', $mirrored->id)->call('openDeleteConfirm')
        ->assertSee('This deletes the task on GitHub.');
});

it('renders no empty # for tasks without a number', function (): void {
    $parent = localIssue(['title' => 'Local parent']);
    $child = localIssue(['title' => 'Local child', 'parent_issue_id' => $parent->id]);

    workspace()->assertSeeHtml('aria-label="Open issue Local child"')->assertDontSeeHtml('Open issue : ')
        ->set('selected', $parent->id)->assertSee('Local child')->assertDontSee('# Local child')->assertDontSee('#Local child')
        ->set('selected', $child->id)->assertSee('Local parent')->assertDontSee('# Local parent');

    $numbered = mirroredIssue(['title' => 'Numbered', 'github_number' => 42]);
    workspace()->assertSeeHtml('aria-label="Open issue #42 Numbered"')->assertSee('#42');
});

it('hides the push queue links when local-only and shows them once mirrored', function (): void {
    localIssue();
    workspace()->assertDontSee('Push queue');
    Livewire::actingAs(User::factory()->create())->test('pages::push-queue')->assertSee('local only and not mirrored to GitHub');

    mirroredIssue();
    workspace()->assertSee('Push queue');
    Livewire::actingAs(User::factory()->create())->test('pages::push-queue')->assertDontSee('local only and not mirrored to GitHub');
});

it('keeps the push queue link visible in the demo, which seeds example rows', function (): void {
    config(['dibs.demo_mode' => true]);
    localIssue();

    workspace()->assertSee('Push queue');
});

it('seeds a demo with no fake GitHub links or numbers', function (): void {
    $this->seed(DemoSeeder::class);

    expect(Issue::query()->count())->toBeGreaterThan(0)
        ->and(Issue::query()->whereNotNull('url')->orWhereNotNull('github_number')->orWhereNotNull('github_node_id')->count())->toBe(0);

    $issue = Issue::query()->first();
    workspace()->set('selected', $issue->id)->assertSee('Local only')->assertDontSee('Open in GitHub')->assertDontSee('github.com');
});

it('still renders the Open in GitHub link when a mirrored issue has a url', function (): void {
    $issue = mirroredIssue(['url' => 'https://github.com/o/r/issues/1']);

    workspace()->set('selected', $issue->id)->assertSee('Open in GitHub');
});
