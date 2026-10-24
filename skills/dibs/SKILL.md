---
name: dibs
description: How an AI agent should use a Dibs task tracker over its MCP server (todo_* tools) - picking up work cold, filing tasks and plans, claiming and completing tasks without colliding with other agents, and recording research and lessons. Use whenever the dibs MCP server is available and the work involves tracked tasks, multi-step plans, or knowledge worth keeping.
compatibility: Requires the Dibs MCP server (php artisan mcp:start todo). The php artisan todo:agent:* commands are a partial CLI fallback.
---

# Working with Dibs

Dibs is a task and knowledge tracker built for agents. Local SQLite is authoritative; GitHub is an optional asynchronous mirror, so a write succeeds locally at once and is pushed later, or never on a local-only instance (`todo_status` reports `mode: local`). The tools are the hand-off between sessions: nothing needs to be remembered in chat, and anything worth keeping between sessions belongs in Dibs, not in the conversation.

## Start of a session

1. Call `todo_status` once to confirm you are talking to the intended Dibs instance (app, repository, counts).
2. Call `todo_context` for orientation: areas (Projects), Groups, label names, live claims, push-queue counts.

Pass `noop: true` to both. It is ignored, but some MCP clients (Claude Code's permission callback, for one) fail a call whose input is an empty object, and these two tools have nothing else to send.
3. Call `todo_list` to see what is outstanding. Scope it with `areas`/`areaNames`, `groups`/`groupNames` or `parentId` (add `descendants: true` for the whole tree beneath it), and with `labels` (all), `anyLabels` or `excludeLabels`; names are accepted wherever ids are, so you need not look ids up. Filter `label: "agent task"` (with a space, no hyphen; matching ignores case) to see only work an agent can finish unattended. That label is a convention, not something every install has: if `todo_context` does not list it, the filter returns nothing and the response lists the name under `unresolved`, so list without a label filter instead. A task without the label is not forbidden, but tasks that need a person (errands, purchases, judgment calls) are not yours to pick up. Every `id` you pass to or get from a `todo_*` tool is the **local id**, not the GitHub issue number; when you quote an id to the user, say "id 541" (the web UI shows it that way), never "#541", which is the GitHub number.
4. Use `todo_search` when you know a topic; it takes the same filters as `todo_list` and needs no keywords if a filter is given. Every keyword must appear in a title or body, and closed records are included by default, so look here before researching something that may already be written down.
5. Use `todo_show` only for the records you actually need in full.
6. Once you have read a record's body this session, keep it — don't call `todo_show` on the same id again without a concrete reason. A concrete reason is your own `todo_revise`/`todo_comment` landing on it, a write that returned `{conflict: true}`, or resuming cold in a new session. Elapsed time is not a reason: never assume a record is still current just because little time has passed or nothing you did touched it — something else (your user, another agent, a GitHub-side edit) may have. To check without paying for the full body again, call `todo_peek` with the id(s) — it returns `{id, title, revision, state}` for a batch in one cheap call, no body. `revision` only bumps on `todo_revise` (title/body/Group/parent/labels), so also compare `state` if closing/reopening matters to you; neither covers a comment, claim, or Priority change, or a label changed elsewhere (the web UI), so re-fetch in full if one of those is what you need to know about. `todo_list`/`todo_search` summaries carry the same `revision`/`state` fields too, if you are already calling one of those for another reason.

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
- Multi-step work: use `todo_scaffold_plan` to create the plan and its initial child tasks in one atomic call, and add more with `todo_create(parentId: <plan id>)` as scope grows. Break the plan into child tasks as soon as its scope is known, even if you will do it all yourself. Open children are what is left; closed ones are done. Write each step body with these sections, each on its own line: `Objective:`, `Files likely:` (paths, classes, methods), `Tier:`, `Done-when:` (a checkable rule: what must be true and what evidence is recorded), `Evidence:` (what the closing note records), `Rules:` (links, not pasted text), `Blocked-by:`. Example: `Done-when: scaffolding a step without Done-when is flagged; tests cover happy and sad paths; atlas updated.` `todo_scaffold_plan` and `todo_show` of a plan return `stepLint`, the open steps missing `Done-when`, `Files likely` or `Evidence`, by section name; fix them with `todo_revise`. Done-when, Evidence and Files likely can instead be set as fields (`step: {doneWhen, evidence, filesLikely}` on `todo_create`/`todo_revise`/scaffold children), so changing one needs no body rewrite; set `blockedBy` (sibling step ids, or earlier child positions in `todo_scaffold_plan`) for the order between steps. To pick up a plan, call `todo_next(planId)`: it returns the first open, unclaimed, unblocked step with its work order (objective, Done-when, Evidence, Files likely, linked rules, last checkpoint), or why nothing is ready. An optional `Guards: floor|standard|strict` line in a plan or step body sets how much evidence `todo_complete` asks for (see step 5).
- Pass an `idempotencyKey` on any create or revise so a retry returns the original result instead of duplicating it. The write and its receipt commit together, so a failed call leaves nothing behind and is safe to retry with the same key. Use a fresh key per intended write: reusing one for a different kind of write (a comment key on a create) is rejected.
- If you notice a follow-up while busy with something else, file it as a task right then instead of mentioning it only in chat.

## Claim, work, finish

Claim the task **before** you start on it, not after: a claim is what tells other agents and sessions the work is taken, so claiming only when you finish (a quick claim-then-close after the work is done) defeats it and lets two agents collide. The order is claim, work (heartbeat if long), then complete. If you did work without claiming, say so in the closing note rather than presenting a claim as if it covered the work.

1. `todo_claim_status` first. A live claim (`isCurrentlyAlive: true`) means another process is working on that task right now: pick a different one.
2. `todo_claim` with your `agentName`. It returns a `capabilityToken` once, in plaintext. Keep it: `todo_heartbeat`, `todo_release` and `todo_complete` all need it. The server identifies your process itself; you never pass a pid. It also returns `workOrder`, the task's brief: work from it instead of re-reading the whole plan. Each linked rule's full text comes only the first time your `agentName` gets it (and again when the rule is revised); keep it for the rest of your context. After a compaction, `/clear` or resume, pass `contextReset: true` so the rules are sent again.
3. On a long task, call `todo_heartbeat` periodically so the lease does not expire. It creates no comments or pushes.
4. Post `todo_comment` checkpoints for a material result, decision, blocker or verification outcome. Keep them concise; never paste raw tool logs.
5. Finish with `todo_complete` and a short `summary` (what changed, how it was verified, what remains) — it becomes the closing note, shown by `todo_show` as `closing.note`. Add `reason` (`COMPLETED` or `NOT_PLANNED`) and `references` (commit/PR pointers) when useful; both are ignored without a `summary`. Pass the evidence as `result`: `commit` (SHA), `tests` (`{command, exit_code}`), `docs` (paths), and for a plan step `done_when_held: true`; where one does not apply, give `"none: <the real reason>"`. The completion guards refuse a close without what the task needs (a commit for `agent task`, `bug` and plan steps; tests and docs whenever a commit is given; a Done-when line for plan steps) and say exactly what is missing. `force: true` with a real `forceReason` overrides them; it is logged and shown on the closing note, so use it only when the evidence truly does not apply, never just to get the task closed. Only close a task once its acceptance criteria are actually met. To give a task up unfinished, use `todo_release` with `left` (what is left and the exact next step) so another agent can take it immediately.

## Revising and conflicts

- Change a title, body, Group (`groupId`, `newGroupName` or `clearGroup`), parent (`parentId` or `clearParent`) or labels with `todo_revise` (`addLabels` / `removeLabels` name lists; removing a label the task lacks is ignored), passing the `revision` you last read as `expectedRevision`. A stale write is not an error: it returns `{conflict: true, current: ...}`. Reread the current record, reconcile, then retry. Never overwrite blindly.
- Link the records that apply to a task (rules, lessons, research, decisions, guides, related tasks) instead of pasting their text: `addLinks` / `removeLinks` on `todo_revise`, `addLinks` on `todo_create` and on each `todo_scaffold_plan` child. Each link is `{issueId}` or `{issueId, commentId}` (one comment of that issue); duplicates are ignored, and a missing, unavailable or self target is an error. `todo_show` returns `links` (what this issue points to) and `linkedBy` (what points at it) as capped one-line summaries with the target's labels; the kind of a record is its labels, there is no separate relation type. Read the linked records you are about to act on.
- The issue body is the canonical document; comments are supporting discussion and evidence. Use `todo_comment` for history and `todo_revise` for the maintained text.
- For a small change to a long text, such as re-stamping an atlas comment's Verified at SHA, pass `replace: [{from, to}]` to `todo_comment` (with `commentId` + `expectedRevision`) or `todo_revise` instead of the whole body: `from` must occur exactly once (or pass `all: true`), and the response leaves the text out. This is the way to restamp.
- If `todo_queue_status` shows items needing attention, that is a GitHub sync conflict for a person to review. Do not try to work around it.

## Recording what you learn

When you find something durable, file it as a knowledge issue instead of leaving it in a comment: `research` for a checked finding about specific code or a system, `lesson` for a gotcha that is not code-specific, `decision` for a settled choice and its reasons. Include what applies where, the evidence, and when it was verified. Link it from the related task or parent. Check for an existing record first (`todo_search`) and update it rather than adding a conflicting second one.

## Boundaries

- Do not put credentials in a task, comment or tool argument. The server reads the GitHub token itself.
- A title, body or comment marked `withheld: true` was written on GitHub by someone the owner hasn't trusted. Don't try to get at its text another way; if you need it, ask your user to read it in the web UI.
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
| One record in full | `todo_show` (`maxBodyLength` for a cheap preview) |
| Check if cached records changed | `todo_peek` |
| Sync backlog | `todo_queue_status` |
| Create a task or knowledge record | `todo_create` |
| Create a plan with children | `todo_scaffold_plan` |
| Next ready step of a plan, with its brief | `todo_next` |
| Edit title/body/Group/parent/labels/step fields safely | `todo_revise` |
| Comment or edit a comment | `todo_comment` |
| Take, keep, drop, finish a task | `todo_claim`, `todo_heartbeat`, `todo_release`, `todo_complete` |
| Reopen a closed task | `todo_reopen` |
| Who holds a task | `todo_claim_status` |
| Report tooling problem | `todo_report_bug` |

If the MCP server cannot be reached, a subset of the operations exists as CLI commands inside the `app` container: `php artisan todo:agent:` followed by `list`, `show`, `claim`, `heartbeat`, `release`, `create`, `update`, `comment` or `complete`. The rest (`todo_status`, `todo_context`, `todo_metadata`, `todo_search`, `todo_peek`, `todo_queue_status`, `todo_next`, `todo_scaffold_plan`, `todo_reopen`, `todo_claim_status`, `todo_report_bug`) are MCP-only. The claim-scoped commands (`claim`, `heartbeat`, `release`, `complete`) need an explicit `--pid` naming the calling agent's own long-lived process (from a shell call, `--pid=$PPID`; never the short-lived `docker exec`), and `heartbeat`, `release` and `complete` also need `--token`. See `docs/agent-interface.md` for the full contract.
