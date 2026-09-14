# Architecture

This describes Dibs as it actually exists today, not a proposal. For the product pitch and
quick start, see [`README.md`](../README.md); for the full MCP/CLI agent contract, see
[`agent-interface.md`](agent-interface.md).

## Stack

Laravel 13, Livewire 4 class-based single-file components, PHP 8.5, SQLite, Tailwind 4, Flux
UI. Docker Compose for local development (`docker/setup.sh`). Livewire components and Artisan
commands delegate business logic to typed Actions in `app/Actions/`, shared by the web UI and
the MCP/CLI agent surface — no business logic lives in a Blade component, console command, or
queue handler.

## Sync authority

**Local SQLite is authoritative. GitHub Issues/Projects are an asynchronous, mostly-read-only
mirror**, decided 2026-09-11 after starting from the opposite (GitHub-authoritative,
webhook/polling-driven) design. A write commits to SQLite immediately as the confirmed result
and enqueues a GitHub push rather than calling the GitHub API inline; the browser and every MCP
tool read SQLite only and never call GitHub directly.

There are no inbound webhooks and no scheduled freshness polling — both existed in the earlier
design and were removed. The only path for GitHub → local data is a manual pull
(`scripts/github-pull`, `php artisan todo:sync`), used for initial import, disaster recovery,
and bringing a second instance in sync. It never overwrites unpushed local changes.

Identity: local integer primary keys plus a unique GitHub node ID on every mirrored entity.
Issue numbers are only unique within a repository; titles and field names are mutable and are
never used as identity.

## Schema

Grouped by purpose; see `database/migrations/` for exact columns and constraints, which change
more often than this document should try to track.

**GitHub mirror** (`repositories`, `projects`, `issues`, `project_fields`,
`project_field_options`, `project_items`, `labels`, `issue_label`, `comments`) — the read model
described above. `github_node_id` is nullable on `issues`, `labels`, `project_items`, and
`comments` specifically to allow local-first creation before a row has been pushed to GitHub
and gotten a real node ID back. An issue can belong to multiple GitHub Projects even though the
normal convention is one primary Project; unexpected multiple memberships are preserved, not
collapsed. Status/Group/Priority/Planned/Due/Repeat live on `project_items`, not `issues`,
because those fields are Project-specific. `project_fields.semantic_key` maps a Project's own
field IDs to the meanings the app understands (status/group/priority/planned/due/repeat) so a
renamed GitHub field doesn't silently break the mapping.

**`sync_states`** — one row per mirrored resource, tracking last successful/attempted sync and
the last error, read by the manual-pull path only (no longer drives any scheduled behavior).

**`github_push_queue`** — the durable outbound queue described below: `operation`, `target_type`
+ `target_id`, a JSON `payload`, `status` (pending/failed/needs_attention/pushed), `attempts`,
`last_error`, and a unique `idempotency_key`.

**`agent_sessions` / `task_claims`** — local worker identity and expiring task claims. A claim
binds to the caller's real OS process (host, pid, process start time verified via
`/proc/<pid>/stat`) rather than a self-reported session string, plus a server-issued capability
token (`capability_token_hash`, SHA-256; the plaintext is returned once, at claim time, and
never stored). `is_verified_live` is false when the PID/start-time couldn't be independently
confirmed (cross-namespace caller, unreadable `/proc`) — the claim still succeeds but is flagged
as weaker assurance rather than silently trusted. Full contract in `agent-interface.md`.

**`mcp_write_receipts`** — backs the idempotency-key mechanism every MCP write tool can use
(`ResolveIdempotentWrite`): a repeated call with the same key returns the original result
instead of repeating the write.

**`todo_capture_requests` / `capture_settings`** — schema for the natural-language capture
feature (an LLM structures free text into a draft task via the host capture bridge, see
`capture-bridge.md`). The bridge mechanism is built and verified; the Dibs-side feature that
uses these tables (capture UI, settings/opt-in) is not yet built.

**`github_mutations`** (`app/Models/GitHubMutation.php`, written only by
`app/Actions/TrackGitHubMutation.php`) — predates the push queue: a synchronous-write tracking
table from the earlier GitHub-authoritative design ("record intent, confirm, or mark for
reconciliation on an ambiguous network outcome"). Nothing in the app currently calls
`TrackGitHubMutation`; it's dead code kept alive only by its own test coverage. Candidate for
removal — flagged here rather than silently deleted since removing a table/model is a
deliberate call, not a documentation change.

Not built as separate tables: `research`/`lesson`/`decision` records, or organizational
parents — these are ordinary issues classified by label (`research`, `lesson`, `decision`,
`parent`), not distinct schema.

## Push queue and write model

Every write Action (`CreateTodoIssue`, `ReviseTodoIssue`, `ReviseTodoComment`,
`CreateTodoComment`, `CompleteTodoTask`, `ClaimTaskForAgent`, and their UI-facing equivalents)
follows the same shape: one short `DB::transaction()` per intent that writes SQLite as the
confirmed result, then calls `EnqueueGitHubPush` to durably record the outbound operation. No
Action calls the GitHub API inline.

`DrainGitHubPushQueue` (`app/Actions/DrainGitHubPushQueue.php`) delivers queued rows to GitHub
independently of the write that created them, one GraphQL/REST call per row, always *outside*
any DB transaction (a network call never holds a SQLite write lock). Operations run in a fixed
dependency order (`OPERATION_ORDER`), not insertion order — an issue must exist on GitHub
before its Project membership can, for example — and a handler whose dependency hasn't pushed
yet returns `waiting` and is retried on the next pass rather than failing.

`php artisan todo:push:drain` (registered `Schedule::command(...)->everyMinute()` in
`routes/console.php`, run by the `dibs-scheduler` container's `schedule:work` daemon) loops
internally for up to 55 seconds between passes (5s apart) rather than draining once and exiting,
so delivery is closer to real-time than a bare once-a-minute tick would allow, while still
leaving room before the next scheduled tick.

**Operational note:** the long-lived `schedule:work` process can silently stop matching its own
`everyMinute()` schedule (observed 2026-09-14 — its internal due-check disagreed with a fresh
process's for ~2.5 hours; root cause not yet identified). Symptom: the
push-queue page shows items stuck `pending` with 0 attempts well past a minute. `docker compose
logs scheduler` showing repeated "No scheduled commands are ready to run" while `php artisan
schedule:list`/`schedule:run` correctly see the job as due confirms it; `docker compose restart
scheduler` is the known recovery.

A push-time conflict (GitHub edited directly, or by another instance, since the local record
was last pushed) does not block or roll back the local write — it marks the row
`needs_attention` for human review. The push-queue UI (`/push-queue`, gated by
`config('dibs.push_queue_ui_enabled')`) shows pending/failed/needs-attention/pushed counts and
per-item detail; `DescribeGitHubPushQueue::counts()['actionable']` is `failed + needs_attention`
— the count that actually needs a human, as opposed to `pending`, which is just queue depth.

Six MCP write Actions with a single-attempt `DB::transaction()` were found racing the
scheduler's own writes to the same SQLite file (2026-09-14): `CompleteTodoTask`,
`ClaimTaskForAgent`, `ReviseTodoIssue`, `ReviseTodoComment`, `CreateTodoComment`, and
`CreateTodoIssue` now all pass `attempts: 3`, since Laravel's `DB::transaction()` already
retries automatically on a `"database is locked"` SQLSTATE and just wasn't configured to.

## Agents and claims

The full MCP tool surface, the CLI fallback, and the claim/heartbeat/release/complete lifecycle
are documented in [`agent-interface.md`](agent-interface.md) — not duplicated here to avoid the
two documents drifting apart.

## Testing

Pest, run through `composer pint` → `composer phpstan` → `composer rector` (dry-run) →
`composer pest`, in that order so style/static-analysis issues don't get mixed into a
test-failure investigation. `Http::fake()` and `Http::preventStrayRequests()` (set globally in
`tests/Pest.php`) block real network calls. Feature tests run against an in-memory SQLite
database wrapped in `RefreshDatabase` (`tests/Pest.php`) — which means a real SQLite
file-locking/concurrency scenario (like the scheduler race above) cannot be exercised as a
Feature test: `RefreshDatabase`'s own wrapping transaction makes any `DB::transaction()` call
under test a *nested* transaction, and Laravel deliberately refuses to retry a nested
transaction (see `ManagesTransactions::handleTransactionException`), converting it straight to
a `DeadlockException` instead. That class of behavior is verified by reasoning about the code
and Laravel's own upstream test coverage, not by a Dibs-level regression test.

Cover sad paths explicitly: validation failures, stale-revision conflicts, claim conflicts,
dead-process claim cleanup, and push-queue failures — not just the happy path.

## Not yet built

- The natural-language capture UI itself (bridge mechanism only — see `capture-bridge.md`)
- Scheduling fields beyond Planned/Due, recurrence, and any calendar/notification integration
- Multiple configurable workspaces (repo + user) per Dibs instance — currently one instance
  targets one configured `DIBS_GITHUB_OWNER`/`DIBS_GITHUB_REPO`
