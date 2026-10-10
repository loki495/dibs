<?php

declare(strict_types=1);

use App\Actions\ExportWorkspaceArchive;
use App\Actions\StageWorkspaceArchive;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    Storage::fake('local');
});

it('requires sign in for the page, export, preview, import and cancellation', function (): void {
    $this->get(route('data-transfer'))->assertRedirect(route('login'));
    $this->get(route('data-transfer.export'))->assertRedirect(route('login'));
    foreach (['preview', 'import', 'cancel'] as $action) {
        $this->post(route('data-transfer.'.$action))->assertRedirect(route('login'));
    }
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('downloads a JSON attachment without changing workspace data', function (): void {
    Issue::factory()->create();
    $before = app(ExportWorkspaceArchive::class)->handle()['data'];
    $this->actingAs(User::factory()->create())->get(route('data-transfer'))->assertOk()->assertSee('Data export/import');
    $response = $this->get(route('data-transfer.export'))->assertOk()->assertHeader('Content-Type', 'application/json');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment;')
        ->and(json_decode($response->streamedContent(), true, 64, JSON_THROW_ON_ERROR)['data'])->toBe($before)
        ->and(app(ExportWorkspaceArchive::class)->handle()['data'])->toBe($before);
});

it('previews without importing, then restores only after explicit confirmation', function (): void {
    $issue = Issue::factory()->create();
    $json = json_encode(app(ExportWorkspaceArchive::class)->handle(), JSON_THROW_ON_ERROR);
    DB::table('issues')->delete();
    DB::table('repositories')->delete();
    $this->actingAs(User::factory()->create());
    $this->from(route('data-transfer'))->post(route('data-transfer.preview'), ['archive' => UploadedFile::fake()->createWithContent('backup.json', $json)])
        ->assertRedirect(route('data-transfer'))->assertSessionHas('workspace-transfer');
    expect(Issue::query()->count())->toBe(0);
    $this->get(route('data-transfer'))->assertOk()->assertSee('Import preview');
    $this->from(route('data-transfer'))->post(route('data-transfer.import'))->assertSessionHasErrors('confirm');
    expect(Issue::query()->count())->toBe(0);
    $this->post(route('data-transfer.import'), ['confirm' => '1'])->assertRedirect(route('data-transfer'))->assertSessionMissing('workspace-transfer');
    expect(Issue::findOrFail($issue->id)->title)->toBe($issue->title)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Http::assertNothingSent();
});

it('rejects invalid uploads gracefully and clears an earlier preview', function (): void {
    $this->actingAs(User::factory()->create());
    $json = json_encode(app(ExportWorkspaceArchive::class)->handle(), JSON_THROW_ON_ERROR);
    $this->post(route('data-transfer.preview'), ['archive' => UploadedFile::fake()->createWithContent('valid.json', $json)])->assertSessionHas('workspace-transfer');
    $this->from(route('data-transfer'))->post(route('data-transfer.preview'), ['archive' => UploadedFile::fake()->createWithContent('bad.json', '{')])
        ->assertRedirect(route('data-transfer'))->assertSessionHasErrors('archive')->assertSessionMissing('workspace-transfer');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not import when the destination became nonempty after preview', function (): void {
    $this->actingAs(User::factory()->create());
    $json = json_encode(app(ExportWorkspaceArchive::class)->handle(), JSON_THROW_ON_ERROR);
    $this->post(route('data-transfer.preview'), ['archive' => UploadedFile::fake()->createWithContent('valid.json', $json)]);
    $issue = Issue::factory()->create(['title' => 'Concurrent task']);
    $before = app(ExportWorkspaceArchive::class)->handle()['data'];
    $this->from(route('data-transfer'))->post(route('data-transfer.import'), ['confirm' => '1'])->assertSessionHasErrors('archive');
    expect(app(ExportWorkspaceArchive::class)->handle()['data'])->toBe($before)
        ->and($issue->fresh()->title)->toBe('Concurrent task');
});

it('rejects expired and missing previews without database writes', function (string $case): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    if ($case === 'expired') {
        $preview = app(StageWorkspaceArchive::class)->handle(json_encode(app(ExportWorkspaceArchive::class)->handle(), JSON_THROW_ON_ERROR), $user->id);
        $preview['expires'] = now()->subMinute()->timestamp;
        $this->withSession(['workspace-transfer' => $preview]);
    }
    $this->from(route('data-transfer'))->post(route('data-transfer.import'), ['confirm' => '1'])->assertSessionHasErrors('archive');
    expect(Issue::query()->count())->toBe(0);
})->with(['expired', 'missing']);

it('discards the encrypted preview without touching workspace data', function (): void {
    $this->actingAs(User::factory()->create());
    $json = json_encode(app(ExportWorkspaceArchive::class)->handle(), JSON_THROW_ON_ERROR);
    $this->post(route('data-transfer.preview'), ['archive' => UploadedFile::fake()->createWithContent('valid.json', $json)]);
    $this->post(route('data-transfer.cancel'))->assertRedirect(route('data-transfer'))->assertSessionMissing('workspace-transfer');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects previews belonging to another account or modified after validation', function (string $case): void {
    $user = User::factory()->create();
    $json = json_encode(app(ExportWorkspaceArchive::class)->handle(), JSON_THROW_ON_ERROR);
    $preview = app(StageWorkspaceArchive::class)->handle($json, $user->id);
    if ($case === 'other user') {
        $preview['user'] = $user->id + 1;
    } else {
        Storage::disk('local')->put($preview['path'], 'not the encrypted preview');
    }
    $this->actingAs($user)->withSession(['workspace-transfer' => $preview])->from(route('data-transfer'))
        ->post(route('data-transfer.import'), ['confirm' => '1'])->assertSessionHasErrors('archive');
    expect(Issue::query()->count())->toBe(0);
})->with(['other user', 'modified file']);

it('rejects oversized uploads with a handled validation error', function (): void {
    config(['workspace-transfer.max_file_kib' => 1]);
    $this->actingAs(User::factory()->create())->from(route('data-transfer'))
        ->post(route('data-transfer.preview'), ['archive' => UploadedFile::fake()->create('large.json', 2)])
        ->assertRedirect(route('data-transfer'))->assertSessionHasErrors('archive');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
