# Initial architecture proposal

Status: foundation and manual import implemented; webhook receiver tested but activation pending. Laravel 13, Livewire 4 SFC, PHP 8.5, SQLite and private LAN access are settled. See the foundation PLAN.md and STATE.md for authoritative progress; later sections remain architectural guidance.

## Stack recommendation

- Laravel 13, subject to Composer compatibility checks at installation.
- Livewire 4 class-based single-file components; Volt is not separately required for this style.
- Alpine for client-only expansion, collapse, and remembered UI state.
- Tailwind and appropriate free Flux components for forms and common controls.
- SQLite on local disk, with foreign keys, WAL, a busy timeout, short write transactions, and a single sync writer initially.
- Pest for tests, Pint, Larastan/PHPStan at the project convention level, and Rector in dry-run mode.
- Docker for PHP/application tools per Andres's conventions. PHP 8.5 and docker/setup.sh are implemented.
- Current hosting is private LAN through Traefik with login; public webhook ingress remains pending. Do not expose private GitHub data through an unauthenticated network service.

Livewire components/controllers and Artisan commands delegate business logic to typed Actions. Actions use a GitHub service for external calls. Queue jobs orchestrate the same actions. No business logic hidden in a Blade component or queue handler.

## Authority and local data

GitHub remains authoritative for issues, labels, parent links, Project memberships, and Project fields. SQLite is a rebuildable read model of that data. The browser reads SQLite, not GitHub directly.

Local-only preferences, future pending operations, session claims, and repository/checkout configuration are NOT disposable cache. Keep them distinguishable from replicated data and back them up if introduced. Never call the entire database disposable once it holds those records.

Use local integer primary keys and unique GitHub node IDs on mirrored entities. Issue numbers are only unique within a repository. Display titles and field names are mutable; never use them as identity.

## Proposed schema

Start with these tables, implementing only the fields needed for the first read-only slice. Each mirrored entity has GitHub identifiers, remote_updated_at where available, last_synced_at, and remote availability/last_seen metadata appropriate to its endpoint. Local created_at/updated_at are not GitHub timestamps.

| Table | Important columns and constraints |
|---|---|
| repositories | id; github_node_id unique; owner; name; full_name; url; is_private; visibility/availability |
| projects | id; github_node_id unique; owner; github_number; title; url; is_closed; is_public; unique owner + github_number |
| issues | id; repository_id; github_node_id unique; github_number; title; body; state; state_reason; url; nullable parent_issue_id; github_parent_node_id for unresolved parents; sibling_position; unique repository_id + github_number |
| project_items | id; project_id; github_node_id unique; nullable issue_id; content_type; archived_at; status_option_id; group_option_id; priority_option_id; planned_on; due_on; repeat_rule; raw_fields_json; unique project_id + issue_id for issue-backed items |
| project_fields | id; project_id; github_node_id unique; name; data_type; nullable semantic_key (status/group/priority/planned/due/repeat); configuration_json |
| project_field_options | id; project_field_id; github_option_id; name; color; position; unique project_field_id + github_option_id |
| labels | id; repository_id; github_node_id unique; name; color; description; unique repository_id + name |
| issue_label | issue_id; label_id; unique pair; indexes on both foreign keys |
| comments | id; issue_id; github_node_id unique; body; author_login; remote_created_at; remote_updated_at; url; fetched on demand initially |
| sync_states | unique resource key; last_success_at; last_attempt_at; cursor/watermark; ETag if supported; last_error; retry_after; completed reconciliation generation |

An issue can appear in multiple GitHub Projects even though the user's normal convention is one primary Project. Preserve unexpected multiple memberships instead of losing remote data. Status, Group, Planned, Due, and Repeat belong to project_items, not issues, because fields are Project-specific.

For the initial app, frequently queried fields have typed columns on project_items so a daily list is a normal indexed query. raw_fields_json preserves other remote field values without requiring a generic field-value editor yet. project_fields maps stable IDs to the app's supported meanings. Confirm/select field mappings rather than permanently inferring them from names. Referenced options must belong to the correct Project and semantic field.

Draft items or PRs must not masquerade as issues. Record their content_type and available identifiers/payload; either display an explicit supported representation or report that they are not yet shown. Missing/unsupported content is not a deletion signal.

Index issues.parent_issue_id, project_items.project_id, project_items.issue_id, planned_on, due_on, and sync resource keys. Preserve native sibling order independently of the user's current table sorting. Reject local cycles and self-parenting when editing is implemented; tolerate unresolved remote parents without discarding children.

Do not add separate tasks/research/lessons tables: they are issues classified by labels. Organizational parents remain issues labeled parent. A task with children is not automatically an organizational container.

Deferred tables when the corresponding behavior exists:

- mutation_operations: durable outgoing command, target, payload, base remote state, status, attempts, error, request identity. Required before robust asynchronous editing, not for read-only sync.
- preferences: expanded nodes, selected views, and filters if browser storage is insufficient.
- agent_sessions / task_claims: local worker identity and expiring task claims; implemented with JSON CLI fallback commands. A local stdio MCP server will expose them as agent tools.
- website_bindings: explicit website parent → repository/local checkout metadata, before agents execute project work.

## Sync scheduling

The page can poll OUR SERVER about every 3–5 seconds while visible. That endpoint queries the local read model or lightweight sync revision, not the GitHub API. Stop/suspend hidden-page work; preserve client tree state across refreshes. Do not re-render an entire large tree merely because a timer fired.

A shared, locked sync process decides whether GitHub is stale. Proposed starting interval: 30–60 seconds while at least one client is active, configurable. Refresh on page load when stale and after a successful app mutation. Provide an explicit Refresh button and last successful sync/status indicator.

Multiple tabs/users must coalesce to one sync job. Agent writes through the app can refresh their affected records immediately. Writes made directly with gh appear on the next sync; browser activity should not be the only trigger once agents/background workflows exist.

Use REST conditional requests/ETags where supported, pagination, backoff, and rate-limit headers. GitHub GraphQL responses and Project field changes need their own reconciliation strategy; do not assume that updating a Project field bumps the issue's updated_at timestamp. Do not assume every endpoint supports ETags or that a GraphQL request is free when unchanged.

Start with repository issue updates plus full small Project snapshots. Later optimize measured bottlenecks. Periodically reconcile full membership and labels. Poll comments only when needed or when known changed. GitHub prefers webhooks, but personal-account Project event coverage and local delivery need verification; do not promise webhooks replace all polling.

Apply network results in short local transactions after network I/O. Never hold SQLite write locks across GitHub requests. Use a complete reconciliation generation before marking missing records absent. A failure on page 2, an authorization error, or a transient empty response must not erase cached tasks.

State distinctions matter: closed issue, removed Project membership, deleted issue, and inaccessible issue are different. Preserve last-known data with an explicit unavailable/stale indication until absence is confirmed in the relevant scope. Do not automatically write cached state back to GitHub during an import.

## Writes: next slice after read-only sync

One Action per intent (rename issue, complete issue, assign parent, change planned date), shared by UI and agent entry points. Prefer field-level changes over replacing an entire stale remote object.

Show pending/saved/failed state explicitly. Keep optimistic UI state separate from the last confirmed snapshot. Retry reads safely. Creation/comment writes with an ambiguous timeout may already have succeeded remotely; local idempotency keys alone do not make GitHub mutations idempotent. Reconcile before retrying instead of creating duplicates.

A pre-write remote comparison reduces lost updates but is not an atomic GitHub compare-and-swap guarantee. Define conflict behavior and preserve user edits; do not advertise impossible transactional guarantees across GitHub and SQLite. Project Status and issue state require an explicit policy before completion actions ship.

## First TDD targets

1. Reimporting the same fixture preserves local identities and creates no duplicates.
2. A renamed/reparented issue and changed labels/membership update correctly.
3. Unresolved parents arriving later link correctly; standalone tasks remain visible.
4. Partial pagination/API errors preserve the last successful snapshot and set the expected failure state.
5. Removed membership is not issue deletion; inaccessible data is not silently destroyed.
6. Concurrent refresh requests queue one sync; cooldown and rate-limit backoff are honored.
7. Planned/Due queries handle null dates, overdue dates, and midnight in the chosen timezone.
8. Private pages and actions reject unauthorized access under the chosen hosting model.

Use synthetic fixtures, Http::fake(), and prevent stray network requests. Test Actions directly for behavior and Livewire/HTTP for validation, rendering, and access contracts. SQLite in-memory tests do not prove file locking/WAL concurrency; use a temporary file integration test when implementing that behavior.

## First implementation slice

1. Confirm framework/runtime and initial access model.
2. Scaffold Laravel + Livewire 4 + SQLite + Pest and local development tooling, preserving CLAUDE.md and other project notes.
3. Add core schema with migrations and meaningful constraint tests.
4. Implement a read-only GitHub import through TDD using fake responses; a manual command is the first entry point.
5. Show a basic collapsible tree from SQLite with no GitHub calls during rendering.
6. Add shared refresh scheduling, sync status, and the first daily view.
7. Add mutations/agent entry points in a separate slice after their contract is reviewed.

No remote task writes, new credentials, deployed routes, or automatic notifications are required for the initial schema/scaffold.

## Sources checked

- Livewire 4 single-file components / Volt migration: https://livewire.laravel.com/docs/4.x/upgrading
- Livewire components: https://livewire.laravel.com/docs/4.x/components
- Livewire polling: https://livewire.laravel.com/docs/4.x/wire-poll
- GitHub polling and conditional request recommendations: https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api
- SQLite WAL constraints: https://www.sqlite.org/wal.html
