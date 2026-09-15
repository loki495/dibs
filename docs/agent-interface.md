# MCP agent interface

Status: the host-local stdio MCP server, its full read/write/claim tool surface, the workspace UI's claim/plan visibility, and end-to-end verification are complete. The documented Artisan CLI fallback is implemented. Local SQLite is authoritative for issues, comments, labels, native parents, Project membership, Group, Priority, plans, tasks, and knowledge records. GitHub is an asynchronous, mostly-read-only mirror reached through a durable push queue — there is no inbound webhook receiver and no scheduled freshness polling.

## Server foundation

`laravel/mcp` is pinned to `1.0.0-beta.1`, not the stable `0.9.x` line composer would otherwise resolve. Confirmed by reading the package's own release notes and source: the stable line still implements the old stateful protocol (an `initialize` handshake, server-side session state), while only `1.0.0-beta.1` (per its changelog: "Serve only MCP 2026-07-28 and drop the initialize handshake") targets the stateless 2026-07-28 revision this project's entire claim/capability-token design assumes. Building the server foundation against the stable line would have meant implementing the wrong protocol version. Also confirmed while reading the package source: `laravel/mcp` has no dependency on the separate official `modelcontextprotocol/php-sdk` — it implements the protocol independently.

- Server: `App\Mcp\Servers\TodoServer`, registered in `routes/ai.php` via `Mcp::local('todo', TodoServer::class)`. Start it with `php artisan mcp:start todo`.
- `todo_status` (`App\Mcp\Tools\DescribeTodoServer`, backed by the `App\Actions\DescribeTodoServer` Action) is the health/metadata tool: app name/environment, configured GitHub owner/repository, whether it's been imported locally, and issue/label/project counts. Never returns `GITHUB_TOKEN` or any other secret.
- Verified end to end against the real stdio process (`php artisan mcp:start todo` piped raw JSON-RPC): a `tools/call` for `todo_status` returns real data in one clean JSON-RPC line with empty stderr; `tools/list` correctly lists it; an unknown method, a request missing the required `_meta` protocol-version member, and malformed JSON each return a structured JSON-RPC error (`-32601`, `-32602`, `-32700`) without crashing the process or corrupting the stream.
- **Known gap:** `laravel/mcp` `1.0.0-beta.1` has no `notifications/cancelled` handling at all — a cancellation notification is silently dropped as an unrecognized notification (harmless, but not real cancellation). Acceptable for now since every current tool handler is a short synchronous DB read; revisit if a long-running tool is ever added.
- **Backward compatibility:** `laravel/mcp`'s server role only speaks the stateless 2026-07-28 protocol and hard-rejects any `initialize` handshake — this broke real MCP clients (confirmed against Claude Code's and opencode's own clients) that haven't caught up to the new spec yet. `TodoServer` (`App\Mcp\Servers\TodoServer`) registers a legacy `initialize` handler and relaxes its `_meta` requirement once such a client is detected, scoped to that one connection's process lifetime; new-style clients sending `_meta` are unaffected. Covered by `tests/Feature/Mcp/TodoServerLegacyInitializeTest.php`.

## Boundary

Agents run on the host and connect to a stdio MCP server. The server exposes Dibs tools and delegates to the same application Actions as the workspace. Those Actions write SQLite directly as the confirmed result and enqueue a GitHub push rather than calling the GitHub API inline. `php artisan todo:agent:*` is retained as a local recovery and smoke-test fallback. There is no unauthenticated HTTP API and no GitHub credential in an agent prompt, browser bundle, or command argument. The MCP server and CLI read the server-side `GITHUB_TOKEN` only when the push-queue worker needs it to reach GitHub.

Implemented commands: `list`, `show`, `claim`, `heartbeat`, `release`, `create`, `update`, `comment`, and `complete` — all follow the same contract.

The command surface is:

- `todo:agent:list` — read current tasks, parent context, Group, Project, labels, Priority, and local push-queue state from SQLite.
- `todo:agent:show ISSUE` — read one task with description, comments, hierarchy, and relevant Project fields.
- `todo:agent:claim ISSUE --agent=NAME --pid=PID [--minutes=30]` — claim a task for the calling agent's own OS process. Returns a `capability_token` in the response **once, in plaintext** — the caller must hold onto it; it's required for every subsequent `heartbeat`/`release` on that claim and is never shown again (only its hash is stored). `--pid` must be the calling agent's own real process ID — the server independently verifies it via `/proc` rather than trusting it blindly (see "Claims and checkpoints" below).
- `todo:agent:heartbeat ISSUE --pid=PID --token=TOKEN [--minutes=30]` — renew a live claim's lease. Re-verifies the process is still alive on every call; a claim cannot renew itself back to life once its process is confirmed dead.
- `todo:agent:release ISSUE --pid=PID --token=TOKEN` — release a claim. Requires the exact capability token and pid returned by `claim`; nothing else can release another worker's claim.
- `todo:agent:create TITLE [--body=] [--area=] [--parent=] [--group=] [--priority=] [--label=]* [--new-group=] [--new-label=] [--idempotency-key=]` — create locally with Project, Group, parent, labels, and Priority; enqueues the GitHub push.
- `todo:agent:update ISSUE --expected-revision= [--title=] [--body=] [--note=] [--idempotency-key=]` — title/body patches only (no metadata/Priority fields yet); requires the `revision` last read for the issue and returns a non-error `{"conflict": true, "current": ...}` payload instead of failing on a stale revision. Writes SQLite and enqueues the push.
- `todo:agent:comment [ISSUE] --body= [--comment-id= --expected-revision=] [--idempotency-key=]` — add a comment (`ISSUE` required) or edit an existing one (`--comment-id`/`--expected-revision` required instead); same non-error stale-revision conflict payload as `update`. Writes SQLite and enqueues the corresponding GitHub push.
- `todo:agent:complete ISSUE --pid=PID --token=TOKEN [--summary=TEXT]` — closes a claimed task, enqueues the GitHub push, optionally posts `--summary` as a result comment, and releases the claim. Claim-scoped like `release`/`heartbeat`: only the exact process holding the live claim can complete it.

Machine-readable JSON is the default output. Human output is opt-in. Commands reject malformed IDs, unavailable local records, and wrong Project-field options before any write.

## MCP tools

Registered on `App\Mcp\Servers\TodoServer`, in this order:

| Tool | Purpose |
|---|---|
| `todo_status` | Server/repository identity and record counts — call first to confirm identity. |
| `todo_context` | Areas (Projects), Groups, labels, live claims, and push-queue counts — orientation before acting. |
| `todo_list` | Filtered/paginated task or knowledge listing (area/group/label/parent/state/search/view). |
| `todo_show` | Full detail for one issue, optionally with paginated comments. |
| `todo_queue_status` | Pending/failed/needs-attention push-queue counts and per-item detail. |
| `todo_create` | Create a task, plan, or knowledge record; enqueues the GitHub push. Idempotency-key supported. |
| `todo_scaffold_plan` | Create a parent plan issue plus its child tasks atomically, in one transaction. `groupId`/`priorityId` set the plan issue's own Group/Priority, independently of each child's own. |
| `todo_revise` | Revise an issue's title/body/note/Group with optimistic-concurrency (`revision`) protection; a stale write returns a non-error `{conflict: true, current: ...}` rather than erroring. `groupId` moves the issue to a different Group within its existing area (the issue must already belong to one) — it does not yet support clearing the Group back to none. |
| `todo_comment` | Add a comment, or edit one (`commentId` + `expectedRevision`) with the same stale-conflict shape as `todo_revise`. |
| `todo_claim` | Claim a task for the calling process. Returns a `capabilityToken` once, in plaintext. |
| `todo_heartbeat` | Renew the calling process's own live claim lease. Creates no GitHub comment or push-queue entry. |
| `todo_release` | Release the calling process's own live claim without completing the task. |
| `todo_complete` | Close a claimed task, enqueue the push, optionally post a result summary comment, and release the claim. |
| `todo_claim_status` | Read-only: whether a task has a live claim, and by whom — no capability token exposed. |
| `todo_report_bug` | Self-report a problem with the MCP/CLI tooling itself (not a product task) — creates an `agent-report`-labeled issue; amend with `todo_comment`. |

Every write tool implements `Laravel\Mcp\Server\Contracts\Errable` and validates its own arguments via `Request::validate()` — confirmed empirically that `laravel/mcp` `1.0.0-beta.1` does not enforce a tool's declared JSON Schema before calling `handle()`, so schema-shaped input alone is not a safety guarantee.

Note on `pid`: the MCP protocol itself has no session/process identity a server can read from a tool call — a stdio server's own parent process *is* the connecting client, so `todo_claim`/`todo_heartbeat`/`todo_release`/`todo_complete` read it directly via `posix_getppid()` and never accept it as a tool argument. The CLI fallback has no equivalent (each Artisan invocation is its own short-lived process, not the long-running agent), so it requires an explicit `--pid=` naming the calling agent's own process — the shared Actions underneath verify whichever PID they're given the same way regardless of which surface it came from.

Every tool returns structured JSON with stable local IDs, GitHub URLs when pushed, and push-queue status when relevant. Stdio is local-only; no network listener, browser credential, or MCP tool argument contains a GitHub token.

## Picking up work cold

This is the primary way a new session (or a different agent/tool entirely) is meant to start, not a fallback path: call `todo_context` first for orientation (areas, Groups, labels, live claims, push-queue snapshot), then `todo_list` — with no filters for everything open, or `area`/`group`/`parentId` to scope to one project — to see what's actually outstanding. No prior conversation state, plan file, or hand-off note is required; the tools are the hand-off. Filter `todo_list` by `label: "agent-task"` to see only work suited to an agent picking it up unattended (code changes, audits, drafting, research, investigation) and skip tasks that need a human body or a judgment call only a human can make (errands, purchases, in-person chores). A task's absence of `agent-task` isn't a hard block — read it and use judgment — but it's a useful default filter, and an agent creating a task via `todo_create`/`todo_scaffold_plan` should apply the label when the new task fits.

## Claims and checkpoints

The local-only `agent_sessions` and `task_claims` models bind a task to an explicit worker identity with an expiry and renewal time. A claim is not a GitHub lock: it prevents accidental duplicate local-agent work sharing one local database. This contract is explicitly scoped to multiple agents/processes on **one** local database — it does not arbitrate ownership across independent databases or between different users. Multi-user/multi-instance collaboration is intentionally kept open as a future direction, but no distributed conflict-resolution design exists yet. A worker must post concise checkpoint comments for a material result, decision, blocker, or verification result, never raw tool logs.

**Workspace claim/plan visibility:** the issue detail panel shows a live claim's agent name, host, pid, a fresh `isCurrentlyAlive` re-check (via `LinuxProcessLiveness`, distinct from the `isVerifiedLive` flag recorded at claim/heartbeat time), and whether its lease has expired — all from `DescribeTodoClaim`, the same Action `todo_claim_status` uses. A "Release claim" button calls a `ReleaseAbandonedTaskClaim` Action: a human-authorized override with no capability-token check, distinct from the agent-facing `ReleaseTaskClaim` — the workspace is behind login for one operator, who already has full local database access, so this just gives a safe, auditable UI path to recover a claim judged abandoned. The panel also shows parent/child navigation and related knowledge-labeled sibling issues (a knowledge-labeled child is flagged directly in the children list rather than duplicated), and a pending-sync badge linking to a push-queue visibility page rather than duplicating its detail.

**Hardened claim identity:** a claim binds to the caller's real OS process, not a self-reported string. `App\Services\Process\LinuxProcessLiveness` reads `/proc/<pid>/stat` directly to confirm a claimed pid genuinely exists and matches the start time recorded when the claim was made — a PID that's since been reused by an unrelated process cannot keep an old claim (or its heartbeat) valid. This requires the `dibs-app` container to see host PIDs (`pid: "host"` in `docker-compose.yml`), since agents run on the host while the app runs in a container — without that, `/proc` inside the container only reflects the container's own PID namespace and can't verify anything about a host-side process. When liveness genuinely can't be verified (that setting removed, or a container/namespace situation where it doesn't apply), a claim still succeeds but is flagged `is_verified_live: false` — treated as weaker assurance, never auto-reclaimed as "dead," only as expired.

Claims, session metadata, and explicit checkout bindings are local durable data. GitHub no longer holds the sole durable copy of anything, so these still need backup with SQLite same as before.

## Retry and conflict rules

- Reads are always against local SQLite and are retry-safe.
- A write commits to SQLite immediately as the confirmed result and enqueues a push-queue row; the command does not wait on GitHub.
- A push-queue row that fails to apply to GitHub (rejected, conflicting edit, rate-limited) is marked `needs_attention` for human review rather than silently retried into a duplicate or silently discarded.
- Updates patch only requested fields, locally and in the resulting push. A push rejection does not roll back the local write; it surfaces via the queue.
- A command records the agent identity and intent in the local record before a multi-step write.
- The first release has no unattended scheduler that creates, edits, completes, or comments through the MCP surface itself — an agent explicitly invoking a command is the triggering event. The push-queue worker draining to GitHub is a separate, already-authorized background process, not an "unattended agent write."

## Website convention

A website is a Group inside a Project area. Its meaningful parent issue holds repository/path/context links. Work is child issues; granular work is nested children. Lessons, research, and decisions are labeled issues in that Group, with evidence and records in comments. A future `website_bindings` table must use explicit repository paths; names alone never select a checkout.
