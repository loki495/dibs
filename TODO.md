# Product backlog

## Implementation order

1. Build the local stdio MCP server over the existing agent Actions/CLI, then complete durable sessions/claims and ambiguous-mutation reconciliation.
2. Build scheduling fields, then calendar/recurrence and eventual Google Calendar or notification work.
3. Build offline-only SQLite mode last, after the synchronized workflow is stable.

## Confirm-before-removal workspace tasks

Do not delete any item in this section until the maintainer explicitly confirms it is done.

- Make task editing use the same reusable form and fields as task creation: description, labels, Group, native parent, and Project membership. Unify the visual design with the preferred edit form and reuse the component/form logic.
- Add comment browsing, creation, and editing to the task detail drawer.
- Show each task’s label pills reliably in list rows, with label colors where available.
- Add app-owned Project colors and use them for accessible task-row tints.
- Render Group virtual roots in All Projects as well as within a selected Project; retain the current selected-Project behavior.
- Remove “websites” from the Group filter.
- Delete the GitHub `parent` label but keep a dynamic `parent` filter in Todo that shows tasks with children.
- On mobile, replace the tall Daily/All/Project controls with one area dropdown containing Daily, All Projects, and every Project.
- On mobile, place the Tasks/Knowledge toggle and Add Task button on one row; correct the Add Task button color.
- Remove the visible “Labels” heading. Keep label buttons; on mobile show one expandable row with a right-side chevron.
- Remove list-row bullet/status-circle markers. Add Mark as done in the task drawer, implemented as GitHub issue close. Tint task rows by Project color, using a lighter parent-to-stronger leaf saturation/opacity gradient.
- Assess and build an agent-facing MCP server so this private Todo repository becomes the source of truth for active work plans. Agents should query, create, update, complete, and remove tasks and post structured comments through the app while GitHub remains authoritative and SQLite stays locally synchronized. Validate whether it can replace worktree TODOs and plan/lesson/research folders, then write a practical tool guide and migration convention.
  - Model each website as a Group in Work or Personal Projects, with a main parent issue holding README-like context or links plus relevant paths/repositories.
  - Model ordinary work as child issues; use nested child issues for smaller/granular work.
  - Store scoped lessons, research, and decisions as separately titled issues in that Group, with individual findings and records in comments.
  - Define safe agent identity, claims, concurrency, retries, permissions, useful checkpoint/comment formats, and how local context is synchronized without exposing credentials.
- Add an offline-only mode backed solely by the local SQLite database, with explicit boundaries from GitHub-synchronized data and a recovery/export strategy.
