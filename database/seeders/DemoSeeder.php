<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ResolveActiveRepository;
use App\Actions\SyncIssueLinks;
use App\Actions\SyncStepFields;
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
    /** Local-only tasks have no GitHub identity, so the demo's don't carry a made-up number or link either. */
    private const array LOCAL_IDENTITY = ['github_node_id' => null, 'github_number' => null, 'url' => null];

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

        // The demo runs local-only (no DIBS_GITHUB_OWNER/DIBS_GITHUB_REPO), so its data lives in the
        // same local repository row a visitor's own new tasks and labels are created in.
        $this->repository = app(ResolveActiveRepository::class)->local();

        foreach (['bug', 'feature', 'documentation', 'research', 'decision', 'lesson', 'guide', 'rule', 'agent task', 'plan', 'today', 'next', 'waiting', 'someday', 'needs research', 'parent'] as $name) {
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

        $this->seedAgentPlan($project, $groups['Dibs'], $priorities[2]);

        $this->pushQueueShowcase($project, $groups['Dibs'], $priorities[2]);

        $this->issue('Move the reverse proxy to the new host', $project, $groups['Homelab'], $priorities[3]);
        $this->issue('Automate the nightly backup verification', $project, $groups['Homelab'], $priorities[2], labels: ['someday']);
        $this->issue('Write up the home network segmentation decision', $project, $groups['Homelab'], $priorities[3], labels: ['decision']);

        $this->issue('Draft the "why local-first" post', $project, $groups['Blog'], $priorities[4], labels: ['someday']);
        $this->closedIssue('Set up the blog\'s RSS feed', $project, $groups['Blog']);
    }

    private function seedAgentPlan(GitHubProject $project, ProjectFieldOption $group, ProjectFieldOption $priority): void
    {
        $rule = $this->issue('Rule: Keep notification delivery local and opt-in', $project, $group, $priority, labels: ['rule', 'guide'], body: <<<'MD'
            Rule: Save notifications locally before attempting delivery. Email digests are disabled by default. Tests use fake mail delivery; never send a real message from a test or the public demo.

            Trigger: Changing notification storage, preferences, or delivery.
            Check: Opt-out and failed-delivery tests pass without contacting a mail service.
            MD);
        $decision = $this->issue('Decision: One notification store, separate delivery channels', $project, $group, $priority, labels: ['decision'], body: <<<'MD'
            Fictional design decision for this demo project.

            Keep one local notification record per event. The bell reads that store; an optional digest groups the same records. A failed email must not hide an in-app notification or roll back local work.

            The agreed contract is: id, event_key, title, read_at, created_at. The preferences screen owns digest_enabled, defaulting to false. The bell and preferences agents can work independently against this contract.
            MD);
        $lesson = $this->issue('Lesson: Retried digest jobs must not duplicate notifications', $project, $group, $priority, labels: ['lesson'], body: <<<'MD'
            Fictional finding from the demo team's retry investigation.

            A worker can restart after saving an event but before acknowledging its job. Retrying that job used to create a second unread item. Use a stable event_key with a unique constraint and test replaying the same event twice.

            Reuse this finding when checking the bell count and the eventual digest integration, rather than rediscovering the failure in a new chat.
            MD);
        foreach ([$rule, $decision, $lesson] as $knowledge) {
            $knowledge->update(['state' => 'CLOSED', 'closed_at' => now()->subDays(2), 'state_reason' => 'COMPLETED']);
        }

        $plan = $this->issue('Plan: Add notification digests', $project, $group, $priority, labels: ['plan', 'agent task'], body: <<<'MD'
            ## Goal

            Let people catch up on task activity without watching the workspace all day: an unread bell now, with a user-controlled email digest later.

            ## Current checkpoint

            Simulated demo scenario: the data model is complete. Claude is building the notification bell while Codex adds the opt-in preferences. Integration waits for both; documentation is ready for another agent to claim.

            ## Why keep this in Dibs?

            A new session can read this plan, follow the linked rule and design decision, and ask todo_next for a ready step. Each step carries acceptance criteria, expected evidence, likely files, and a checkpoint. Claims show who owns which work; dependencies keep an agent from starting integration too soon. The linked lesson preserves a retry bug across sessions.

            ## Boundaries

            This is fictional sample work, not a notification feature already implemented in Dibs. The two sample claims do not represent running agents. No mail is sent. Open the child tasks and their linked context to explore the workflow.
            MD);
        app(SyncIssueLinks::class)->handle($plan, array_map(fn (Issue $issue): array => ['issueId' => $issue->id], [$rule, $decision, $lesson]), []);

        $definitions = [
            [
                'title' => 'Design the notification data model',
                'objective' => 'Define the shared storage contract so the bell and preferences can be built independently.',
                'doneWhen' => 'A unique event_key prevents duplicate records; read_at is nullable; digest_enabled defaults to false.',
                'evidence' => 'Migration and model tests cover event replay and the default opt-out state.',
                'filesLikely' => 'database/migrations/*_create_notifications_table.php; app/Models/Notification.php; tests/Feature/Notifications/StorageTest.php',
                'context' => $decision,
            ],
            [
                'title' => 'Build the in-app notification bell',
                'objective' => 'Show unread local notifications and let the user mark them read using the agreed storage contract.',
                'doneWhen' => 'The unread count updates after marking an item read; empty and narrow-screen states remain usable.',
                'evidence' => 'Pest covers unread/read transitions and an empty inbox; attach mobile and desktop screenshots.',
                'filesLikely' => 'resources/views/components/notification-bell.blade.php; tests/Feature/Notifications/BellTest.php',
                'context' => $decision,
            ],
            [
                'title' => 'Add an email digest opt-in setting',
                'objective' => 'Let the user choose a digest frequency while keeping email disabled until they opt in.',
                'doneWhen' => 'Preferences persist; invalid frequencies show validation errors; disabled digests never queue email.',
                'evidence' => 'Pest covers default opt-out, saved preferences, and invalid input with fake mail delivery.',
                'filesLikely' => 'resources/views/pages/notification-preferences.blade.php; tests/Feature/Notifications/PreferencesTest.php',
                'context' => $decision,
            ],
            [
                'title' => 'Verify the notification flow end to end',
                'objective' => 'Connect the bell and preferences after both implementations are complete, then exercise retries and delivery failure.',
                'doneWhen' => 'One event creates one unread item even after a retry; opted-out users receive no digest; delivery failure preserves local records.',
                'evidence' => 'Integration tests use fake mail and cover duplicate events, opt-out, and a failed delivery.',
                'filesLikely' => 'app/Actions/Notifications/BuildDigest.php; tests/Feature/Notifications/DigestFlowTest.php',
                'context' => $lesson,
            ],
            [
                'title' => 'Document notification preferences and recovery',
                'objective' => 'Draft the user guide from the accepted design while the two implementation agents work.',
                'doneWhen' => 'The draft explains unread status, opt-in, and retry recovery, with a checklist to confirm against the final integration.',
                'evidence' => 'Documentation diff and a list of settings and failure cases checked against the linked decision.',
                'filesLikely' => 'docs/notifications.md',
                'context' => $decision,
            ],
        ];
        $steps = [];
        foreach ($definitions as $position => $definition) {
            $step = $this->issue($definition['title'], $project, $group, $priority, parent: $plan, labels: ['agent task'], body: 'Objective: '.$definition['objective']."\n\nFictional demo work; file paths describe the sample project.");
            $step->update(['sibling_position' => $position]);
            app(SyncStepFields::class)->handle($step, array_intersect_key($definition, SyncStepFields::FIELDS));
            app(SyncIssueLinks::class)->handle($step, [['issueId' => $rule->id], ['issueId' => $definition['context']->id]], []);
            $steps[] = $step;
        }

        $steps[0]->update(['state' => 'CLOSED', 'closed_at' => now()->subDay(), 'state_reason' => 'COMPLETED']);
        Comment::factory()->closing()->for($steps[0], 'issue')->create([
            'body' => 'Simulated completion: agreed the notification contract and recorded the linked decision. Example evidence: storage tests pass for duplicate event keys and default opt-out. No feature code was actually written by this demo.',
        ]);
        foreach ([$steps[1], $steps[2], $steps[4]] as $step) {
            app(SyncStepFields::class)->handle($step, blockedBy: [$steps[0]->id]);
        }
        app(SyncStepFields::class)->handle($steps[3], blockedBy: [$steps[1]->id, $steps[2]->id]);
        $this->claim($steps[1], 'claude:demo:notification-bell', ['resources/views/components/notification-bell.blade.php', 'tests/Feature/Notifications/BellTest.php']);
        $this->claim($steps[2], 'codex:demo:preferences', ['resources/views/pages/notification-preferences.blade.php', 'tests/Feature/Notifications/PreferencesTest.php']);
        $this->comment($steps[1], 'Simulated checkpoint: unread list and empty state are drafted against the agreed contract. Next: finish mark-as-read coverage and check the bell at a phone viewport. Preferences are owned by the other agent.');
        $this->comment($steps[2], 'Simulated checkpoint: preference validation and default opt-out are drafted. Next: check invalid frequencies and disabled-digest behavior with fake mail. The bell is owned by the other agent.');
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
    private function issue(string $title, GitHubProject $project, ProjectFieldOption $group, ProjectFieldOption $priority, ?Issue $parent = null, array $labels = [], ?string $body = null): Issue
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create([
            'title' => $title,
            'body' => $body,
            'parent_issue_id' => $parent?->id,
        ] + self::LOCAL_IDENTITY);

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
        $issue = Issue::factory()->for($this->repository, 'repository')->create(['title' => $title, 'body' => null, 'state' => 'CLOSED'] + self::LOCAL_IDENTITY);
        ProjectItem::factory()->for($project, 'project')->create(['issue_id' => $issue->id, 'group_option_id' => $group->id]);

        return $issue;
    }

    private function comment(Issue $issue, string $body): void
    {
        Comment::factory()->for($issue, 'issue')->create(['body' => $body, 'author_login' => null]);
    }

    private function researchNote(string $title, GitHubProject $project, string $body): void
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create(['title' => $title, 'body' => $body] + self::LOCAL_IDENTITY);
        ProjectItem::factory()->for($project, 'project')->create(['issue_id' => $issue->id]);
        $issue->labels()->attach($this->labels['research']->id);
    }

    private function decisionNote(string $title, GitHubProject $project, string $body): void
    {
        $issue = Issue::factory()->for($this->repository, 'repository')->create(['title' => $title, 'body' => $body] + self::LOCAL_IDENTITY);
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
     *
     * @param  list<string>  $paths
     */
    private function claim(Issue $issue, string $agentName, array $paths): void
    {
        // A static template may be served long after boot; keep illustrative claims visible without a real heartbeat.
        $expiresAt = now()->addYear();
        $session = AgentSession::create([
            'agent_name' => $agentName,
            'session_key' => (string) Str::uuid(),
            'host_identifier' => 'demo-host',
            'pid' => null,
            'process_started_at' => null,
            'capability_token_hash' => hash('sha256', Str::random(40)),
            'is_verified_live' => false,
            'last_seen_at' => now()->subMinutes(2),
            'expires_at' => $expiresAt,
        ]);

        TaskClaim::create([
            'issue_id' => $issue->id,
            'agent_session_id' => $session->id,
            'expires_at' => $expiresAt,
            'paths' => $paths,
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
