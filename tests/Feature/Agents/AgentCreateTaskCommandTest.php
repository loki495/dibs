<?php

declare(strict_types=1);

use App\Actions\CreateTodoIssue;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    GitHubRepository::factory()->create(['owner' => config('github.owner'), 'name' => config('github.repository'), 'full_name' => config('github.owner').'/'.config('github.repository')]);
});

it('creates a task locally and enqueues the GitHub push', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Ship the thing', '--body' => 'Some detail'])
        ->expectsOutputToContain('Ship the thing')
        ->assertSuccessful();

    $issue = Issue::query()->where('title', 'Ship the thing')->sole();
    expect($issue->body)->toBe('Some detail')
        ->and(GitHubPushQueueItem::query()->where('operation', 'create_issue')->where('target_id', $issue->id)->exists())->toBeTrue();
});

it('creates a task with a new label by name', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Tagged task', '--new-label' => 'urgent'])->assertSuccessful();

    $issue = Issue::query()->where('title', 'Tagged task')->sole();
    expect($issue->labels->pluck('name')->all())->toBe(['urgent']);
});

it('is idempotent: retrying the same key does not duplicate the task', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Once only', '--idempotency-key' => 'agent-1'])->assertSuccessful();
    $this->artisan('todo:agent:create', ['title' => 'Once only', '--idempotency-key' => 'agent-1'])->assertSuccessful();

    expect(Issue::query()->where('title', 'Once only')->count())->toBe(1);
});

it('returns a structured error for an unavailable area instead of crashing', function (): void {
    $this->artisan('todo:agent:create', ['title' => 'Task', '--area' => '999999'])->assertFailed();

    expect(Issue::query()->count())->toBe(0);
});

it('rejects a call missing the required title', function (): void {
    expect(fn () => $this->artisan('todo:agent:create', []))->toThrow(RuntimeException::class, 'title');
});

it('inherits parent project and Group through the CLI with no project flags', function (): void {
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => 'group']);
    $group = ProjectFieldOption::factory()->for($field, 'field')->create();
    $parent = app(CreateTodoIssue::class)->handle(title: 'CLI parent', area: $project->id, groupId: $group->id);

    $exitCode = Artisan::call('todo:agent:create', ['title' => 'CLI inherited child', '--parent' => $parent->id]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)->and($result['area']['id'])->toBe($project->id)
        ->and($result['group'])->toBe($group->name)->and($result['parentId'])->toBe($parent->id)
        ->and(Issue::query()->where('title', 'CLI inherited child')->sole()->projectItems->sole()->group_option_id)->toBe($group->id);
});

describe('batch JSON files', function (): void {
    beforeEach(function (): void {
        $this->batchFile = tempnam(sys_get_temp_dir(), 'dibs-batch-');
    });
    afterEach(function (): void {
        if (is_file($this->batchFile)) {
            unlink($this->batchFile);
        }
    });

    it('prints successful batch JSON and retries keyed items without duplicates', function (): void {
        file_put_contents($this->batchFile, json_encode([['title' => 'File A', 'idempotencyKey' => 'file-a'], ['title' => 'File B', 'idempotencyKey' => 'file-b']], JSON_THROW_ON_ERROR));
        foreach (range(1, 2) as $attempt) {
            expect(Artisan::call('todo:agent:create', ['--tasks-file' => $this->batchFile]))->toBe(0);
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['succeeded'])->toBe(2)->and($result['failed'])->toBe(0);
        }
        expect(Issue::query()->count())->toBe(2);
    });

    it('returns failure with parseable per-item results while keeping successful tasks', function (): void {
        file_put_contents($this->batchFile, json_encode([['title' => 'Good'], ['title' => 'Bad', 'area' => 999_999]], JSON_THROW_ON_ERROR));
        expect(Artisan::call('todo:agent:create', ['--tasks-file' => $this->batchFile]))->toBe(1);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['succeeded'])->toBe(1)->and($result['failed'])->toBe(1)
            ->and($result['items'][1]['error']['code'])->toBe('validation_error')->and(Issue::query()->count())->toBe(1);
    });

    it('rejects a malformed file and mixed single arguments without writing', function (): void {
        file_put_contents($this->batchFile, '{invalid');
        $this->artisan('todo:agent:create', ['--tasks-file' => $this->batchFile])->expectsOutputToContain('valid JSON')->assertFailed();
        $this->artisan('todo:agent:create', ['title' => 'Single', '--tasks-file' => $this->batchFile])->expectsOutputToContain('either')->assertFailed();
        $this->artisan('todo:agent:create', ['--area' => 1, '--tasks-file' => $this->batchFile])->expectsOutputToContain('either')->assertFailed();
        expect(Issue::query()->count())->toBe(0);
    });
});
