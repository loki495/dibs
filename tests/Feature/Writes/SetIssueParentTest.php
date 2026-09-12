<?php

declare(strict_types=1);

use App\Actions\SetIssueParent;
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
