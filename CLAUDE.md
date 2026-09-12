# Todo — project context and conventions

## Purpose and next work

This is Andres's private global task and knowledge system for current work, personal projects, career development, learning, and everyday tasks.

**Current: implementing the sync-authority pivot (local SQLite is now authoritative; GitHub becomes an asynchronously-pushed, mostly-read-only mirror via a durable push queue) as part of the MCP-backed agent-planning plan.** This project tracks its own multi-step work in its own GitHub issues rather than the global `.ai/plans/` file-based orchestrator-worker protocol — read issue #36 (application context), #37 (the authoritative MCP/sync-pivot plan), #38 (open questions), and #51 (dogfooding friction/lessons) before coding. Do not recreate `.ai/plans/` files for Todo's own work; GitHub issues plus local SQLite are the source of truth for planning state here.

The user wants low-friction capture, editing, filtering, a collapsible task tree, website-specific knowledge, and a passive daily list. Notifications and calendar integration can follow. Preserve the existing GitHub issues and their identities when adding the UI.

## Repository and current implementation

- Private repository: https://github.com/loki495/Todo
- Local checkout: /home/andres/www/Todo
- Remote: origin = git@github.com:loki495/Todo.git
- Observed initial layout: one checkout on main tracking origin/main; no local/feature branches or extra worktrees. Reinspect before consequential git operations; do not assume the layout stays unchanged.
- Laravel scaffolding, dependencies, schema files and tests now exist. Docker app service is todo-app; local Traefik override routes todo.ac495.net. See GitHub issue #37 for the authoritative implementation status and remaining work.
- Local SQLite is authoritative once data is imported; GitHub Issues and Projects hold an asynchronously-pushed mirror of that same data, used as a manual-pull baseline and a fallback surface with no live Todo app (see #37). The pinned usage guide is https://github.com/loki495/Todo/issues/20.
- GitHub CLI is installed and authenticated locally, with Projects access. Verify the actual account and permissions before writes. Do not expose tokens or embed local credentials in browser code.
- All repository/project data is private. Keep any future hosting private as well.

Read ~/dotfiles/ai/CLAUDE.md and applicable AGENTS.md instructions before substantive work. Preserve project notes under .claude/ if introduced. Follow the shared planning/research/lessons conventions when application development starts; this document is not a replacement for an implementation plan.

## Broad categories: four private GitHub Projects

| Project | URL | Meaning |
|---|---|---|
| Work | https://github.com/users/loki495/projects/2 | Projects and todos for Andres's CURRENT JOB only |
| Personal Projects | https://github.com/users/loki495/projects/3 | Personal websites/software, homelab, backups, dotfiles, and forward-looking career tasks |
| Learning & Self-Improvement | https://github.com/users/loki495/projects/5 | Learning, studying codebases, electronics, fitness, and personal development |
| Random Tasks | https://github.com/users/loki495/projects/6 | Household chores, purchases, inventory, and general errands |

Job hunting, resume/profile improvements, and LinkedIn tasks belong in Personal Projects → Career, not Work or Learning. The old combined Todo Project and Life & Misc Project were emptied and deleted; the Todo repository remains.

Use one primary Project per task. Projects are broad categories; individual websites normally use parent issues and Group values within them. GitHub Projects do not have parent Projects. Do not create a Project per website merely to emulate hierarchy.

Andres actively edits issues, labels, parents, groups, and membership in GitHub. Always read current data before changing it. Never restore an old assignment merely because it differs from this file or prior session notes. Avoid static task counts and exhaustive task copies here.

## Tree and group conventions

- A GitHub Project is the broad area. Within a selected Project area, Group is the first visual root in the app; native issue/sub-issue links form the hierarchy below that root.
- An issue may have no Group. Ungrouped root issues remain visible directly under the Project area. Group headings are virtual UI rows, not Issues, and disappear when that Group filter is active.
- A Group is a website/topic/category within one Project. It does not create a GitHub Project or a fake Issue. Parent links remain optional and express actual task breakdown below the Group.
- Do not create new empty organizational parent Issues merely to form categories. Existing parent-labeled issues that contain meaningful task or knowledge content may remain; organization-only containers will be migrated to Groups, with children detached or retained under meaningful task parents, before deletion.
- Each issue has one direct native parent. Use ordinary links for additional associations rather than duplicating the issue or silently replacing a parent. A task may have sub-issues without carrying the parent label.
- Preserve the real task relationship #9 under #2 unless Andres changes it; it is not an organization-only container.
- Group and parent selection remain separate during capture/editing. Selecting a Group does not invent a parent, and selecting a parent does not silently change Group.
- Show all imported issue labels as compact list-row badges. Labels remain lightweight cross-cutting metadata and do not duplicate Projects or Groups.

## Labels: current simplified scheme

Latest decision supersedes the earlier area-label scheme: **do not duplicate Projects, Groups, or website parents with labels.** Area labels and site/topic labels were deliberately removed.

| Purpose | Labels |
|---|---|
| Workflow | today, next, waiting, someday, recurring, needs research |
| Structure | parent, guide |
| Content | bug, documentation, research, lesson, decision |

Keep labels small and useful. Do not restore GitHub's unused default labels or add one label per website. Existing topics and category membership live in Group/parent/Project. Moving a task no longer requires maintaining an area label.

Use documentation for actual documentation work, bug for a concrete defect, and the knowledge labels for the corresponding records. Do not label all code-study tasks as research or all AI-assisted work as a separate category by default.

**`research` vs `needs research` (decided 2026-09-12 after a real mix-up):** `research` (and `lesson`/`decision`/`guide` alongside it) marks a completed knowledge *record* — the issue body itself is the maintained finding, and the app's Tasks/Knowledge toggle treats any of these four labels as Knowledge, hiding the issue from the default Tasks view. `needs research` is the opposite: a workflow marker on an ordinary *task* that still needs investigation before it can proceed — it does not affect Tasks/Knowledge classification. Applying `research` to a task that just needs research done (instead of `needs research`) will make it disappear from Tasks view, which is exactly what happened to #35 and looked like data loss.

## Website-specific research, lessons, and decisions

Agreed approach: create a separate knowledge issue per meaningful website/topic, discoverable from the website parent, with research, lesson, or decision as appropriate. Avoid burying all knowledge in one long parent-issue comment stream. No fabricated or empty knowledge records have been created just to fill this structure.

- Issue body: maintained current findings/conclusion, scope, relevant sources, and remaining questions.
- Comments: evidence, discussion, revisions, and the history of how the conclusion was reached.
- Useful details: what was learned, website/repository it applies to, source task, evidence, relevant files/commit, and when it was verified.
- Comments cannot have native labels. A heading such as [LESSON], [RESEARCH], or [DECISION] is a recognizable text convention only, not filterable comment metadata. Prefer a labeled issue when a finding deserves independent retrieval.
- Future UI should separate Tasks and Knowledge for each website while reading from the same GitHub data.
- Choose one canonical maintained record rather than keeping conflicting summaries in several places. Link from related tasks and website parents.
- When a finding becomes an instruction agents MUST follow, put that rule in the relevant website repository's instructions and link to the supporting knowledge issue. Keep those instructions concise.
- Global rules belong in shared documentation; website-specific facts stay scoped to that website. Follow the user's global-memory approval conventions before changing shared instructions.
- Do not treat a reusable lesson as a pending chore forever. Decide the knowledge issue lifecycle and its relationship to task completion/progress when implementing the UI; it is not settled yet.

## Scheduling and daily lists

Existing fields on each Project:

- Planned: intended work date.
- Due: a real deadline, if any.
- Repeat: human-readable recurrence rule; not currently executable automation.
- Group: website/topic/category within that Project.
- Status: Todo, In Progress, Done (verify actual options before updates).

Existing views include All tasks, Daily, Today picks, Due, Timeline, group tabs, Parents, and Hierarchy.

- Daily: open issues with Planned today or earlier (planned:<=@today).
- Due: open issues with Due today or earlier (due:<=@today).
- Today picks: manual today label. It does not reset automatically.
- today, next, waiting, someday are optional; do not invent dates or priorities for imported tasks.
- Timeline is GitHub's roadmap, not a month/week event calendar. Planned/Due mapping in its Date fields UI has not been verified through the API.
- Date fields are date-only. Timed events need local time, duration, and timezone; default context is America/Los_Angeles.
- Scheduling fields are per Project. Current manual cross-project shortlist: https://github.com/loki495/Todo/issues?q=is%3Aissue+is%3Aopen+label%3Atoday
- Recurrence is manual for now: close the occurrence, create the next issue linked to the previous, add it to the appropriate Project/parent, and set its schedule. Distinguish fixed-calendar recurrence from intervals after completion.
- No automated recurrence, reminders, Google Calendar integration, or automatic project intake/routing is enabled.

## Next implementation: web UI

Local SQLite is the task/knowledge store; GitHub mirrors it asynchronously (see #37). Existing issue IDs, URLs, labels, parents, and Project fields should remain usable from GitHub, the app, and agents. This intentionally is the authoritative database now — the earlier caution against a second authoritative database no longer applies; the risk to guard against instead is SQLite and the GitHub mirror silently diverging, which the push queue's `needs_attention` state exists to surface.

Requested behavior:

1. Show Work, Personal Projects, Learning & Self-Improvement, and Random Tasks as clear areas.
2. Show top-level issues collapsed by default; expand/collapse children recursively in place, including nested parents. Remember expansion state. Keep standalone tasks discoverable.
3. Capture with one title field and Enter. Allow area/website/parent selection with minimal steps and sensible defaults from the current view.
4. Edit titles inline, complete via checkbox with Undo, and open a detail panel for descriptions, comments, links, and checklists.
5. Provide clickable filters, search, persistent views, and website/topic browsing without requiring users to search a label list.
6. Offer separate Tasks and Knowledge browsing per website, with research/lesson/decision filtering and maintained summaries visible first.
7. Provide one daily list across all Projects, including planned/overdue work and manual picks. Keep planned dates distinct from deadlines.
8. Add recurrence handling after defining rules and history behavior; a passive daily list is acceptable before notifications.
9. Consider a true calendar and Google Calendar integration later for scheduled events/reminders. Do not claim either exists today.
10. Preserve user edits from GitHub; refresh state before writes and handle conflicting updates clearly.

## Next implementation: shared local-agent task interface

Andres wants local agents to track individual website/project work here and update status, useful progress, research, and lessons. Build one consistent interface that agents and the UI can share.

Proposed workflow to implement:

1. Read the task, parent context, relevant knowledge, and the actual website repository instructions.
2. Resolve the website repository/local checkout from explicit metadata on its parent; do not guess the target from a display name.
3. Record the active session/worker and mark the task in progress when work starts. Define a claim mechanism that avoids two agents independently claiming the same task.
4. Post meaningful checkpoints, blockers, decisions, and verification results rather than every tool action or raw session transcript.
5. On completion, summarize changes, tests/checks, commits or PR links, remaining work, and links to new knowledge records; update status and close the issue consistently.
6. Save durable knowledge in the agreed scoped records and instructions. Keep temporary execution notes in local session files; avoid making them a second permanent backlog.
7. Define safe retries and conflict handling so repeated requests do not duplicate tasks, comments, or recurring occurrences.

The original agreed order was scaffolding → manual GitHub pull and issue webhooks → hierarchy UI → polling → create/edit operations and shared agent interface; webhooks and polling are being removed in favor of local-authoritative writes with an asynchronous GitHub push queue (#37, #49). The canonical agent interface is a host-local stdio MCP server; the Artisan CLI is its recovery and smoke-test fallback. This roadmap is not blanket authorization for unattended external writes, notifications, pushes, or deployments. Explicitly establish which routine agent updates may happen automatically before enabling that behavior.

## Implementation decisions still open

- Framework/runtime, credential provisioning, and webhook ingress are settled. LAN access uses todo.ac495.net through Traefik with application login.
- Cache/offline needs beyond the push queue's own backlog (SQLite authority and the GitHub sync strategy are now settled — see #37/#49).
- Agent interface, session identity/claims, concurrency, retry semantics, and automatic-write authorization boundaries.
- Rules for synchronizing native issue open/closed state with Project Status.
- Treatment of persistent knowledge records in progress counts and task views.
- Calendar scope, recurring-occurrence generation, completion semantics, timezone handling, and future notifications.
- Testing/linting commands, branch model for application development, and hosting/container layout.

Prefer straightforward implementation choices; do not reopen settled product conventions without a concrete reason. Ask only for decisions that materially affect implementation, and continue independent work while awaiting answers.

## Testing: Pest TDD for non-obvious behavior

User preference: try to use test-driven development with Pest for anything non-obvious. Write a focused failing behavior test first, verify it fails for the intended reason, implement the smallest correct behavior, then refactor with the tests passing.

Prioritize action-level tests for sync idempotency, partial pagination failures, parent resolution/cycles, deletions vs lost access, field mappings, rate-limit/backoff handling, stale writes, recurrence, and agent claims. Include meaningful sad paths and specific expected outcomes. Add feature tests for authentication, validation, and HTTP/Livewire contracts. Fake GitHub requests; prevent stray real network calls in automated tests. Do not fabricate tests for trivial static markup or mirror implementation details. Never delete, weaken, or skip a failing test to make the suite pass.

Initial architecture and sync/schema proposal: docs/architecture.md. Stack decisions are settled; scaffold and migrations are verified. GitHub issue #37 is the authoritative plan and supersedes earlier phase-order proposals.

## Safety and verification

- Follow global instructions for approvals. Never push without first stating the exact remote and branch and receiving explicit confirmation. Never force-push or rewrite shared history without explicit authorization.
- Existing authorization covers the requested task organization and saving these conventions. The user also authorized scaffolding and LAN serving through Traefik. This does not authorize public exposure, emailing, notifications, production changes, or unrestricted ongoing agent writes.
- Before changing external state, verify owner/repository/project and inspect live data. Preserve user changes, task identities, parent links, and schedule values.
- Choose checks appropriate to the future implementation. Never weaken or bypass tests to make them pass. Run Pest/Pint/PHPStan/Rector inside the app container; scaffold checks passed (15 Pest tests, PHPStan level 6, Pint, Rector dry-run, assets and HTTPS smoke check).
- Keep secrets out of issues, logs, browser bundles, and committed configuration.

## Running the scaffold

See README.md for setup. App: https://todo.ac495.net, container todo-app, PHP 8.5. Create a login with `docker compose exec -u www-data app php artisan todo:user`. Run PHP tooling inside that container; Node builds use `docker compose run --rm node npm run build`. No default account exists. Scaffold, manual import, read-only hierarchy UI, and local-first quick capture (via the durable push queue, see #37/#49) are complete. Automatic polling and inbound webhook delivery were removed 2026-09-11 in favor of the push queue; manual `Refresh from GitHub` / `todo:sync` remain the only inbound path. The Flux capture modal supports the viewed Project/Group, searchable parent selection, and existing or new labels/Groups; existing-task title/description editing is available; comments and completion are available. Scheduling fields remain pending; local-agent reads, hardened claims/heartbeats/releases (PID+process-start-time verified via `/proc`, requiring `pid: "host"` on the `todo-app` container — see #40), comments, and completion are available through documented Artisan commands. Run scripts/github-pull (optionally --comments) to refresh with host gh authorization.

## Theme and sync additions

Theme selector: System (default), Light or Dark. Preference persists in browser localStorage (`todo-theme`), applies before paint and follows OS changes in System mode. Verified at desktop and 390px mobile widths.

Manual read-only import: `scripts/github-pull --comments` uses gh token over stdin. Initial and repeat imports verified with 35 issues and four Projects on 2026-09-08; query live data instead of treating that count as permanent. Repository/Project/issue/label/parent/schedule/comment data remains GitHub-authoritative. The read-only UI provides areas, recursive collapsed hierarchy, saved expansion, search with ancestor context, Group/label/state filters, Tasks/Knowledge, daily list, and safe issue details.

Inbound GitHub webhooks and automatic freshness polling have been removed as of 2026-09-11 (#49). GitHub sync is now one-directional (local SQLite → GitHub via a durable push queue); the only path for GitHub → local data is the manual `Refresh from GitHub` button / `todo:sync` command / `scripts/github-pull`.

## Local agent interface and MCP

The canonical interface is a host-local stdio MCP server. Its tools must delegate to typed GitHub-first Actions and use the local claim/session records; never pass GitHub credentials through tool arguments or output. `todo:agent:list`, `show`, `claim`, `release`, `comment`, and `complete` remain JSON CLI fallback commands for recovery and smoke testing. `docs/agent-interface.md` is the detailed MCP/CLI contract.

The server foundation is implemented (#41): `App\Mcp\Servers\TodoServer`, registered in `routes/ai.php`, started with `php artisan mcp:start todo`. It runs on `laravel/mcp` `1.0.0-beta.1` specifically — not the stable `0.9.x` line — since only that pre-release targets the stateless MCP 2026-07-28 revision the claim/capability-token design assumes; the stable line still has the old `initialize` handshake. A `todo_status` tool reports server/repository identity for smoke-testing. Read/write task and knowledge tools (#42-#45) are not yet built.
