# Dibs — project context and conventions

## What this is

Dibs is a self-hosted personal task and knowledge tracker, backed by GitHub Issues and Projects as an asynchronous mirror. Local SQLite is authoritative once imported — GitHub is a mostly-read-only mirror reached through a durable outbound push queue, not a live sync source. It's built MCP-native: a host-local stdio MCP server exposes the same task/plan/claim operations to AI agents that the web UI uses, with a claim/heartbeat/release/complete lifecycle so multiple agents (or the same agent across sessions) can coordinate on shared work without duplicating or conflicting. Use it solo as a todo list, or let agents work alongside you.

## Stack

Laravel 13, Livewire 4 class-based single-file components, PHP 8.5, SQLite, Tailwind 4, Flux UI. Docker Compose for local development — PHP/Composer commands run inside the app container; Node builds run in a separate service. See `README.md` for local setup and `docker/setup.sh`.

## Architecture

- Local SQLite is authoritative for issues, comments, labels, native parents, Project membership, Group, Priority, plans, tasks, and knowledge records.
- GitHub Issues/Projects are an asynchronous, mostly-read-only mirror reached through a durable outbound push queue — no inbound webhooks, no scheduled freshness polling. Manual pull (`scripts/github-pull`, `todo:sync`) remains for initial setup, disaster recovery, and syncing a second instance; it must not overwrite unpushed local changes.
- Livewire components and Artisan commands delegate business logic to typed Actions, shared by the UI and the MCP/CLI agent surface. Actions write SQLite directly as the confirmed result and enqueue a GitHub push rather than calling the GitHub API inline.
- A push-queue conflict (GitHub edited directly, or by another instance, since the local record was last pushed) does not block or roll back the local write — it marks the row `needs_attention` for human review rather than silently overwriting or discarding either side.
- See `docs/architecture.md` for the schema and full design history, and `docs/agent-interface.md` for the complete MCP/CLI contract.

## Installable as a PWA

`resources/views/layouts/app.blade.php` ships a manifest link (served dynamically at `/manifest.webmanifest`, `routes/web.php`, so `name`/`short_name` reflect `config('app.name')` per deployment — e.g. the personal instance vs. the public "Dibs Demo") plus the iOS meta tags (`apple-mobile-web-app-capable`, `apple-touch-icon`, etc.) needed for "Add to Home Screen" to launch full-screen instead of as a bookmarked browser tab. This is presentation only — it does not add offline capability; every interaction is still a Livewire round-trip to the server, unaffected by how the page was launched. Icon assets live under `public/app-icons/`, not `public/icons/` — Apache ships a built-in `Alias /icons/` (its default autoindex folder icons) that silently 404s anything placed at that exact path regardless of what's actually on disk there.

## Local agent interface (MCP)

The canonical agent interface is a host-local stdio MCP server (`App\Mcp\Servers\TodoServer`, started with `php artisan mcp:start todo`). It exposes 15 tools — context/read (`todo_status`, `todo_context`, `todo_list`, `todo_show`, `todo_queue_status`), create/revise/comment (`todo_create`, `todo_scaffold_plan`, `todo_revise`, `todo_comment`), the full claim lifecycle (`todo_claim`, `todo_heartbeat`, `todo_release`, `todo_complete`, `todo_claim_status`), and a tooling self-report tool (`todo_report_bug`) — all delegating to the same typed Actions the web UI uses. Every write tool validates its own arguments explicitly and implements `Laravel\Mcp\Server\Contracts\Errable`: `laravel/mcp` does not enforce a tool's declared JSON Schema before invoking its handler, so schema-shaped input alone is not a safety guarantee.

`todo_claim`/`todo_heartbeat`/`todo_release`/`todo_complete` identify the calling process via `posix_getppid()` internally rather than a caller-supplied `pid` argument — a stdio MCP server's own parent process *is* the connecting agent, so no cooperation from the client is needed. `php artisan todo:agent:*` commands are the JSON CLI fallback for recovery and smoke-testing (using an explicit `--pid=`, since each CLI invocation is its own short-lived process), backed by the same shared Actions. Never pass a GitHub credential through a tool argument or output — the server reads `GITHUB_TOKEN` server-side only, when the push-queue worker needs it.

## Claims and coordination

A live claim binds to the caller's real OS process (host, pid, process start time verified via `/proc/<pid>/stat`), not a self-reported session string, plus a server-issued capability token required for every subsequent heartbeat/release/complete on that claim. A PID that's since been reused by an unrelated process cannot keep an old claim valid; a claim whose process is confirmed dead becomes automatically recoverable by the next claim attempt. This contract is scoped to one local database — it does not arbitrate ownership across independent databases or users; multi-instance/multi-user collaboration is an intentionally open future direction, not yet designed. See `docs/agent-interface.md` for the full model, including the workspace UI's claim/plan visibility (live claim ownership, a human-authorized "Release claim" override, and parent/child/knowledge navigation).

## Git workflow

`main` tracks the remote. Keep commits feature-scoped; use separate commits for distinct features or fixes rather than one large commit. Run the full verification suite (below) before committing.

`main` has branch protection: a required status check (`Pint, PHPStan, Rector, Pest`, the CI `quality` job) plus `enforce_admins`, but no required PR review — direct pushes are meant to stay allowed. GitHub still enforces the check on a direct push, though, by requiring the exact commit SHA to already have a passing check run, which a brand-new local commit never has yet — expect a `GH006` rejection. See the `git-workflow` skill's "Required status check blocks a direct push" section for the fix (push to a throwaway branch, open a PR, wait for the check, `gh pr merge --rebase`, then sync local `main`).

## Public demo hosting

A public demo instance runs at `dibs-demo.ac495.net` (per-visitor SQLite database isolation, not shared state) — see `docs/demo-hosting.md` for the full architecture, deploy steps, and CD pipeline (push to `main` → GHCR publish → Watchtower redeploy on `media`). `config('dibs.demo_mode')` defaults to `false` and every piece of this is a no-op on a normal install; `database/seeders/DemoSeeder.php` is what the public demo actually shows visitors.

## Testing and tooling

Run PHP tooling inside the app container via the composer script wrappers rather than invoking Docker directly:

```bash
composer pint      # Pint (auto-fix)
composer phpstan   # PHPStan level 6 (Larastan)
composer rector     # Rector, dry-run by default — review its diff before ever applying
composer pest       # Pest test suite
composer artisan    # any artisan command, e.g. `composer artisan -- migrate`
```

Order matters: Pint → PHPStan → Rector (dry-run) → Pest, so style/static-analysis issues don't get mixed into a test-failure investigation.

Prefer TDD with Pest for non-obvious behavior: write a focused failing test first, confirm it fails for the intended reason, implement the smallest correct change, then refactor with tests passing. Fake GitHub requests in tests (`Http::fake()`); never let a test make a real network call. Cover sad paths explicitly — validation failures, stale writes, claim conflicts, push-queue failures, dead-process cleanup — not just the happy path. Never delete, weaken, or skip a failing test to force a passing state.

**Write-tool testing policy:** verify a write tool (create/revise/comment/claim/heartbeat/release/complete) through the isolated Pest suite (`TodoServer::tool(...)->assertOk()`) or an isolated scratch database — never against a real personal dataset — unless explicitly asked to demonstrate one live.

## Labels

The app ships with an example labels scheme: workflow markers (`today`, `next`, `waiting`, `someday`, `recurring`, `needs research`), structural markers (`parent`, `guide`), content markers (`bug`, `documentation`, `research`, `lesson`, `decision`), and `agent-report` for tooling problems agents self-report via `todo_report_bug`. Adjust to taste for your own use — labels are lightweight, cross-cutting metadata, not a replacement for Projects/Groups/parents, and shouldn't duplicate them.

## Knowledge records

`research`, `lesson`, `decision`, and `guide` labels mark a completed knowledge *record* — the issue body itself is the maintained finding — rather than an ordinary task; the workspace's Tasks/Knowledge toggle treats any of these four labels as Knowledge, hiding the issue from the default Tasks view. Don't confuse this with a task that merely needs research done before it can proceed — use a distinct workflow label (e.g. `needs research`) for that; applying a knowledge label to an ordinary task will make it disappear from the Tasks view.

## Safety and verification

- Never push without confirming the exact remote and branch first. Never force-push or rewrite shared history without explicit authorization.
- Before changing external GitHub state, verify the configured owner/repository and inspect live data; preserve user edits, task identities, parent links, and schedule values.
- Never weaken or bypass a test to make it pass.
- Keep secrets out of issues, logs, browser bundles, and committed configuration. `GITHUB_TOKEN` is read server-side only and never appears in a tool argument, MCP response, or browser bundle.
