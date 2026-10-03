<?php

declare(strict_types=1);

use App\Actions\ApplyGitHubSnapshot;
use App\Actions\ClaimTaskForAgent;
use App\Actions\CloseTodoIssue;
use App\Actions\CompleteTodoTask;
use App\Actions\CreateLabel;
use App\Actions\CreateTodoComment;
use App\Actions\CreateTodoIssue;
use App\Actions\DescribeTodoServer;
use App\Actions\ReopenTodoIssue;
use App\Actions\ResolveActiveRepository;
use App\Actions\SyncGitHub;
use App\Actions\UpdateTodoIssue;
use App\Exceptions\TodoValidationException;
use App\Mcp\Servers\TodoServer;
use App\Mcp\Tools\CommentOnTodoTask;
use App\Mcp\Tools\CreateTodoTask;
use App\Models\Comment;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Services\GitHub\GitHubSyncException;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['github.owner' => '', 'github.repository' => '', 'github.token' => 'test-token']);
    Http::fake();
});

function remoteRepositorySnapshot(array $labels = [], array $issues = []): array
{
    return [
        'repository' => ['id' => 'R_remote', 'owner' => ['login' => 'example-owner'], 'name' => 'example-tasks', 'nameWithOwner' => 'example-owner/example-tasks', 'url' => 'https://github.com/example-owner/example-tasks', 'isPrivate' => true, 'visibility' => 'PRIVATE', 'updatedAt' => '2026-10-03T12:00:00Z'],
        'labels' => $labels,
        'issues' => $issues,
        'projects' => [],
    ];
}

function configureGitHub(): void
{
    config(['github.owner' => 'example-owner', 'github.repository' => 'example-tasks']);
}

describe('the local repository row', function (): void {
    it('is created once and reused, however often it is resolved', function (): void {
        $first = app(ResolveActiveRepository::class)->handle();
        $second = app(ResolveActiveRepository::class)->handle();
        $third = app(ResolveActiveRepository::class)->local();

        expect(GitHubRepository::query()->count())->toBe(1)
            ->and($second->id)->toBe($first->id)
            ->and($third->id)->toBe($first->id)
            ->and($first->is_local)->toBeTrue()
            ->and($first->github_node_id)->toBe(GitHubRepository::LOCAL_IDENTITY);
    });

    it('is refused when the instance was already imported from GitHub, rather than splitting tasks across two repositories', function (): void {
        GitHubRepository::factory()->create(['full_name' => 'example-owner/example-tasks']);

        expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Stranded'))
            ->toThrow(TodoValidationException::class, 'This instance was imported from GitHub (example-owner/example-tasks), but DIBS_GITHUB_OWNER and DIBS_GITHUB_REPO are blank.');
        expect(GitHubRepository::query()->where('is_local', true)->exists())->toBeFalse()
            ->and(Issue::query()->count())->toBe(0);
    });
});

describe('local-only writes', function (): void {
    it('creates a task with new labels on the local repository without queueing a push', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Local task', body: 'Notes', newLabelNames: ['Next']);

        expect($issue->repository->is_local)->toBeTrue()
            ->and($issue->github_node_id)->toBeNull()
            ->and($issue->labels->pluck('name')->all())->toBe(['next'])
            ->and(Label::query()->sole()->repository_id)->toBe($issue->repository_id)
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('creates a bare label, and still rejects a duplicate one', function (): void {
        $label = app(CreateLabel::class)->handle('Agent Task');

        expect($label->name)->toBe('agent task')
            ->and($label->repository->is_local)->toBeTrue()
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
        expect(fn () => app(CreateLabel::class)->handle('agent task'))
            ->toThrow(TodoValidationException::class, 'A label with this name already exists.');
    });

    it('edits, comments on, closes and reopens a task without queueing a push', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Local task');

        $updated = app(UpdateTodoIssue::class)->handle(id: $issue->id, expectedRevision: $issue->revision, title: 'Renamed', newLabelNames: ['today']);
        app(CreateTodoComment::class)->handle($issue->id, 'Progress note');
        app(CloseTodoIssue::class)->handle($updated->refresh(), 'COMPLETED', 'Done locally');
        app(ReopenTodoIssue::class)->handle($updated->refresh());

        expect($updated->refresh()->title)->toBe('Renamed')
            ->and($updated->state)->toBe('OPEN')
            ->and($updated->labels->pluck('name')->all())->toBe(['today'])
            ->and(Comment::query()->where('issue_id', $issue->id)->count())->toBe(2)
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('still rejects a blank comment', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Local task');

        expect(fn () => app(CreateTodoComment::class)->handle($issue->id, '  '))
            ->toThrow(TodoValidationException::class, 'A comment body is required.');
        expect(Comment::query()->count())->toBe(0);
    });

    it('runs the claim lifecycle through to completion without queueing a push', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Claimable');

        $claim = app(ClaimTaskForAgent::class)->handle($issue, 'codex', posix_getppid());
        app(CompleteTodoTask::class)->handle($issue->id, posix_getppid(), $claim['capability_token'], 'Finished');

        expect($issue->refresh()->state)->toBe('CLOSED')
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
    });

    it('creates and comments through the MCP tools', function (): void {
        TodoServer::tool(CreateTodoTask::class, ['title' => 'From an agent', 'newLabelName' => 'agent task'])
            ->assertOk()->assertHasNoErrors()->assertSee('From an agent');
        $issue = Issue::query()->sole();

        TodoServer::tool(CommentOnTodoTask::class, ['issueId' => $issue->id, 'body' => 'Checkpoint'])
            ->assertOk()->assertHasNoErrors();

        expect($issue->repository->is_local)->toBeTrue()
            ->and($issue->comments()->sole()->body)->toBe('Checkpoint')
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
    });

    it('returns a structured MCP error, not a crash, when the instance was imported but GitHub is unconfigured', function (): void {
        GitHubRepository::factory()->create(['full_name' => 'example-owner/example-tasks']);

        TodoServer::tool(CreateTodoTask::class, ['title' => 'Nowhere to go'])->assertHasErrors(['DIBS_GITHUB_OWNER']);

        expect(Issue::query()->count())->toBe(0);
    });

    it('creates a task through the CLI', function (): void {
        $this->artisan('todo:agent:create', ['title' => 'From the CLI'])->assertSuccessful();

        expect(Issue::query()->sole()->repository->is_local)->toBeTrue()
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
    });

    it('reports local mode in todo_status', function (): void {
        app(CreateTodoIssue::class)->handle(title: 'Local task');

        $repository = app(DescribeTodoServer::class)->handle()['repository'];

        expect($repository['mode'])->toBe('local')
            ->and($repository['imported'])->toBeFalse()
            ->and($repository['full_name'])->toBeNull();
    });
});

describe('GitHub is never contacted', function (): void {
    it('skips the push-queue drain cleanly', function (): void {
        $this->artisan('todo:push:drain')
            ->expectsOutputToContain('not configured (local-only)')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('refuses a GitHub import with a clear error', function (): void {
        expect(fn () => app(SyncGitHub::class)->handle('test-token'))
            ->toThrow(GitHubSyncException::class, 'GitHub mirroring is not configured.');

        $this->artisan('todo:sync')->expectsOutputToContain('Set DIBS_GITHUB_OWNER and DIBS_GITHUB_REPO')->assertFailed();
        Http::assertNothingSent();
    });
});

describe('configured GitHub mirroring', function (): void {
    it('keeps writing to the imported repository and queueing pushes', function (): void {
        configureGitHub();
        $repository = GitHubRepository::factory()->create(['owner' => 'example-owner', 'name' => 'example-tasks', 'full_name' => 'example-owner/example-tasks']);

        $issue = app(CreateTodoIssue::class)->handle(title: 'Mirrored', newLabelNames: ['next']);

        expect($issue->repository_id)->toBe($repository->id)
            ->and(GitHubRepository::query()->count())->toBe(1)
            ->and(GitHubPushQueueItem::query()->pluck('operation')->sort()->values()->all())->toBe(['add_issue_labels', 'create_issue', 'create_label']);
    });
});

describe('switching a local-only instance to GitHub', function (): void {
    it('adopts the local repository on the first import and queues every local record for GitHub', function (): void {
        $parent = app(CreateTodoIssue::class)->handle(title: 'Local parent', newLabelNames: ['bug', 'next']);
        $child = app(CreateTodoIssue::class)->handle(title: 'Local child', parentId: $parent->id);
        app(CreateTodoComment::class)->handle($child->id, 'A local note');
        app(CloseTodoIssue::class)->handle($child->refresh(), 'NOT_PLANNED');
        $deleted = app(CreateTodoIssue::class)->handle(title: 'Deleted locally');
        $deleted->update(['is_available' => false]);
        $localRepository = GitHubRepository::query()->sole();
        expect(GitHubPushQueueItem::query()->count())->toBe(0);

        configureGitHub();
        app(ApplyGitHubSnapshot::class)->handle(remoteRepositorySnapshot(
            labels: [['id' => 'L_bug', 'name' => 'bug', 'color' => 'd73a4a', 'description' => null]],
        ));

        $repository = GitHubRepository::query()->sole();
        $bug = Label::query()->where('name', 'bug')->sole();
        $next = Label::query()->where('name', 'next')->sole();
        $queued = GitHubPushQueueItem::query()->get()->map(fn (GitHubPushQueueItem $item): string => $item->operation.':'.$item->target_id)->sort()->values()->all();

        expect($repository->id)->toBe($localRepository->id)
            ->and($repository->is_local)->toBeFalse()
            ->and($repository->github_node_id)->toBe('R_remote')
            ->and($repository->full_name)->toBe('example-owner/example-tasks')
            ->and($bug->github_node_id)->toBe('L_bug')
            ->and(Issue::query()->where('is_available', true)->pluck('id')->sort()->values()->all())->toBe([$parent->id, $child->id])
            ->and($queued)->toBe(collect([
                'add_issue_labels:'.$parent->id,
                'close_issue:'.$child->id,
                'create_comment:'.$child->comments()->sole()->id,
                'create_issue:'.$child->id,
                'create_issue:'.$parent->id,
                'create_label:'.$next->id,
                'set_issue_parent:'.$child->id,
            ])->sort()->values()->all())
            ->and(GitHubPushQueueItem::query()->where('operation', 'close_issue')->sole()->payload)->toBe(['stateReason' => 'NOT_PLANNED']);

        $after = app(CreateTodoIssue::class)->handle(title: 'After the switch');
        expect($after->repository_id)->toBe($repository->id)
            ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $after->id)->exists())->toBeTrue();
    });

    it('does not adopt or requeue anything on a later import', function (): void {
        app(CreateTodoIssue::class)->handle(title: 'Local task');
        configureGitHub();
        app(ApplyGitHubSnapshot::class)->handle(remoteRepositorySnapshot());
        GitHubPushQueueItem::query()->update(['status' => 'pushed', 'pushed_at' => now()]);

        app(ApplyGitHubSnapshot::class)->handle(remoteRepositorySnapshot());

        expect(GitHubRepository::query()->count())->toBe(1)
            ->and(GitHubPushQueueItem::query()->where('status', 'pending')->count())->toBe(0);
    });

    it('rejects writes with a clear error once GitHub is configured but before the first import, leaving the local data alone', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Local task');
        configureGitHub();

        expect(fn () => app(CreateTodoIssue::class)->handle(title: 'Too early'))
            ->toThrow(TodoValidationException::class, 'GitHub mirroring is configured for example-owner/example-tasks, but that repository has not been imported yet.');
        expect(Issue::query()->pluck('id')->all())->toBe([$issue->id])
            ->and(GitHubRepository::query()->sole()->is_local)->toBeTrue()
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
    });

    it('leaves the instance local-only when the first import fails', function (): void {
        $issue = app(CreateTodoIssue::class)->handle(title: 'Local task', newLabelNames: ['bug']);
        configureGitHub();

        expect(fn () => app(ApplyGitHubSnapshot::class)->handle(remoteRepositorySnapshot(labels: [['id' => '', 'name' => 'bug', 'color' => 'd73a4a', 'description' => null]])))
            ->toThrow(GitHubSyncException::class, 'GitHub returned a label without an id');

        $repository = GitHubRepository::query()->sole();
        expect($repository->is_local)->toBeTrue()
            ->and($repository->github_node_id)->toBe(GitHubRepository::LOCAL_IDENTITY)
            ->and($issue->refresh()->is_available)->toBeTrue()
            ->and(Label::query()->sole()->github_node_id)->toBeNull()
            ->and(GitHubPushQueueItem::query()->count())->toBe(0);
    });
});

describe('the public demo', function (): void {
    it('seeds its data into the local repository, so a visitor can create tasks with the demo labels', function (): void {
        $this->seed(DemoSeeder::class);
        $queuedBySeeder = GitHubPushQueueItem::query()->count();

        $issue = app(CreateTodoIssue::class)->handle(title: 'Visitor task', labelIds: [Label::query()->where('name', 'bug')->sole()->id], newLabelNames: ['visitor']);

        expect(GitHubRepository::query()->sole()->is_local)->toBeTrue()
            ->and($issue->labels->pluck('name')->sort()->values()->all())->toBe(['bug', 'visitor'])
            ->and(GitHubPushQueueItem::query()->count())->toBe($queuedBySeeder);
    });
});
