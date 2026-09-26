---
name: dibs
description: How an AI agent should use a Dibs task tracker over its MCP server (todo_* tools) - picking up work cold, filing tasks and plans, claiming and completing tasks without colliding with other agents, and recording research and lessons. Use whenever the dibs MCP server is available and the work involves tracked tasks, multi-step plans, or knowledge worth keeping.
compatibility: Requires the Dibs MCP server (php artisan mcp:start todo). The php artisan todo:agent:* commands are the CLI fallback.
---

# Working with Dibs

Dibs is a task and knowledge tracker built for agents. Local SQLite is authoritative; GitHub is an asynchronous mirror, so a write succeeds locally at once and is pushed later. The tools are the hand-off between sessions: nothing needs to be remembered in chat, and anything worth keeping between sessions belongs in Dibs, not in the conversation.

## Start of a session

1. Call `todo_status` once to confirm you are talking to the intended Dibs instance (app, repository, counts).
2. Call `todo_context` for orientation: areas (Projects), Groups, label names, live claims, push-queue counts.

Pass `noop: true` to both. It is ignored, but some MCP clients (Claude Code's permission callback, for one) fail a call whose input is an empty object, and these two tools have nothing else to send.
3. Call `todo_list` to see what is outstanding. Scope it with `areas`/`areaNames`, `groups`/`groupNames` or `parentId` (add `descendants: true` for the whole tree beneath it), and with `labels` (all), `anyLabels` or `excludeLabels`; names are accepted wherever ids are, so you need not look ids up. Filter `label: "agent task"` (with a space, no hyphen; matching ignores case) to see only work an agent can finish unattended. That label is a convention, not something every install has: if `todo_context` does not list it, the filter returns nothing and the response lists the name under `unresolved`, so list without a label filter instead. A task without the label is not forbidden, but tasks that need a person (errands, purchases, judgment calls) are not yours to pick up.
4. Use `todo_search` when you know a topic; it takes the same filters as `todo_list` and needs no keywords if a filter is given. Every keyword must appear in a title or body, and closed records are included by default, so look here before researching something that may already be written down.
5. Use `todo_show` only for the records you actually need in full.

Before starting work that may already have a plan, look for an open issue labeled `plan` (`todo_list` with `label: "plan"`) and ask which to resume rather than starting a duplicate.

## Structure

- **Area** is the broad category (a GitHub Project). **Group** is a topic or website inside an area. **Parent** links express real task breakdown below that. These are separate: choosing a Group never implies a parent.
- A **plan** is an issue labeled `plan`; its tasks are its child issues.
- **Knowledge** is an issue labeled `research`, `lesson`, `decision` or `guide`. Its body is the maintained finding. Labeling an ordinary task with one of these hides it from the Tasks view, so use `needs research` for a task that still needs investigating.
- Each issue has a `revision`. Writes that take `expectedRevision` are optimistic-concurrency protected.

## Filing work

- Create one task with `todo_create`. Set `area`, and `groupId` (or `newGroupName`) and `parentId` when they obviously fit. If it is unclear which area or Group applies, ask rather than guess.
- Call `todo_metadata` for ids: every area with its Groups and Priority options, and every label with its id, description and usage count, plus how to attach or create each. `query` searches names (and label descriptions); `kinds`/`area` narrow it. Or skip ids entirely and attach labels by name with `labelNames`: an array such as `["bug", "agent task"]`, each matched case-insensitively against existing labels and created if missing. `labelIds` (ids you already have) and the older single `newLabelName` still work and combine with it.
- Name any label you create with spaces, not hyphens (`needs research`, not `needs-research`); labels accept spaces. Labels are always stored lowercase and a label filter ignores case, so any casing resolves to the same label.
- Apply the `agent task` label to a task an agent could complete on its own. Include `"agent task"` in `labelNames`; it is attached, and created on first use.
- Multi-step work: use `todo_scaffold_plan` to create the plan and its initial child tasks in one atomic call, and add more with `todo_create(parentId: <plan id>)` as scope grows. Break the plan into child tasks as soon as its scope is known, even if you will do it all yourself. Open children are what is left; closed ones are done.
- Pass an `idempotencyKey` on any create or revise so a retry returns the original result instead of duplicating it.
- If you notice a follow-up while busy with something else, file it as a task right then instead of mentioning it only in chat.

## Claim, work, finish

1. `todo_claim_status` first. A live claim (`isCurrentlyAlive: true`) means another process is working on that task right now: pick a different one.
2. `todo_claim` with your `agentName`. It returns a `capabilityToken` once, in plaintext. Keep it: `todo_heartbeat`, `todo_release` and `todo_complete` all need it. The server identifies your process itself; you never pass a pid.
3. On a long task, call `todo_heartbeat` periodically so the lease does not expire. It creates no comments or pushes.
4. Post `todo_comment` checkpoints for a material result, decision, blocker or verification outcome. Keep them concise; never paste raw tool logs.
5. Finish with `todo_complete` and a short `summary` (what changed, how it was verified, what remains) — it becomes the closing note, shown by `todo_show` as `closing.note`. Add `reason` (`COMPLETED` or `NOT_PLANNED`) and `references` (commit/PR pointers) when useful; both are ignored without a `summary`. Only close a task once its acceptance criteria are actually met. To give a task up unfinished, use `todo_release` so another agent can take it immediately.

## Revising and conflicts

- Change a title, body or Group with `todo_revise`, passing the `revision` you last read as `expectedRevision`. A stale write is not an error: it returns `{conflict: true, current: ...}`. Reread the current record, reconcile, then retry. Never overwrite blindly.
- The issue body is the canonical document; comments are supporting discussion and evidence. Use `todo_comment` for history and `todo_revise` for the maintained text.
- If `todo_queue_status` shows items needing attention, that is a GitHub sync conflict for a person to review. Do not try to work around it.

## Recording what you learn

When you find something durable, file it as a knowledge issue instead of leaving it in a comment: `research` for a checked finding about specific code or a system, `lesson` for a gotcha that is not code-specific, `decision` for a settled choice and its reasons. Include what applies where, the evidence, and when it was verified. Link it from the related task or parent. Check for an existing record first (`todo_search`) and update it rather than adding a conflicting second one.

## Boundaries

- Do not put credentials in a task, comment or tool argument. The server reads the GitHub token itself.
- Do not use write tools as a smoke test against a real dataset; a test create is a real task and queues a real GitHub push. Test against an isolated or scratch database.
- If a Dibs tool itself misbehaves (a bug, a confusing result, an unexpected error), report it with `todo_report_bug` rather than working around it silently. That is for tooling problems, not for questions about the task.
- Do not close, reassign or restructure tasks a person is actively editing without reading their current state first; preserve their edits and parent links.

## Tool reference

| Need | Tool |
|---|---|
| Confirm instance | `todo_status` |
| Areas, Groups, labels, live claims | `todo_context` |
| Browse tasks | `todo_list` |
| Find by topic | `todo_search` |
| One record in full | `todo_show` |
| Sync backlog | `todo_queue_status` |
| Create a task or knowledge record | `todo_create` |
| Create a plan with children | `todo_scaffold_plan` |
| Edit title/body/Group safely | `todo_revise` |
| Comment or edit a comment | `todo_comment` |
| Take, keep, drop, finish a task | `todo_claim`, `todo_heartbeat`, `todo_release`, `todo_complete` |
| Reopen a closed task | `todo_reopen` |
| Who holds a task | `todo_claim_status` |
| Report tooling problem | `todo_report_bug` |

If the MCP server cannot be reached, the same operations exist as `php artisan todo:agent:<command>` inside the app container. Those commands need an explicit `--pid` for the calling agent. See `docs/agent-interface.md` for the full contract.
