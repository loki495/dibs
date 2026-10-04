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

**Local-only mode.** Mirroring is optional: it is on only when both `DIBS_GITHUB_OWNER` and
`DIBS_GITHUB_REPO` are set (`App\Support\GitHubMirror::enabled()`). With either blank, the instance
is local-only. `ResolveActiveRepository` (the one place `CreateTodoIssue`, `UpdateTodoIssue` and
`CreateLabel` get "the repository" from) returns a single local `repositories` row
(`is_local = true`, `github_node_id`/`full_name` set to the sentinel `GitHubRepository::LOCAL_IDENTITY`,
`local`, which no real GitHub node id or `owner/name` can equal). It creates the row on first use with
`createOrFirst`, so repeated or concurrent calls always yield one row. `EnqueueGitHubPush` queues
nothing and returns `null`, `todo:push:drain` exits successfully without draining, `SyncGitHub` refuses
with a `GitHubSyncException`, and the UI hides **Refresh from GitHub**. With mirroring configured,
`ResolveActiveRepository` returns the imported row whose `full_name` matches, and refuses with a
`TodoValidationException` until the first import has created it. It also refuses to create a local
row on an instance that already has an imported one (configuration blanked after an import), so
tasks are never split across two repositories.

**Switching to GitHub.** The first `ApplyGitHubSnapshot` on an instance with a local row and no row
for the remote repository's node id adopts the local row: it is updated in place to the remote
identity (`is_local = false`), so every local issue, label and comment stays attached to the same
`repository_id`. Local labels whose names match remote ones are reconciled onto them by the
existing name match. Inside the same transaction, `QueueLocalRecordsForGitHub` then queues, for every
still-unpushed available record, the same pushes the original writes would have queued:
`create_label`, `create_issue`, `add_issue_labels`, `set_issue_parent` (only to a parent that is
itself queued), `close_issue` with its `stateReason`, and `create_comment`. A failed import rolls
back with the rest of the snapshot, so the instance stays local-only. Later imports find the row by
node id and never requeue anything. The import's "retire records GitHub no longer has" updates skip
rows with a null `github_node_id`, because a `whereNotIn` over an empty list would otherwise match
every row, including unpushed local-first records, when the remote repository has none of that kind
yet.

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
and gotten a real node ID back. `repositories.is_local` marks the local-only repository row (see
"Local-only mode" above). It is a plain added column rather than a nullable `github_node_id`,
because changing a column makes SQLite rebuild `repositories`, which `issues` and `labels`
reference with `ON DELETE CASCADE`. An issue can belong to multiple GitHub Projects even though the
normal convention is one primary Project; unexpected multiple memberships are preserved, not
collapsed. Status/Group/Priority/Planned/Due/Repeat live on `project_items`, not `issues`,
because those fields are Project-specific. `project_fields.semantic_key` maps a Project's own
field IDs to the meanings the app understands (status/group/priority/planned/due/repeat) so a
renamed GitHub field doesn't silently break the mapping.

`comments.kind` is nullable and only ever `null` or `Comment::KIND_CLOSING`; a closing-note comment is
an ordinary comment for GitHub's purposes (the `kind` column is local-only bookkeeping so the app can
find and highlight it). `CloseTodoIssue` creates one via `CreateClosingComment` when an issue is closed
with a note, never for an issue that was already closed. `comments.references` (nullable JSON, a list
of strings such as commit or PR references) is local-only structured data for the UI/`todo_show` to
render next to the note — it is not folded into the GitHub-pushed comment body.

`issues.state_reason` (`CloseTodoIssue::REASONS`: `COMPLETED`, `NOT_PLANNED`, GitHub's own
`IssueClosedStateReason` values) is set locally on close and sent to GitHub as the `close_issue` push's
`stateReason` mutation variable; GitHub's echoed value then overwrites it, same as every other push
confirmation.

`issues.closed_at` is stamped by `CloseTodoIssue` (kept if closed again), cleared on reopen, and
overwritten by GitHub's own `closedAt` from the close confirmation and from every sync (the snapshot
query fetches it). The task list shows "Closed <date>" for a closed row (`closed_at`, falling back to
`remote_updated_at` for issues closed before the column existed) and "Modified <date>"
(`remote_updated_at`, falling back to `updated_at`) for an open one, in `config('dibs.timezone')`;
`updated_at` alone isn't used because every sync bumps it. The Deleted view shows when each task was
deleted.

Reopening (`ReopenTodoIssue`, the workspace detail panel's Reopen icon and the Reopen button beside Add comment, `todo_reopen`) sets `state` back to
`OPEN` and clears `state_reason`, and enqueues a `reopen_issue` push (GitHub's `reopenIssue` mutation).
Nothing is deleted: earlier closing comments stay in the thread, and the highlighted closing block
disappears because `closing` is only derived while the issue is `CLOSED`.

The workspace detail panel (`resources/views/partials/issue-detail.blade.php`) surfaces the current
close as a highlighted block above the comment thread — reason, rendered note, and reference chips —
built by `GetIssueDetails`'s own `closing` derivation (the same shape as `DescribeTodoIssue`'s, kept
separate since one renders markdown/formats dates for the UI and the other returns raw ISO8601 for
MCP). The comment box's red "Close" button (open issues only, with a confirm) calls
`closeWithComment(reason)` on the workspace component with reason `COMPLETED`, using the same
textarea as an ordinary comment for the note (`NOT_PLANNED` remains supported by the method and by
`todo_complete`, but no button offers it); the header "Mark done" icon still closes with no reason
or note. On a closed task the same spot shows "Reopen". The
current closing comment is excluded from the plain thread list so it isn't shown twice; an earlier
closing comment from a prior close/reopen cycle still appears there as ordinary history.

Label names are stored lowercase with single spaces (`App\Support\LabelName`). The import lowercases a remote label and queues a `rename_label` push so GitHub converges on the local spelling; `php artisan labels:normalize` (dry run unless `--apply`) does the same for labels already stored with capitals. `CreateLabel` is the standalone "new label, not attached to any task" write the Manage labels popup uses — unlike `ResolveLabels` (which reuses an existing same-named label when resolving an issue's own labels), a duplicate name here is a validation error, since the point of this one is a brand new label.

**`sync_states`** — one row per mirrored resource, tracking last successful/attempted sync and
the last error, read by the manual-pull path only (no longer drives any scheduled behavior).

**`github_push_queue`** — the durable outbound queue described below: `operation`, `target_type`
+ `target_id`, a JSON `payload`, `status` (pending/failed/needs_attention/pushed), `attempts`,
`last_error`, `next_attempt_at` (when a backing-off row is next due), and a unique `idempotency_key`.

**`agent_sessions` / `task_claims`** — local worker identity and expiring task claims. A claim
binds to the caller's real OS process (host, pid, process start time verified via
`/proc/<pid>/stat`) rather than a self-reported session string, plus a server-issued capability
token (`capability_token_hash`, SHA-256; the plaintext is returned once, at claim time, and
never stored). `is_verified_live` is false when the PID/start-time couldn't be independently
confirmed (cross-namespace caller, unreadable `/proc`) — the claim still succeeds but is flagged
as weaker assurance rather than silently trusted. Full contract in `agent-interface.md`.

**`mcp_write_receipts`** — backs the idempotency-key mechanism every MCP write tool can use
(`ResolveIdempotentWrite`): a repeated call with the same key returns the original result
instead of repeating the write. The receipt lookup, the write and the receipt insert run in one
`DB::transaction` (the write Action's own transaction becomes a savepoint), so a write never
commits without its receipt and a failure leaves neither. Two concurrent calls with the same key
can't both commit: the loser hits the unique `idempotency_key` (or SQLite's "database is locked",
which the transaction retries), rolls back its write and returns the winner's result. A key
already used for a different kind of write (`subject_type`, e.g. a comment key reused for a
create) is rejected with a `TodoValidationException`.

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
confirmed result, then calls `EnqueueGitHubPush` to durably record the outbound operation (a
no-op on a local-only instance). No Action calls the GitHub API inline.

`DrainGitHubPushQueue` (`app/Actions/DrainGitHubPushQueue.php`) delivers queued rows to GitHub
independently of the write that created them, one GraphQL/REST call per row, always *outside*
any DB transaction (a network call never holds a SQLite write lock). Operations run in a fixed
dependency order (`OPERATION_ORDER`), not insertion order — an issue must exist on GitHub
before its Project membership can, for example — and a handler whose dependency hasn't pushed
yet returns `waiting` and is retried on the next pass rather than failing.

**A push handler must never treat "the local field already matches the target value" as proof
the mutation already reached GitHub.** Because the originating write Action always applies its
target state locally *before* enqueueing the push, that local state is the target value on
every single real attempt, including the very first — it can never distinguish "already pushed"
from "just applied locally, not yet pushed." Two handlers did this wrong until 2026-09-22
(`pushCloseIssue` checking local `state === 'CLOSED'`, `pushClearProjectItemField` checking the
local group/priority column `=== null`) and, as a result, silently never called their mutation
for any real close or Group/Priority clear performed through the app. The only reliable signal
a push handler may use is a value the mutation's own response sets (`github_node_id`, etc.) —
GitHub's own mutations are idempotent, so calling one again for a state GitHub already has is a
harmless no-op, which is the correct way to handle "maybe already pushed" rather than guessing
from local state.

`php artisan todo:push:drain` runs a single pass and exits, registered
`Schedule::command(...)->everyTenSeconds()->withoutOverlapping(1)` in `routes/console.php` (run
by the `dibs-scheduler` container's `schedule:work` daemon, which supports sub-minute frequencies
natively — no extra infra needed). A single quick pass fired every ten seconds gives near-real-time
delivery without the bookkeeping a long-lived internal loop would need, and keeps the window a
container restart could catch mid-run down to however long one pass's GitHub calls take rather
than up to a minute.

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

A failed push is retried only when a later attempt could succeed. `GitHubClient` classifies each
failure on the `GitHubSyncException` it throws: a rate limit carries the `retryAt` GitHub gave
(`Retry-After`, else `X-RateLimit-Reset` when `X-RateLimit-Remaining` is `0`, else a minute for a
secondary limit with no time), for a 403/429 or a GraphQL `RATE_LIMITED` error; network failures,
5xx, unconfirmed responses and untyped GraphQL errors are transient; other 4xx and GraphQL
`NOT_FOUND`/`FORBIDDEN`/`INSUFFICIENT_SCOPES`/`UNPROCESSABLE` errors are permanent. The drain
sends a permanent failure straight to `needs_attention`. A transient one stays `pending` with
`next_attempt_at` set to GitHub's `retryAt` or an exponential backoff (`backoff_base_seconds *
2^(failures - 1)`, capped at `backoff_cap_seconds`), and the drain skips rows that aren't due. A
rate limit also ends the pass and holds every other queued row until the same time without
spending their attempts, since the limit is per token. A row needs attention once
`max_attempts` calls have failed (`config('dibs.push_queue')`, defaults 8 / 30 s / 1 h, about two
hours in all). The push-queue page shows "retrying at …" under a backing-off row's status, and a
manual Retry clears the wait.

Six MCP write Actions with a single-attempt `DB::transaction()` were found racing the
scheduler's own writes to the same SQLite file (2026-09-14): `CompleteTodoTask`,
`ClaimTaskForAgent`, `ReviseTodoIssue`, `ReviseTodoComment`, `CreateTodoComment`, and
`CreateTodoIssue` now all pass `attempts: 3`, since Laravel's `DB::transaction()` already
retries automatically on a `"database is locked"` SQLSTATE and just wasn't configured to.

## Agents and claims

The full MCP tool surface, the CLI fallback, and the claim/heartbeat/release/complete lifecycle
are documented in [`agent-interface.md`](agent-interface.md) — not duplicated here to avoid the
two documents drifting apart.

## Activity log

Two tables record what happened, linked by a shared `request_id`: `mcp_call_logs` (every MCP tool
call, reads included, with redacted and truncated arguments, status, duration and agent label) and
`change_logs` (Action-level data changes with a field-level `changes` diff, plus source, actor and
category). Write Actions record their own entries through `ActivityRecorder`; there are no model
observers. `ActivityContext` (request-scoped) supplies the actor, source and `request_id` for a web
request, an MCP call, a console command or a scheduler run. Values are redacted by key pattern
(`token`, `secret`, `password`, `authorization`, `key`) and truncated before they are stored, so a
`capabilityToken` never reaches either table.

Retention is per log, in days, from `DIBS_ACTIVITY_MCP_RETENTION_DAYS` and
`DIBS_ACTIVITY_CHANGE_RETENTION_DAYS` (default 30; `0` keeps that log forever). `activity:prune`
(a thin command over the `PruneActivityLog` Action) applies them and is scheduled daily; a row
exactly at the cutoff is kept. `ClearActivityLog` empties one log on request and records a single
`ClearActivityLog` row in the change log (who cleared, how many) after deleting, so the audit row
survives; clearing an already-empty log is a no-op.

The `/activity` page (`pages::activity`, behind `auth`, linked from the settings menu and the workspace
sidebar) is a Livewire page that reads through `ListActivityLog` (newest first, paginated, filtered by
date range, tool/action, status, category, source, request id and free text), `DescribeActivityFilterOptions`
(the dropdown values) and `CountRelatedActivity` (the link between an MCP call and the changes sharing its
`request_id`), and clears through `ClearActivityLog`; it never writes itself. Date filters are inclusive
days in `DIBS_TIMEZONE` (rows are stored in UTC), a date that doesn't parse is ignored and reported as a
validation error, and the active log name is a locked Livewire property so a tampered request can't choose
which log Clear empties. Following a request link pushes the current log, filters, page and open row onto a
locked history, which the Back button pops, so a drill-down across both logs can be walked back one hop at a time.

The Manage labels popup is its own Livewire component (`resources/views/livewire/manage-labels.blade.php`)
rendered once by the layout for signed-in users, so the gear menu's link works on every page (push queue,
activity), not only the workspace. It listens for `open-manage-labels` (dispatched by the gear menu and
the workspace sidebar link) and announces `labels-changed` and `label-deleted`; the workspace listens for
those to re-render and to drop a deleted label from its filter and pending selections.

Popups (Flux `<dialog>`s) stay inside the visible area on a notched phone: `resources/css/app.css` exposes
the safe-area insets as `--safe-top`/`--safe-bottom` and bounds plain dialogs to the viewport minus them,
scrolling inside, while `scroll="body"` dialogs are padded by them. Without it a tall popup started under
the iOS status bar (the app runs edge-to-edge with `viewport-fit=cover`). Overriding the two variables
simulates a notch in a desktop browser; `tests/Browser/ModalSafeAreaTest.php` does exactly that.

## Loading indicator

`resources/js/loading-indicator.js` gives one indicator for every Livewire request and every full-page
link navigation (which the installed iOS app otherwise shows no progress for): after
`dibs.loading_indicator_delay_ms` (`DIBS_LOADING_INDICATOR_DELAY_MS`, default 150, so fast requests never
flicker) a thin teal bar shows at the top and the lists marked `data-busy` (the task list, deleted list,
push queue table, activity list) dim and get `aria-busy`. The bar is a `popover="manual"` element so it
renders in the top layer above open popups, and sits below the status bar via `--safe-top`. It counts
requests in flight, clears when the last one finishes, fails or is cancelled, and resets when a page is
restored from the back/forward cache. Covered by `tests/Feature/Workspace/LoadingIndicatorMarkupTest.php`
and `tests/Browser/RequestFeedbackTest.php` (slow, quick and failing requests).

Filter chips, area pills, the sidebar area buttons and the Tasks/Knowledge/Deleted tabs look selected the
instant they are tapped instead of after the round trip. An element opts in with `data-optimistic`
(`teal`, `tealfill`, `slate` or `segment`, matching the classes it already uses for its real state) plus
`aria-pressed` for that real state, `data-optimistic-mode="toggle"` for a chip that flips on and off, and
`data-optimistic-group` on the container of an exclusive set. `resources/js/optimistic.js` only marks the
tapped element `data-pending`; `app.css` restyles it (and un-highlights the previously selected one in the
group) with `!important` rules, so the normal `wire:click` and the server stay the source of truth. The marks
clear on the `dibs:idle` event the loading indicator fires when the last request finishes, so a failed request
leaves the real unchanged state showing. Native selects and the search box are already immediate. Covered by
the browser tests in `tests/Browser/RequestFeedbackTest.php` (toggle on and off, failure, phone pills).

## Client-side request failures

Livewire 4 shows nothing when a request fails at the network level (only HTTP errors get its modal)
and has no request timeout, so one lost or hung response left taps changing the URL while the page
never re-rendered, and every later action queued silently behind the stuck request. It looked like a
frozen page that "fixed itself" on refresh, typically on a phone whose connection dropped or took the
Cloudflare path. `resources/js/connection-watchdog.js` hooks `Livewire.interceptRequest`: a failed
request, or one still pending after `dibs.livewire_request_timeout_seconds` (`DIBS_LIVEWIRE_REQUEST_TIMEOUT`,
default 20, `0` disables only the timeout), shows the `[data-connection-banner]` in
`layouts/app.blade.php` ("Lost contact with the server, so your last change may not have been saved"
with a Reload button); a timed-out request is cancelled so the queue drains. The next successful
request hides the banner again. Requests Livewire cancels itself, and failures while the page is
unloading, don't show it. The timeout reaches the script through a `dibs-request-timeout` meta tag.
Covered by `tests/Feature/Workspace/ConnectionBannerMarkupTest.php` and the real-browser
`tests/Browser/ConnectionBannerTest.php`, which replace `fetch` to simulate a lost and a hung response.

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

Browser tests (`composer pest:browser`, `tests/Browser/`) run in the `app-test` container, whose image
bakes Chromium for the Playwright version pinned in `docker/setup-test-container.sh`; that pin must move
together with `package.json` (a Dependabot bump that skipped it broke every browser test with "Playwright
is outdated" until the image was rebuilt).

Cover sad paths explicitly: validation failures, stale-revision conflicts, claim conflicts,
dead-process claim cleanup, and push-queue failures — not just the happy path.

## Not yet built

- The natural-language capture UI itself (bridge mechanism only — see `capture-bridge.md`)
- The rest of the activity log: change recording for the remaining write Actions, sign-in /
  GitHub pull / push-queue drain events, and the architecture guard that requires every write
  Action to record or be allowlisted (see the activity-log plan in Dibs)
- Scheduling fields beyond Planned/Due, recurrence, and any calendar/notification integration
- Multiple configurable workspaces (repo + user) per Dibs instance — currently one instance
  targets one configured `DIBS_GITHUB_OWNER`/`DIBS_GITHUB_REPO`, or none (local-only)
- Areas, Groups and Priority on a local-only instance (they come only from GitHub Projects), and
  returning an imported instance to local-only
