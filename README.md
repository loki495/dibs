# Todo

Private task and knowledge workspace backed by GitHub Issues and Projects.

Stack: Laravel 13, Livewire 4 class-based single-file components, PHP 8.5, SQLite, Tailwind 4. The private scaffold, light/dark/system themes, hierarchy UI, automatic local-cache reconciliation, and signed webhook delivery work. Task capture and editing use GitHub-first Actions for title, description, Project, Group, Priority, parent, labels, completion, and comments. Local agents can read, claim, release, comment on, and complete tasks through the same application boundary.

## Local setup

Run from this repository with Docker Compose available. PHP/Composer run inside the app container; Node runs in the tools service.

```bash
# On this LAN, with the existing Traefik web network and DNS:
cp docker/compose.traefik.example.yml docker-compose.override.yml
bash docker/setup.sh
docker compose exec -u www-data app php artisan todo:user
```

The final command asks for a name, email and hidden password (12+ characters). No default account or public registration exists. Open https://todo.ac495.net on the LAN. App container: todo-app; loopback port: 8095. Use HTTPS for login because session cookies are secure.

The Traefik override is machine-specific and ignored. Review its hostname, source ranges and .env TRUSTED_PROXIES when moving hosts; the example assumes the existing web Docker network and 192.168.1.0/24 LAN. No shared Traefik files were changed. A clean host also needs DNS and TLS routing configured. Without Traefik, explicitly configure local HTTP/session settings for your environment.

.env, SQLite databases, vendor, node_modules and local routing overrides are ignored. Do not commit credentials. The manual pull wrapper uses your host gh login without persisting its token. Background webhook jobs require a configured GITHUB_TOKEN. Keep SQLite on local disk; WAL and a 5-second busy timeout are configured. The setup command preserves an existing application key and database.

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

Read CLAUDE.md and [.ai/plans/2026-09-08-foundation/PLAN.md](.ai/plans/2026-09-08-foundation/PLAN.md). STATE.md records verified progress and the next step; RESULT.md records checks. Follow the five-phase order and update state after each step. GitHub remains authoritative; do not seed fake task data or overwrite newer user organization changes.

## Manual GitHub pull

```bash
scripts/github-pull
scripts/github-pull --comments
# Or, with GITHUB_TOKEN configured server-side:
docker compose exec -T -u www-data app php artisan todo:sync --comments
```

The wrapper pipes `gh auth token` into the container over stdin; it never puts the token in arguments, logs or a file. Full pulls reconcile the configured repository and Project numbers (GITHUB_PROJECT_NUMBERS=2,3,5,6). Comments are optional because they add requests. No GitHub records are written. Keep this command as backup even after webhooks are enabled.

The signed-in workspace also has **Refresh from GitHub**. It runs that same complete, read-only importer with comments, disables while it runs, and preserves the cached workspace with a retry message if GitHub is unavailable. It requires a server-side `GITHUB_TOKEN`; keep it only in ignored local configuration with restrictive file permissions.

The importer fetches all pages before applying a transaction, preserves node IDs/parent links/multiple memberships and marks absent records unavailable rather than deleting them. Renamed fields retain their initial semantic mapping (Status, Group, Priority, Planned, Due, Repeat); mappings are stored in project_fields.semantic_key. Unknown content stays in the raw Project-item payload. Field options retain last-known records; future editing must offer only options in the current field configuration_json. Failed pulls retain the previous data and expose last_error/retry_after in sync_states. Respect that retry window; correct configuration and retry later.

## Webhook receiver — live

POST /webhooks/github verifies the raw-body SHA-256 signature, configured repository and delivery ID. It accepts issues, issue_comment, sub_issues and label events, stores a durable receipt and queues a canonical pull. Repeated/out-of-order payloads cannot directly overwrite task data. Raw webhook bodies are not retained. Queue insertion and receipt are transactional on the default SQLite connection; keep the database queue on that same connection.

Ingress uses the existing Cloudflare Tunnel, an Access application scoped only to `todo.ac495.net/webhooks/github` with a Bypass/Everyone policy, and a dedicated HTTPS Tunnel origin for Todo. The zone’s existing bot rule retains its block everywhere except that exact path, which is still protected by the application’s GitHub HMAC signature and repository allowlist.

The local `GITHUB_WEBHOOK_SECRET` and server-side `GITHUB_TOKEN` are configured. The active repository hook uses JSON delivery for issues, issue comments, labels, and sub-issues. Keep the private UI behind LAN/login controls; the public edge permits only the dedicated webhook path.

```bash
# Docker Compose already runs this worker; keep a manually started worker timeout below retry_after (960s):
docker compose exec -u www-data app php artisan queue:work database --queue=github --timeout=840 --tries=5
# Recover pending receipts after fixing a failed worker/credential:
docker compose exec -T -u www-data app php artisan todo:webhooks:replay
```

Docker Compose runs supervised `worker` and `scheduler` services for automatic reconciliation. Signed local and public-path deliveries returned 202, and GitHub’s signed ping was retried successfully with an `OK`/202 delivery record. Inspect failed_jobs and unprocessed github_webhook_deliveries when diagnosing delivery failures. Personal Projects still need polling for Project-only field/membership changes; issue webhooks do not replace that.

## Read-only workspace

Projects are the four broad areas: Work, Personal Projects, Learning & Self-Improvement, and Random Tasks. **Group** is the first visual root inside a selected Project area and names a website or topic such as Sessioneer, Career, Fitness, or Home. Native issue parents form the optional hierarchy below the Group. Ungrouped issues remain direct roots. Group headings are virtual UI rows and disappear when filtering by that Group.

The workspace shows all four GitHub Projects as areas. Group is inserted as the first virtual root for a selected Project; filtering by that Group hides the duplicate heading. Parent issues form a recursive tree that starts collapsed and remembers expansion in browser storage. Search and filters keep matching parent context visible, including a parent stored outside the selected area. Labels are compact multi-select chips (AND matching); Website/Group, Priority, issue-state, Tasks, and Knowledge filters are available. Priority is a per-Project-item single-select value from 1 through 5; Rank priority sorts siblings without flattening native parent/child hierarchy.

The Daily list combines open actionable issues that are planned through today, due through today, or labeled `today`, using `America/Los_Angeles`. Organizational parents and knowledge records do not inflate task counts or appear as overdue tasks. Selecting an issue opens a keyboard-accessible detail drawer with its maintained description, imported comments, labels, Project fields, and GitHub link. Imported Markdown is rendered after removing raw HTML and unsafe links.

The header’s **Refresh from GitHub** control reconciles issues, Projects, memberships, fields, and comments immediately. It is manual, synchronous and read-only.

Freshness checks use SQLite only: every 10 seconds after recent interaction, every 60 seconds while a tab is visible but idle, and never while hidden. A check schedules one shared GitHub reconciliation at most every 30 seconds while active or 60 seconds while idle. The scheduler recovers missed updates and Project-only changes every five minutes. Automatic reconciliation omits comments; manual refresh and webhook reconciliation include them. The status indicator reports pending work, stale data, retry windows, GitHub failures, and a separate browser connection failure.

Task capture uses a Flux modal. It creates in GitHub first, then projects the confirmed Issue locally; the viewed Project/Group is preselected and the form supports a searchable native parent, existing or new Group, and existing or one new label, and Priority. If placement fails after creation, the Issue remains visible and the UI identifies the failed context; do not resubmit its title. Existing-task editing includes title, description, Project, Group, Priority, parent, and labels. The detail drawer supports completion and GitHub comments; scheduling fields, recurrence, and notifications are still pending.

## Agent integration

Todo’s intended agent interface is a host-local stdio MCP server. It will expose the same task, claim, and checkpoint operations as tools without giving agents a GitHub token. The current Artisan commands below are the recovery and smoke-test interface underneath that future MCP server: list and show read SQLite; comment and complete are GitHub-first writes.

```bash
docker compose exec -T -u www-data app php artisan todo:agent:list
docker compose exec -T -u www-data app php artisan todo:agent:show 42
docker compose exec -T -u www-data app php artisan todo:agent:claim 42 --agent=codex --session=work-123
docker compose exec -T -u www-data app php artisan todo:agent:release 42 --session=work-123
docker compose exec -T -u www-data app php artisan todo:agent:comment 42 "[CHECKPOINT] Tests passed"
docker compose exec -T -u www-data app php artisan todo:agent:complete 42
```

All commands emit JSON. `list` and `show` read SQLite; `claim` and `release` are local-only; `comment` and `complete` are GitHub-first writes. See `docs/agent-interface.md` for the MCP tool contract, CLI fallback, retries, and future website bindings.

