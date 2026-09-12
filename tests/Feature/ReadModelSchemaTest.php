<?php

declare(strict_types=1);

use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\ProjectItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('the GitHub read model schema', function (): void {
    it('enforces stable GitHub identities', function (): void {
        GitHubRepository::factory()->create(['github_node_id' => 'R_kgDOunique']);

        expect(fn () => GitHubRepository::factory()->create(['github_node_id' => 'R_kgDOunique']))
            ->toThrow(QueryException::class);
    });

    it('keeps issue trees and unresolved remote parents representable', function (): void {
        $parent = Issue::factory()->create();
        $child = Issue::factory()->for($parent, 'parent')->create(['sibling_position' => 3]);
        $unresolved = Issue::factory()->for($parent->repository, 'repository')->create([
            'parent_issue_id' => null,
            'github_parent_node_id' => 'I_kgDOparent-arrives-later',
            'sibling_position' => 4,
        ]);

        expect($child->parent->is($parent))->toBeTrue()
            ->and($parent->children->sole()->is($child))->toBeTrue()
            ->and($unresolved->parent)->toBeNull()
            ->and($unresolved->github_parent_node_id)->toBe('I_kgDOparent-arrives-later')
            ->and($unresolved->sibling_position)->toBe(4);
    });

    it('allows matching issue numbers in different repositories', function (): void {
        $firstRepository = GitHubRepository::factory()->create();
        $secondRepository = GitHubRepository::factory()->create();

        Issue::factory()->for($firstRepository, 'repository')->create(['github_number' => 42]);
        Issue::factory()->for($secondRepository, 'repository')->create(['github_number' => 42]);

        expect(Issue::query()->where('github_number', 42)->count())->toBe(2);
    });

    it('preserves multiple project memberships but rejects duplicate issue-backed membership', function (): void {
        $issue = Issue::factory()->create();
        $firstProject = GitHubProject::factory()->create();
        $secondProject = GitHubProject::factory()->create();

        ProjectItem::factory()->for($firstProject, 'project')->for($issue, 'issue')->create();
        ProjectItem::factory()->for($secondProject, 'project')->for($issue, 'issue')->create();

        expect($issue->projectItems()->count())->toBe(2)
            ->and(fn () => ProjectItem::factory()->for($firstProject, 'project')->for($issue, 'issue')->create())
            ->toThrow(QueryException::class);
    });

    it('rejects orphan foreign keys', function (): void {
        expect(fn () => DB::table('issues')->insert([
            'repository_id' => 999_999,
            'github_node_id' => 'I_kgDOorphan',
            'github_number' => 1,
            'title' => 'Orphan issue',
            'state' => 'OPEN',
            'sibling_position' => 0,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
