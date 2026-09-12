# MCP agent interface

Status: MCP is the canonical planned interface; the documented Artisan CLI fallback is implemented. GitHub remains authoritative for issues, comments, labels, native parents, Project membership, Group, and Priority. SQLite is the synchronized read model plus local-only agent state.

## Boundary

Agents run on the host and connect to a stdio MCP server. The server exposes Todo tools and delegates to the same application Actions as the workspace. `php artisan todo:agent:*` is retained as a local recovery and smoke-test fallback. There is no unauthenticated HTTP API and no GitHub credential in an agent prompt, browser bundle, or command argument. The MCP server and CLI read the server-side `GITHUB_TOKEN` only when a GitHub-first Action needs it.

Implemented commands: `list`, `show`, `claim`, `release`, `comment`, and `complete`. The remaining write commands follow the same contract.

The command surface is:

- `todo:agent:list` — read current tasks, parent context, Group, Project, labels, Priority, and sync state from SQLite.
- `todo:agent:show ISSUE` — read one task with description, comments, hierarchy, and relevant Project fields.
- `todo:agent:create` — GitHub-first issue creation with Project, Group, parent, labels, and Priority.
- `todo:agent:update ISSUE` — intentional-field patches only; title/body, metadata, and Priority use the existing Actions.
- `todo:agent:comment ISSUE` and `todo:agent:complete ISSUE` — GitHub-first mutations using existing comment/close Actions.

Machine-readable JSON is the default output. Human output is opt-in. Commands reject malformed IDs, unavailable local records, wrong Project-field options, and a missing server token before GitHub is contacted.

## MCP tools

The initial tool names and inputs are `todo_list` (area/group/priority/knowledge filters), `todo_show` (local issue ID), `todo_claim` (issue, agent, session, minutes), `todo_release` (issue, session), `todo_comment` (issue, body), and `todo_complete` (issue). Future `todo_create` and field-patch `todo_update` tools must preserve the mutation ledger and reconcile ambiguous outcomes before retrying.

Every tool returns structured JSON with stable local IDs and GitHub URLs when available. Stdio is local-only; no network listener, browser credential, or MCP tool argument contains a GitHub token.

## Claims and checkpoints

The local-only `agent_sessions` and `task_claims` models bind a task to an explicit worker identity with an expiry and renewal time. A claim is not a GitHub lock: it prevents accidental duplicate local-agent work. Workspace display remains pending. A worker must post concise checkpoint comments for a material result, decision, blocker, or verification result, never raw tool logs.

Claims, session metadata, and explicit checkout bindings are local durable data. They are not part of the rebuildable GitHub snapshot and need backup with SQLite.

## Retry and conflict rules

- Reads are retry-safe after normal synchronization.
- An ambiguous GitHub create/comment result is marked `reconciliation_needed`; the command reconciles before any retry and does not blindly create another object.
- Updates patch only requested fields. A remote rejection retains local drafts/intent and reports the GitHub error.
- A command records the agent identity and intent in the mutation ledger before a multi-step write.
- The first release has no unattended scheduler that creates, edits, completes, or comments on GitHub. An agent explicitly invoking a command is the triggering event.

## Website convention

A website is a Group inside Work or Personal Projects. Its meaningful parent issue holds repository/path/context links. Work is child issues; granular work is nested children. Lessons, research, and decisions are labeled issues in that Group, with evidence and records in comments. A future `website_bindings` table must use explicit repository paths; names alone never select a checkout.
