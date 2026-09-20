<?php

declare(strict_types=1);

use App\Actions\SetIssueParent;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sets a native GitHub parent and updates the local hierarchy after confirmation', function (): void {
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($parent->repository, 'repository')->create(['github_node_id' => 'I_child']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['addSubIssue' => ['subIssue' => ['id' => 'I_child', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $updated = app(SetIssueParent::class)->handle('test-token', $child, $parent);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $variables = (array) $data['variables'];

        return str_contains((string) $data['query'], 'addSubIssue')
            && $variables['parentId'] === 'I_parent'
            && $variables['childId'] === 'I_child'
            && $variables['replaceParent'] === true;
    });
    expect($updated->parent_issue_id)->toBe($parent->id)
        ->and($updated->github_parent_node_id)->toBe('I_parent');
});

it('rejects self-parenting and locally detectable cycles before contacting GitHub', function (): void {
    Http::fake();
    $root = Issue::factory()->create();
    $child = Issue::factory()->for($root, 'parent')->for($root->repository, 'repository')->create();

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $root, $root))
        ->toThrow(GitHubSyncException::class, 'own parent');
    expect(fn () => app(SetIssueParent::class)->handle('test-token', $root, $child))
        ->toThrow(GitHubSyncException::class, 'cycle');
    Http::assertNothingSent();
});

it('rejects a parent from a different repository before contacting GitHub', function (): void {
    Http::fake();
    $child = Issue::factory()->create(['github_node_id' => 'I_child']);
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, $parent))
        ->toThrow(GitHubSyncException::class, 'same repository');
    Http::assertNothingSent();
});

it('is a no-op when the requested parent is already the current one', function (): void {
    Http::fake();
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create(['github_node_id' => 'I_child']);

    $result = app(SetIssueParent::class)->handle('test-token', $child, $parent);

    expect($result->is($child))->toBeTrue();
    Http::assertNothingSent();
});

it('rejects an unavailable child or parent before contacting GitHub', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create();
    $unavailableChild = Issue::factory()->for($repository, 'repository')->create(['is_available' => false]);
    $parent = Issue::factory()->for($repository, 'repository')->create();
    $child = Issue::factory()->for($repository, 'repository')->create();
    $unavailableParent = Issue::factory()->for($repository, 'repository')->create(['is_available' => false]);

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $unavailableChild, $parent))
        ->toThrow(GitHubSyncException::class, 'not available in the local snapshot');
    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, $unavailableParent))
        ->toThrow(GitHubSyncException::class, 'not available in the local snapshot');
    Http::assertNothingSent();
});

it('rejects when github does not confirm the parent assignment', function (): void {
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($parent->repository, 'repository')->create(['github_node_id' => 'I_child']);
    Http::fake(fn () => Http::response(['data' => ['addSubIssue' => ['subIssue' => null]]], 200));

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, $parent))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});

it('detects a cycle among ancestors that does not directly involve the child', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create();
    $a = Issue::factory()->for($repository, 'repository')->create();
    $b = Issue::factory()->for($repository, 'repository')->for($a, 'parent')->create();
    $a->update(['parent_issue_id' => $b->id]);
    $child = Issue::factory()->for($repository, 'repository')->create();

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, $a))
        ->toThrow(GitHubSyncException::class, 'already contains a cycle');
    Http::assertNothingSent();
});

it('rejects setting a parent when an ancestor further up is not available locally', function (): void {
    Http::fake();
    $repository = GitHubRepository::factory()->create();
    $ghost = Issue::factory()->for($repository, 'repository')->create();
    $parent = Issue::factory()->for($repository, 'repository')->create(['parent_issue_id' => $ghost->id]);
    $ghost->delete();
    $child = Issue::factory()->for($repository, 'repository')->create();

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, $parent))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('is a no-op when removing a parent that is already absent both locally and remotely', function (): void {
    Http::fake();
    $child = Issue::factory()->create(['parent_issue_id' => null, 'github_parent_node_id' => null]);

    $result = app(SetIssueParent::class)->handle('test-token', $child, null);

    expect($result->is($child))->toBeTrue();
    Http::assertNothingSent();
});

it('rejects removing a parent that is confirmed remotely but missing locally', function (): void {
    Http::fake();
    $child = Issue::factory()->create(['parent_issue_id' => null, 'github_parent_node_id' => 'I_ghost_parent']);

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, null))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('rejects removing a parent whose local record is no longer available', function (): void {
    Http::fake();
    $ghost = Issue::factory()->create();
    $child = Issue::factory()->create(['parent_issue_id' => $ghost->id]);
    $ghost->delete();

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, null))
        ->toThrow(GitHubSyncException::class, 'not available locally');
    Http::assertNothingSent();
});

it('rejects when github does not confirm removal of the parent', function (): void {
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create(['github_node_id' => 'I_child', 'github_parent_node_id' => 'I_parent']);
    Http::fake(fn () => Http::response(['data' => ['removeSubIssue' => ['subIssue' => null]]], 200));

    expect(fn () => app(SetIssueParent::class)->handle('test-token', $child, null))
        ->toThrow(GitHubSyncException::class, 'did not confirm');
});

it('removes a current native parent and clears the confirmed local link', function (): void {
    $parent = Issue::factory()->create(['github_node_id' => 'I_parent']);
    $child = Issue::factory()->for($parent, 'parent')->for($parent->repository, 'repository')->create(['github_node_id' => 'I_child', 'github_parent_node_id' => 'I_parent']);
    Http::fake(fn (Request $request) => Http::response(['data' => ['removeSubIssue' => ['subIssue' => ['id' => 'I_child', 'updatedAt' => '2026-09-09T20:00:00Z']]]], 200));

    $updated = app(SetIssueParent::class)->handle('test-token', $child, null);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $variables = (array) $data['variables'];

        return str_contains((string) $data['query'], 'removeSubIssue')
            && $variables['parentId'] === 'I_parent'
            && $variables['childId'] === 'I_child';
    });
    expect($updated->parent_issue_id)->toBeNull()->and($updated->github_parent_node_id)->toBeNull();
});
