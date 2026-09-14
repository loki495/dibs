# Dibs

A self-hosted todo list app that's MCP-native — AI agents can read, claim, and complete your
tasks through the same interface the web UI uses, so you can work solo or hand off work to
agents without stepping on each other. Backed by GitHub Issues and Projects as an asynchronous
mirror; local SQLite is authoritative.

**Stack:** Laravel 13, Livewire 4, PHP 8.5, SQLite, Tailwind 4, Flux UI.

<p>
  <img src="docs/images/screenshot-light-desktop.png" alt="Dibs workspace, light theme" width="49%">
  <img src="docs/images/screenshot-dark-desktop.png" alt="Dibs workspace, dark theme" width="49%">
</p>
<p>
  <img src="docs/images/screenshot-detail-panel.png" alt="Task detail panel with comments" width="49%">
  <img src="docs/images/screenshot-light-mobile.png" alt="Dibs on mobile" width="23.5%">
  <img src="docs/images/screenshot-dark-mobile.png" alt="Dibs on mobile, dark theme" width="23.5%">
</p>

*(Screenshots show seeded sample data — see `database/seeders/DemoSeeder.php`.)*

## Quick start

Requires Docker and Docker Compose.

```bash
git clone https://github.com/loki495/dibs.git
cd dibs
bash docker/setup.sh
docker compose exec -u www-data app php artisan todo:user
```

The last command creates your login (name, email, and a password of at least 12 characters —
there's no public registration). Then visit `http://localhost:8095` (override the port with
`APP_PORT` in `.env`).

Running behind a reverse proxy? See `docker/compose.traefik.example.yml` for a working
label-based Traefik example.

Want to look around with realistic sample data instead of an empty workspace?
`docker compose exec -u www-data app php artisan db:seed --class="Database\Seeders\DemoSeeder"`
adds a few dozen fake tasks across areas, groups, priorities, and labels. It's meant for a
fresh database — don't run it against one you care about.

### Connecting to GitHub (optional)

Dibs works as a local-only task tracker out of the box. To sync with GitHub Issues/Projects,
set these in `.env`:

- `DIBS_GITHUB_OWNER` / `DIBS_GITHUB_REPO` — the repository to mirror issues to/from
- `GITHUB_TOKEN` — a personal access token with repo/project scope
- `GITHUB_PROJECT_NUMBERS` — which GitHub Projects (v2) to show as areas

Local edits queue automatically and push to GitHub in the background; a manual
**Refresh from GitHub** button pulls the latest.

## Running checks

```bash
composer pint      # code style (auto-fixes)
composer phpstan    # static analysis
composer rector      # modernization (dry-run only)
composer pest        # tests
```

## Agent integration

Dibs exposes a host-local stdio MCP server (`php artisan mcp:start todo`) so AI agents (Claude,
Codex, etc.) can read, create, claim, and complete tasks through the same Actions the UI uses —
without ever handling your GitHub token. See [`docs/agent-interface.md`](docs/agent-interface.md)
for the full tool contract, and a JSON CLI fallback (`php artisan todo:agent:*`) for scripting or
recovery.

## Learn more

- [`docs/architecture.md`](docs/architecture.md) — data model and sync design
- [`docs/agent-interface.md`](docs/agent-interface.md) — MCP/CLI contract for agents
- [`docs/demo-hosting.md`](docs/demo-hosting.md) — how the public demo instance is built and deployed
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — how to contribute
- [`CLAUDE.md`](CLAUDE.md) — conventions for AI coding assistants working in this repo

## License

MIT — see [LICENSE](LICENSE).
