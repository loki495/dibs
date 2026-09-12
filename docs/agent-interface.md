# MCP agent interface

Status: the host-local stdio MCP server foundation is implemented (#41); read/write tools beyond the metadata tool are not yet built (#42-#45). The documented Artisan CLI fallback is implemented. Local SQLite is authoritative for issues, comments, labels, native parents, Project membership, Group, Priority, plans, tasks, and knowledge records (see GitHub issue #37, loki495/Todo, for the full architecture record). GitHub is an asynchronous, mostly-read-only mirror reached through a durable push queue (#49) — there is no inbound webhook receiver and no scheduled freshness polling.

## Server foundation (#41, 2026-09-12)

`laravel/mcp` is pinned to `1.0.0-beta.1`, not the stable `0.9.x` line composer would otherwise resolve. Confirmed by reading the package's own release notes and source: the stable line still implements the old stateful protocol (an `initialize` handshake, server-side session state), while only `1.0.0-beta.1` (per its changelog: "Serve only MCP 2026-07-28 and drop the initialize handshake") targets the stateless 2026-07-28 revision that #37/#56's entire claim/capability-token design assumes. Building the server foundation against the stable line would have meant implementing the wrong protocol version. Also confirmed while reading the package source: `laravel/mcp` has no dependency on the separate official `modelcontextprotocol/php-sdk` — it implements the protocol independently, resolving one of #38's open research questions.

- Server: `App\Mcp\Servers\TodoServer`, registered in `routes/ai.php` via `Mcp::local('todo', TodoServer::class)`. Start it with `php artisan mcp:start todo`.
- `todo_status` (`App\Mcp\Tools\DescribeTodoServer`, backed by the `App\Actions\DescribeTodoServer` Action) is the health/metadata tool: app name/environment, configured GitHub owner/repository, whether it's been imported locally, and issue/label/project counts. Never returns `GITHUB_TOKEN` or any other secret.
- Verified end to end against the real stdio process (`php artisan mcp:start todo` piped raw JSON-RPC): a `tools/call` for `todo_status` returns real data in one clean JSON-RPC line with empty stderr; `tools/list` correctly lists it; an unknown method, a request missing the required `_meta` protocol-version member, and malformed JSON each return a structured JSON-RPC error (`-32601`, `-32602`, `-32700`) without crashing the process or corrupting the stream.
- **Known gap:** `laravel/mcp` `1.0.0-beta.1` has no `notifications/cancelled` handling at all — a cancellation notification is silently dropped as an unrecognized notification (harmless, but not real cancellation). Acceptable for now since every current tool handler is a short synchronous DB read; revisit if a long-running tool is ever added (#38).

## Boundary

Agents run on the host and connect to a stdio MCP server. The server exposes Todo tools and delegates to the same application Actions as the workspace. Those Actions write SQLite directly as the confirmed result and enqueue a GitHub push rather than calling the GitHub API inline. `php artisan todo:agent:*` is retained as a local recovery and smoke-test fallback. There is no unauthenticated HTTP API and no GitHub credential in an agent prompt, browser bundle, or command argument. The MCP server and CLI read the server-side `GITHUB_TOKEN` only when the push-queue worker needs it to reach GitHub.

Implemented commands: `list`, `show`, `claim`, `release`, `heartbeat`, `comment`, and `complete`. The remaining write commands follow the same contract.

The command surface is:

- `todo:agent:list` — read current tasks, parent context, Group, Project, labels, Priority, and local push-queue state from SQLite.
- `todo:agent:show ISSUE` — read one task with description, comments, hierarchy, and relevant Project fields.
- `todo:agent:claim ISSUE --agent=NAME --pid=PID [--minutes=30]` — claim a task for the calling agent's own OS process. Returns a `capability_token` in the response **once, in plaintext** — the caller must hold onto it; it's required for every subsequent `heartbeat`/`release` on that claim and is never shown again (only its hash is stored). `--pid` must be the calling agent's own real process ID — the server independently verifies it via `/proc` rather than trusting it blindly (see "Claims and checkpoints" below).
- `todo:agent:heartbeat ISSUE --pid=PID --token=TOKEN [--minutes=30]` — renew a live claim's lease. Re-verifies the process is still alive on every call; a claim cannot renew itself back to life once its process is confirmed dead.
- `todo:agent:release ISSUE --pid=PID --token=TOKEN` — release a claim. Requires the exact capability token and pid returned by `claim`; nothing else can release another worker's claim.
- `todo:agent:create` — create locally with Project, Group, parent, labels, and Priority; enqueues the GitHub push.
- `todo:agent:update ISSUE` — intentional-field patches only; title/body, metadata, and Priority write SQLite and enqueue the push.
- `todo:agent:comment ISSUE` and `todo:agent:complete ISSUE` — write SQLite and enqueue the corresponding GitHub push. `complete` is currently stale (still calls the old synchronous `CloseGitHubIssue` directly, from before #49's push-queue conversion) — fixing this is part of #45.

Machine-readable JSON is the default output. Human output is opt-in. Commands reject malformed IDs, unavailable local records, and wrong Project-field options before any write.

## MCP tools

The initial tool names and inputs are `todo_list` (area/group/priority/knowledge filters), `todo_show` (local issue ID), `todo_claim` (issue, agent, pid, minutes — returns a capability token), `todo_heartbeat` (issue, pid, token, minutes), `todo_release` (issue, pid, token), `todo_comment` (issue, body), and `todo_complete` (issue). Future `todo_create` and field-patch `todo_update` tools write SQLite and enqueue a push. A `todo_queue_status` tool exposes pending/failed/needs-attention push-queue counts and per-item detail (#50).

Note on `pid`: per #56's research, the MCP protocol itself has no session/process identity a server can read from a tool call — a stdio server's own parent process *is* the connecting client, so once #41 builds the real MCP server it can read that PID directly via `getppid()` without the client needing to supply anything. The CLI fallback has no equivalent (each Artisan invocation is its own short-lived process, not the long-running agent), so it requires an explicit `--pid=` naming the calling agent's own process — the shared Actions underneath verify whichever PID they're given the same way regardless of which surface it came from.

Every tool returns structured JSON with stable local IDs, GitHub URLs when pushed, and push-queue status when relevant. Stdio is local-only; no network listener, browser credential, or MCP tool argument contains a GitHub token.

## Claims and checkpoints

The local-only `agent_sessions` and `task_claims` models bind a task to an explicit worker identity with an expiry and renewal time. A claim is not a GitHub lock: it prevents accidental duplicate local-agent work sharing one local database. This contract is explicitly scoped to multiple agents/processes on **one** local database — it does not arbitrate ownership across independent databases or between different users. Multi-user/multi-instance collaboration is a direction Andres wants to keep open but is explicitly deferred (see #38); no distributed conflict-resolution design exists yet. Workspace display of claims is pending (#46). A worker must post concise checkpoint comments for a material result, decision, blocker, or verification result, never raw tool logs.

**Hardened as of #40 (2026-09-12):** a claim binds to the caller's real OS process, not a self-reported string. `App\Services\Process\LinuxProcessLiveness` reads `/proc/<pid>/stat` directly to confirm a claimed pid genuinely exists and matches the start time recorded when the claim was made — a PID that's since been reused by an unrelated process cannot keep an old claim (or its heartbeat) valid. This requires the `todo-app` container to see host PIDs (`pid: "host"` in `docker-compose.yml`), since agents run on the host while the app runs in a container — without that, `/proc` inside the container only reflects the container's own PID namespace and can't verify anything about a host-side process. When liveness genuinely can't be verified (that setting removed, or a container/namespace situation where it doesn't apply), a claim still succeeds but is flagged `is_verified_live: false` — treated as weaker assurance, never auto-reclaimed as "dead," only as expired.

Claims, session metadata, and explicit checkout bindings are local durable data. GitHub no longer holds the sole durable copy of anything, so these still need backup with SQLite same as before.

## Retry and conflict rules

- Reads are always against local SQLite and are retry-safe.
- A write commits to SQLite immediately as the confirmed result and enqueues a push-queue row; the command does not wait on GitHub.
- A push-queue row that fails to apply to GitHub (rejected, conflicting edit, rate-limited) is marked `needs_attention` for human review rather than silently retried into a duplicate or silently discarded.
- Updates patch only requested fields, locally and in the resulting push. A push rejection does not roll back the local write; it surfaces via the queue.
- A command records the agent identity and intent in the local record before a multi-step write.
- The first release has no unattended scheduler that creates, edits, completes, or comments through the MCP surface itself — an agent explicitly invoking a command is the triggering event. The push-queue worker draining to GitHub is a separate, already-authorized background process, not an "unattended agent write."

## Website convention

A website is a Group inside Work or Personal Projects. Its meaningful parent issue holds repository/path/context links. Work is child issues; granular work is nested children. Lessons, research, and decisions are labeled issues in that Group, with evidence and records in comments. A future `website_bindings` table must use explicit repository paths; names alone never select a checkout.
