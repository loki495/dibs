<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentSession;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubPushQueueItem;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectField;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Models\TaskClaim;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Populates a fresh database with realistic-looking, entirely fake sample data -- no real
 * personal task content -- for screenshots and the public demo instance. Never run this
 * against a real personal database; it's meant for a fresh `migrate:fresh` target only.
 */
class DemoSeeder extends Seeder
{
    private GitHubRepository $repository;

    /** @var array<string, Label> */
    private array $labels = [];

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => bcrypt('demo-password-please-change'),
        ]);

        $this->repository = GitHubRepository::factory()->create([
            'owner' => 'demo-user', 'name' => 'dibs-demo', 'full_name' => 'demo-user/dibs-demo', 'is_private' => true,
        ]);

        foreach (['bug', 'feature', 'documentation', 'research', 'decision', 'lesson', 'guide', 'plan', 'today', 'next', 'waiting', 'someday', 'needs research', 'parent'] as $name) {
            $this->labels[$name] = Label::factory()->for($this->repository, 'repository')->create(['name' => $name]);
        }

        $this->seedWork();
        $this->seedPersonalProjects();
        $this->seedLearning();
        $this->seedRandomTasks();
    }

    private function seedWork(): void
    {
        [$project, $groups, $priorities] = $this->makeArea('Work', '0f6cbd', ['Client A', 'Internal tools']);

        $parent = $this->issue('Redesign the client onboarding flow', $project, $groups['Client A'], $priorities[2], labels: ['feature']);
        $this->issue('Write the new welcome email copy', $project, $groups['Client A'], $priorities[3], parent: $parent, labels: ['today']);
        $this->issue('Wire up the progress checklist component', $project, $groups['Client A'], $priorities[2], parent: $parent);
        $this->issue('Get sign-off from the client on the new flow', $project, $groups['Client A'], $priorities[1], parent: $parent, labels: ['waiting']);

        $this->issue('Fix intermittent 500 on the invoice export endpoint', $project, $groups['Internal tools'], $priorities[0], labels: ['bug', 'today']);
        $this->issue('Upgrade the internal dashboard to the new charting library', $project, $groups['Internal tools'], $priorities[4], labels: ['someday']);
        $this->closedIssue('Migrate staging to the new deploy pipeline', $project, $groups['Internal tools']);
    }

    private function seedPersonalProjects(): void
    {
        [$project, $groups, $priorities] = $this->makeArea('Personal Projects', '10b981', ['Dibs', 'Homelab', 'Blog']);

        $parent = $this->issue('Ship the mobile layout pass', $project, $groups['Dibs'], $priorities[1], labels: ['feature']);
        $child = $this->issue('Fix the bulk-select button crowding the header on small screens', $project, $groups['Dibs'], $priorities[1], parent: $parent, labels: ['bug']);
        $this->comment($child, 'Moved it next to the sort dropdown instead — screenshots look right on a 390px viewport now.');
        $this->issue('Add a quick "back to all projects" link when a single area is sorted by group', $project, $groups['Dibs'], $priorities[2], parent: $parent);

        // A plan-shaped hierarchy with one claimed and two unclaimed children -- what makes Dibs
        // different from a plain todo list isn't visible from the task list alone, so the demo
        // needs at least one task showing live agent coordination, not just task/knowledge content.
        $plan = $this->issue('Plan: Add notification digests', $project, $groups['Dibs'], $priorities[2], labels: ['plan']);
        $this->issue('Design the notification data model', $project, $groups['Dibs'], $priorities[2], parent: $plan, labels: ['next']);
        $claimed = $this->issue('Build the in-app notification bell', $project, $groups['Dibs'], $priorities[1], parent: $plan, labels: ['feature']);
        $this->issue('Add an email digest opt-in setting', $project, $groups['Dibs'], $priorities[3], parent: $plan);
        $this->claim($claimed, 'claude-code');

        $this->pushQueueShowcase($project, $groups['Dibs'], $priorities[2]);

        $this->issue('Move the reverse proxy to the new host', $project, $groups['Homelab'], $priorities[3]);
        $this->issue('Automate the nightly backup verification', $project, $groups['Homelab'], $priorities[2], labels: ['someday']);
        $this->issue('Write up the home network segmentation decision', $project, $groups['Homelab'], $priorities[3], labels: ['decision']);

        $this->issue('Draft the "why local-first" post', $project, $groups['Blog'], $priorities[4], labels: ['someday']);
        $this->closedIssue('Set up the blog\'s RSS feed', $project, $groups['Blog']);
    }

    private function seedLearning(): void
    {
        [$project, $groups, $priorities] = $this->makeArea('Learning & Self-Improvement', '6366f1', ['Courses', 'Reading']);

        $this->issue('Finish the advanced SQLite internals course', $project, $groups['Courses'], $priorities[2], labels: ['next']);
        $this->issue('Practice WAL-mode failure scenarios in a scratch project', $project, $groups['Courses'], $priorities[3], labels: ['needs research']);
        $this->issue('Read "Designing Data-Intensive Applications" ch. 7-9', $project, $groups['Reading'], $priorities[3]);
        $this->researchNote(
            'Research: SQLite WAL checkpoint behavior under concurrent writers',
            $project,
            "Findings from testing concurrent writers against a WAL-mode database:\n\n"
            ."- A busy_timeout covers waiting for another writer's lock, not every class of `database is locked` error.\n"
            ."- Checkpointing can stall behind a long-lived read transaction; watch for readers that never commit.\n"
            ."- `PRAGMA wal_checkpoint(TRUNCATE)` before a backup guarantees the main file has everything.\n\n"
            .'Verified against a real two-connection scenario, not just documentation.',
        );
        $this->decisionNote(
            'Decision: keep the reading list in Dibs instead of a separate app',
            $project,
            "Considered a dedicated read-it-later app; decided against it.\n\n"
            .'Splitting "things to read" from "things to do" just recreates the two-list problem Dibs '
            .'itself exists to avoid. A `someday`-labeled task under a topic Group does the same job '
            .'without a second tool to check.',
        );
    }

    private function seedRandomTasks(): void
    {
        [$project, $groups, $priorities] = $this->makeArea('Random Tasks', 'f59e0b', ['Home', 'Errands']);

        $this->issue('Replace the smoke detector batteries', $project, $groups['Home'], $priorities[2], labels: ['today']);
        $this->issue('Book the car in for its service', $project, $groups['Errands'], $priorities[3]);
        $this->issue('Return the mispackaged order', $project, $groups['Errands'], $priorities[1], labels: ['waiting']);
        $this->closedIssue('Renew the passport', $project, $groups['Home']);
    }

    /**
     * @param  list<string>  $groupNames
     * @return array{0: GitHubProject, 1: array<string, ProjectFieldOption>, 2: array<int, ProjectFieldOption>}
     */
    private function makeArea(string $title, string $color, array $groupNames): array
    {
        $project = GitHubProject::factory()->create(['title' => $title, 'color' => $color, 'owner' => 'demo-user']);

        $groupField = ProjectField::factory()->for($project, 'project')->create(['name' => 'Group', 'semantic_key' => 'group']);
        $groups = [];
        foreach ($groupNames as $position => $name) {
            $groups[$name] = ProjectFieldOption::factory()->for($groupField, 'field')->create(['name' => $name, 'position' => $position]);
        }

        $priorityField = ProjectField::factory()->for($project, 'project')->create(['name' => 'Priority', 'semantic_key' => 'priority']);
        $priorities = [];
        foreach (range(1, 5) as $index => $value) {
            $priorities[$index] = ProjectFieldOption::factory()->for($priorityField, 'field')->create(['name' => (string) $value, 'position' => $index]);
        }

        return [$project, $groups, $priorities];
    }

    /** @param list<string> $labels */
    private function issue(string $title, GitHubProject $project, ProjectFieldOption $group, ProjectFieldOption $priority, ?Issue $parent = null, array $labels = []): Issue
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create([
            'title' => $title,
            'body' => null,
            'parent_issue_id' => $parent?->id,
        ]);

        ProjectItem::factory()->for($project, 'project')->create([
            'issue_id' => $issue->id, 'group_option_id' => $group->id, 'priority_option_id' => $priority->id,
        ]);

        if ($labels !== []) {
            $issue->labels()->attach(array_map(fn (string $name): int => $this->labels[$name]->id, $labels));
        }

        return $issue;
    }

    private function closedIssue(string $title, GitHubProject $project, ProjectFieldOption $group): Issue
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create(['title' => $title, 'body' => null, 'state' => 'CLOSED']);
        ProjectItem::factory()->for($project, 'project')->create(['issue_id' => $issue->id, 'group_option_id' => $group->id]);

        return $issue;
    }

    private function comment(Issue $issue, string $body): void
    {
        Comment::factory()->for($issue, 'issue')->create(['body' => $body, 'author_login' => 'demo-user']);
    }

    private function researchNote(string $title, GitHubProject $project, string $body): void
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create(['title' => $title, 'body' => $body]);
        ProjectItem::factory()->for($project, 'project')->create(['issue_id' => $issue->id]);
        $issue->labels()->attach($this->labels['research']->id);
    }

    private function decisionNote(string $title, GitHubProject $project, string $body): void
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create(['title' => $title, 'body' => $body]);
        ProjectItem::factory()->for($project, 'project')->create(['issue_id' => $issue->id]);
        $issue->labels()->attach($this->labels['decision']->id);
    }

    /**
     * Binds a claim to a fabricated, deliberately unverifiable process (is_verified_live: false)
     * rather than a real PID -- a real one would only look alive by accident (matching this
     * container's own init process) and would go stale on the very next deploy. Unverified
     * liveness is itself a real, documented state the app handles (see AuthorizeAgentClaim's own
     * docs), not a demo shortcut: it renders as "claimed" with "(liveness unverifiable)", not as
     * a dead/abandoned claim.
     */
    private function claim(Issue $issue, string $agentName): void
    {
        $session = AgentSession::create([
            'agent_name' => $agentName,
            'session_key' => (string) Str::uuid(),
            'host_identifier' => 'demo-host',
            'pid' => null,
            'process_started_at' => null,
            'capability_token_hash' => hash('sha256', Str::random(40)),
            'is_verified_live' => false,
            'last_seen_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(30),
        ]);

        TaskClaim::create([
            'issue_id' => $issue->id,
            'agent_session_id' => $session->id,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * The push queue page is otherwise always empty in the demo (GITHUB_TOKEN is deliberately
     * blank, see docs/demo-hosting.md), which would make Dibs' local-first sync model invisible
     * to a visitor. Seed a couple of rows directly rather than through EnqueueGitHubPush so they
     * land in specific, illustrative states instead of whatever the real write path happens to
     * produce.
     */
    private function pushQueueShowcase(GitHubProject $project, ProjectFieldOption $group, ProjectFieldOption $priority): Issue
    {
        $pushed = $this->issue('Rename the settings page to Preferences', $project, $group, $priority);
        GitHubPushQueueItem::create([
            'operation' => 'update_issue_body', 'target_type' => 'issue', 'target_id' => $pushed->id,
            'payload' => ['title' => $pushed->title], 'status' => 'pushed', 'attempts' => 1,
            'idempotency_key' => 'demo-pushed-'.$pushed->id, 'pushed_at' => now()->subMinutes(3),
        ]);

        $pending = $this->issue('Add a "someday" filter shortcut', $project, $group, $priority, labels: ['someday']);
        GitHubPushQueueItem::create([
            'operation' => 'create_issue', 'target_type' => 'issue', 'target_id' => $pending->id,
            'payload' => ['title' => $pending->title], 'status' => 'pending', 'attempts' => 0,
            'idempotency_key' => 'demo-pending-'.$pending->id,
        ]);

        return $pending;
    }
}
