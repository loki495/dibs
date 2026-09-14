# Dibs

A todo list app — that's also built MCP-native, so AI agents can pick up your tasks, coordinate without stepping on each other, and hand off work cleanly. Use it solo, or let agents claim work alongside you. Backed by GitHub Issues and Projects as an asynchronous mirror; local SQLite is authoritative.

Stack: Laravel 13, Livewire 4 class-based single-file components, PHP 8.5, SQLite, Tailwind 4. The private scaffold, light/dark/system themes, hierarchy UI, and durable push queue for asynchronous GitHub sync work. Task capture and editing use local-first Actions for title, description, Project, Group, Priority, parent, labels, completion, and comments, then queue them for GitHub. Local agents can read, claim, release, comment on, and complete tasks through the same application boundary.

## Local setup

Run from this repository with Docker Compose available. PHP/Composer run inside the app container; Node runs in the tools service.

```bash
# On this LAN, with the existing Traefik web network and DNS:
cp docker/compose.traefik.example.yml docker-compose.override.yml
bash docker/setup.sh
docker compose exec -u www-data app php artisan todo:user
```

The final command asks for a name, email and hidden password (12+ characters). No default account or public registration exists. Open your configured hostname on the LAN. App container: dibs-app; loopback port: 8095. Use HTTPS for login because session cookies are secure.

The Traefik override is machine-specific and ignored. Review its hostname, source ranges and .env TRUSTED_PROXIES when moving hosts; the example assumes the existing web Docker network and 192.168.1.0/24 LAN. No shared Traefik files were changed. A clean host also needs DNS and TLS routing configured. Without Traefik, explicitly configure local HTTP/session settings for your environment.

.env, SQLite databases, vendor, node_modules and local routing overrides are ignored. Do not commit credentials. The manual pull wrapper uses your host gh login without persisting its token. The durable push queue requires a configured GITHUB_TOKEN. Keep SQLite on local disk; WAL and a 5-second busy timeout are configured. The setup command preserves an existing application key and database.

## Checks

```bash
docker compose exec -T -u www-data app vendor/bin/pint
docker compose exec -T -u www-data app vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec -T -u www-data app vendor/bin/rector process --dry-run
docker compose exec -T -u www-data app vendor/bin/pest
docker compose run --rm node npm run build
```

Host Composer shortcuts: composer pint, composer phpstan, composer rector, composer pest. Do not invoke those Docker wrappers inside the container; use vendor/bin there. Tests use an isolated in-memory SQLite database and block stray HTTP requests. Prefer Pest TDD for non-obvious changes.

## Agent handoff

Read CLAUDE.md before coding — it documents the architecture, conventions, and testing approach in detail. This project tracks its own multi-step work in GitHub issues rather than a file-based planning protocol. Local SQLite is authoritative once imported; do not seed fake task data or overwrite newer user organization changes.

## Manual GitHub pull

```bash
scripts/github-pull
scripts/github-pull --comments
# Or, with GITHUB_TOKEN configured server-side:
docker compose exec -T -u www-data app php artisan todo:sync --comments
```

The wrapper pipes `gh auth token` into the container over stdin; it never puts the token in arguments, logs or a file. Full pulls reconcile the configured repository and Project numbers (GITHUB_PROJECT_NUMBERS=1,2,3,4). Comments are optional because they add requests. No GitHub records are written.

The signed-in workspace also has **Refresh from GitHub**. It runs that same complete, read-only importer with comments, disables while it runs, and preserves the cached workspace with a retry message if GitHub is unavailable. It requires a server-side `GITHUB_TOKEN`; keep it only in ignored local configuration with restrictive file permissions.

The importer fetches all pages before applying a transaction, preserves node IDs/parent links/multiple memberships and marks absent records unavailable rather than deleting them. Renamed fields retain their initial semantic mapping (Status, Group, Priority, Planned, Due, Repeat); mappings are stored in project_fields.semantic_key. Unknown content stays in the raw Project-item payload. Field options retain last-known records; future editing must offer only options in the current field configuration_json. Failed pulls retain the previous data and expose last_error/retry_after in sync_states. Respect that retry window; correct configuration and retry later.

## Push queue

Local edits are queued to GitHub through a durable, asynchronous push queue (app/Actions/DrainGitHubPushQueue.php) scheduled every minute. The queue persists across restarts and retries with exponential backoff on transient failures. See `docs/architecture.md` for details.

## Read-only workspace

Projects are broad areas you define — Work, Personal Projects, Learning, and so on. **Group** is the first visual root inside a selected Project area and names a website or topic within it. Native issue parents form the optional hierarchy below the Group. Ungrouped issues remain direct roots. Group headings are virtual UI rows and disappear when filtering by that Group.

The workspace shows your configured GitHub Projects as areas. Group is inserted as the first virtual root for a selected Project; filtering by that Group hides the duplicate heading. Parent issues form a recursive tree that starts collapsed and remembers expansion in browser storage. Search and filters keep matching parent context visible, including a parent stored outside the selected area. Labels are compact multi-select chips (AND matching); Website/Group, Priority, issue-state, Tasks, and Knowledge filters are available. Priority is a per-Project-item single-select value from 1 through 5; Rank priority sorts siblings without flattening native parent/child hierarchy.

The Daily list combines open actionable issues that are planned through today, due through today, or labeled `today`, using `America/Los_Angeles`. Organizational parents and knowledge records do not inflate task counts or appear as overdue tasks. Selecting an issue opens a keyboard-accessible detail drawer with its maintained description, imported comments, labels, Project fields, and GitHub link. Imported Markdown is rendered after removing raw HTML and unsafe links.

The header’s **Refresh from GitHub** control reconciles issues, Projects, memberships, fields, and comments immediately. It is manual, synchronous and read-only.

Task capture uses a Flux modal. It creates in GitHub first, then projects the confirmed Issue locally; the viewed Project/Group is preselected and the form supports a searchable native parent, existing or new Group, and existing or one new label, and Priority. If placement fails after creation, the Issue remains visible and the UI identifies the failed context; do not resubmit its title. Existing-task editing includes title, description, Project, Group, Priority, parent, and labels. The detail drawer supports completion and GitHub comments; scheduling fields, recurrence, and notifications are still pending.

## Agent integration

Dibs's agent interface is a host-local stdio MCP server (`App\Mcp\Servers\TodoServer`, started with `php artisan mcp:start todo`), exposing 14 tools — context/read, create/revise/comment, and the full claim lifecycle (`todo_claim`, `todo_heartbeat`, `todo_release`, `todo_complete`, `todo_claim_status`) — without ever giving an agent a GitHub token. `todo_claim`/`todo_heartbeat`/`todo_release`/`todo_complete` identify the calling process via `posix_getppid()` automatically; the CLI fallback below needs an explicit `--pid=` since each Artisan invocation is its own short-lived process. The Artisan commands are the recovery and smoke-test interface underneath the MCP server, backed by the same shared Actions:

```bash
docker compose exec -T -u www-data app php artisan todo:agent:list
docker compose exec -T -u www-data app php artisan todo:agent:show 42
docker compose exec -T -u www-data app php artisan todo:agent:claim 42 --agent=codex --pid=$$
docker compose exec -T -u www-data app php artisan todo:agent:heartbeat 42 --pid=$$ --token=TOKEN
docker compose exec -T -u www-data app php artisan todo:agent:release 42 --pid=$$ --token=TOKEN
docker compose exec -T -u www-data app php artisan todo:agent:comment 42 "Checkpoint: tests passed"
docker compose exec -T -u www-data app php artisan todo:agent:complete 42 --pid=$$ --token=TOKEN --summary="Done"
```

All commands emit JSON. `list` and `show` read SQLite; `claim`/`heartbeat`/`release`/`complete` are claim-scoped, local-first writes that enqueue a GitHub push where relevant (heartbeats do not); `comment` is a local-first write. See `docs/agent-interface.md` for the full MCP tool contract, CLI fallback, retries, and future website bindings.
