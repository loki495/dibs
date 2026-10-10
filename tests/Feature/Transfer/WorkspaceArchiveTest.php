<?php

declare(strict_types=1);

use App\Actions\ExportWorkspaceArchive;
use App\Actions\RestoreWorkspaceArchive;
use App\Actions\ValidateWorkspaceArchive;
use App\Models\CaptureSetting;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\Issue;
use App\Models\Label;
use App\Models\McpCallLog;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\TodoCaptureRequest;
use App\Models\User;
use App\Support\WorkspaceArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

it('exports archived content and relationships without accounts or capability tokens', function (): void {
    $parent = Issue::factory()->create(['is_available' => false]);
    $child = Issue::factory()->for($parent->repository, 'repository')->create(['parent_issue_id' => $parent->id]);
    $label = Label::factory()->for($parent->repository, 'repository')->create();
    $child->labels()->attach($label);
    Comment::factory()->for($child, 'issue')->create(['body' => 'Saved note']);
    User::factory()->create();
    $archive = app(ExportWorkspaceArchive::class)->handle();

    expect($archive['format'])->toBe('dibs-workspace')
        ->and($archive['version'])->toBe(1)
        ->and($archive['data']['issues'])->toHaveCount(2)
        ->and($archive['data']['issue_label'])->toHaveCount(1)
        ->and($archive['data']['comments'][0]['body'])->toBe('Saved note')
        ->and($archive['data'])->not->toHaveKeys(['users', 'task_claims', 'agent_sessions', 'mcp_write_receipts']);
    Http::assertNothingSent();
});

it('round trips content including a parent whose id follows its child without pushes', function (): void {
    $child = Issue::factory()->create();
    $parent = Issue::factory()->for($child->repository, 'repository')->create();
    $child->update(['parent_issue_id' => $parent->id]);
    $archive = app(ExportWorkspaceArchive::class)->handle();
    DB::table('issues')->delete();
    DB::table('repositories')->delete();
    $user = User::factory()->create();

    app(RestoreWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR), $user->id);

    expect(Issue::findOrFail($child->id)->parent_issue_id)->toBe($parent->id)
        ->and(app(ExportWorkspaceArchive::class)->handle()['data'])->toBe($archive['data'])
        ->and(GitHubPushQueueItem::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses a nonempty destination without changing any existing data', function (): void {
    $archive = app(ExportWorkspaceArchive::class)->handle();
    $issue = Issue::factory()->create(['title' => 'Keep me']);
    $before = app(ExportWorkspaceArchive::class)->handle()['data'];
    $user = User::factory()->create();
    expect(fn () => app(RestoreWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR), $user->id))
        ->toThrow(ValidationException::class);
    expect(app(ExportWorkspaceArchive::class)->handle()['data'])->toBe($before)
        ->and($issue->fresh()->title)->toBe('Keep me');
});

it('rejects malformed JSON and incompatible format versions', function (string $input): void {
    expect(fn () => app(ValidateWorkspaceArchive::class)->handle($input))->toThrow(ValidationException::class);
    expect(Issue::query()->count())->toBe(0);
})->with(['broken' => '{', 'not object' => '[]', 'future' => '{"format":"dibs-workspace","version":999,"data":{}}']);

it('rejects missing tables, unexpected columns, duplicate ids and dangling parents', function (string $mutation): void {
    Issue::factory()->create();
    $archive = app(ExportWorkspaceArchive::class)->handle();
    match ($mutation) {
        'table' => $archive['data']['users'] = [],
        'column' => $archive['data']['issues'][0]['surprise'] = 'x',
        'duplicate' => $archive['data']['issues'][] = $archive['data']['issues'][0],
        'parent' => $archive['data']['issues'][0]['parent_issue_id'] = 999999,
        'missing' => $archive['data']['comments'] = null,
    };
    expect(fn () => app(ValidateWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR)))
        ->toThrow(ValidationException::class);
})->with(['table', 'column', 'duplicate', 'parent', 'missing']);

it('rejects cycles in the parent tree', function (): void {
    $first = Issue::factory()->create();
    $second = Issue::factory()->for($first->repository, 'repository')->create();
    $archive = app(ExportWorkspaceArchive::class)->handle();
    $archive['data']['issues'][0]['parent_issue_id'] = $second->id;
    $archive['data']['issues'][1]['parent_issue_id'] = $first->id;
    expect(fn () => app(ValidateWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR)))
        ->toThrow(ValidationException::class);
});

it('rolls back all inserted content if a later insert fails', function (): void {
    $issue = Issue::factory()->create();
    Comment::factory()->for($issue, 'issue')->create();
    $archive = app(ExportWorkspaceArchive::class)->handle();
    DB::table('comments')->delete();
    DB::table('issues')->delete();
    DB::table('repositories')->delete();
    DB::statement("CREATE TRIGGER reject_import_comment BEFORE INSERT ON comments BEGIN SELECT RAISE(ABORT, 'isolated failure'); END");
    $user = User::factory()->create();
    expect(fn () => app(RestoreWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR), $user->id))
        ->toThrow(ValidationException::class);
    expect(Issue::query()->count())->toBe(0)
        ->and(DB::table('repositories')->count())->toBe(0)
        ->and(DB::table('comments')->count())->toBe(0);
});

it('rejects invalid states and timestamps', function (string $column): void {
    Issue::factory()->create();
    $archive = app(ExportWorkspaceArchive::class)->handle();
    $archive['data']['issues'][0][$column] = 'not valid';
    expect(fn () => app(ValidateWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR)))
        ->toThrow(ValidationException::class);
})->with(['state', 'created_at']);

it('preserves project options, schedules, labels, closing notes and history on restore', function (): void {
    $issue = Issue::factory()->create(['state' => 'CLOSED', 'state_reason' => 'COMPLETED', 'closed_at' => now()]);
    $project = GitHubProject::factory()->create();
    $options = [];
    foreach (['group', 'status', 'priority'] as $semantic) {
        $field = ProjectField::factory()->for($project, 'project')->create(['semantic_key' => $semantic]);
        $options[$semantic] = ProjectFieldOption::factory()->for($field, 'field')->create();
    }
    ProjectItem::factory()->for($issue, 'issue')->for($project, 'project')->create([
        'group_option_id' => $options['group']->id, 'status_option_id' => $options['status']->id,
        'priority_option_id' => $options['priority']->id, 'planned_on' => '2026-10-12', 'due_on' => '2026-10-20', 'repeat_rule' => 'weekly',
    ]);
    $issue->labels()->attach(Label::factory()->for($issue->repository, 'repository')->create());
    Comment::factory()->closing()->for($issue, 'issue')->create(['references' => ['commit:example']]);
    McpCallLog::query()->create(['tool' => 'todo_show', 'status' => 'ok', 'arguments' => ['id' => $issue->id], 'created_at' => now()]);
    $archive = app(ExportWorkspaceArchive::class)->handle();
    foreach (array_reverse(WorkspaceArchive::TABLES) as $table) {
        DB::table($table)->delete();
    }
    $user = User::factory()->create();
    app(RestoreWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR), $user->id);
    expect(app(ExportWorkspaceArchive::class)->handle()['data'])->toBe($archive['data'])
        ->and(Issue::findOrFail($issue->id)->comments->sole()->references)->toBe(['commit:example']);
    Http::assertNothingSent();
});

it('rejects an option belonging to another field or project', function (): void {
    $issue = Issue::factory()->create();
    $project = GitHubProject::factory()->create();
    $field = ProjectField::factory()->create(['semantic_key' => 'priority']);
    $option = ProjectFieldOption::factory()->for($field, 'field')->create();
    ProjectItem::factory()->for($issue, 'issue')->for($project, 'project')->create(['group_option_id' => $option->id]);
    $archive = app(ExportWorkspaceArchive::class)->handle();
    expect(fn () => app(ValidateWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR)))
        ->toThrow(ValidationException::class);
});

it('preserves capture drafts while assigning them to the destination account', function (): void {
    $owner = User::factory()->create();
    CaptureSetting::query()->create(['enabled' => false, 'enabled_agents' => ['codex']]);
    $draft = TodoCaptureRequest::factory()->for($owner, 'user')->create(['raw_text' => 'Sanitized draft', 'draft' => ['title' => 'A draft']]);
    $archive = app(ExportWorkspaceArchive::class)->handle();
    DB::table('todo_capture_requests')->delete();
    DB::table('capture_settings')->delete();
    $destination = User::factory()->create();
    app(RestoreWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR), $destination->id);
    $restored = $draft->fresh();
    expect($restored->user_id)->toBe($destination->id)
        ->and($restored->raw_text)->toBe('Sanitized draft')
        ->and($restored->draft)->toBe(['title' => 'A draft']);
});

it('rejects scalar structured fields instead of creating records that crash when read', function (): void {
    $issue = Issue::factory()->create();
    Comment::factory()->closing()->for($issue, 'issue')->create();
    $archive = app(ExportWorkspaceArchive::class)->handle();
    $archive['data']['comments'][0]['references'] = '42';
    expect(fn () => app(ValidateWorkspaceArchive::class)->handle(json_encode($archive, JSON_THROW_ON_ERROR)))
        ->toThrow(ValidationException::class);
});
