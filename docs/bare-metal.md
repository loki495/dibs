# Running Dibs without Docker

Dibs runs fine directly on a Linux host. You run four things yourself: a web server, the scheduler, the
claim-liveness watcher, and (per agent) the MCP server.

## Requirements

- **PHP 8.5** with `pdo_sqlite`, `intl`, `pcntl` and `zip`, the extensions the Docker images add.
  `pcntl` is required by `dibs:claims:watch`, which fails at startup without it. Without `zip`, Composer
  needs the `unzip` command instead. Most distribution PHP builds already include `pdo_sqlite`.
  The test suite also needs `sockets` (and `pcov` for coverage); running Dibs doesn't.
- **Composer 2**, and **Node.js 22 with npm** to build the frontend assets.
- **SQLite** comes with `pdo_sqlite`; the `sqlite3` command-line tool is optional.
- **Linux `/proc`** for claim liveness (see [Claim liveness](#claim-liveness)). On other systems claims
  still work but are recorded as unverified and only expire with their lease.

## Install

```bash
git clone https://github.com/loki495/dibs.git
cd dibs
composer install --no-dev --optimize-autoloader
cp .env.example .env            # then set APP_URL (and the rest, see the comments in the file)
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
npm ci && npm run build
php artisan todo:user           # creates your login
```

`DIBS_UID`/`DIBS_GID` in `.env` only matter for Docker; leave them blank.

## Long-running processes

Run them all as one user that owns the checkout: they all write `database/` (the directory itself, since
SQLite in WAL mode creates `database.sqlite-wal` and `-shm` beside the file), `storage/` and
`bootstrap/cache/`. If your web server runs as another user, such as `www-data` under PHP-FPM, both users
need write access to those three, for example through a shared group with `chmod g+ws` on the
directories and `umask 002` for both.

- **Web server.** Point nginx or Apache (with PHP-FPM or `mod_php`) at `public/` as the document root.
  Apache needs `mod_rewrite` and `AllowOverride All` for `public/.htaccess`. For a quick local look,
  `php artisan serve` (single-process, development only) is enough.
- **Scheduler:** `php artisan schedule:work`. It drains the GitHub push queue every 10 seconds and prunes
  the activity log daily.
- **Claim watcher:** `php artisan dibs:claims:watch`. It re-checks every live claim's process each
  `DIBS_CLAIM_LIVENESS_INTERVAL` seconds (default 30) and records the result the UI shows. It stops
  cleanly on SIGTERM.

Example systemd user units, in `~/.config/systemd/user/` (`%h` is your home directory; adjust the paths):

```ini
# dibs-scheduler.service
[Unit]
Description=Dibs scheduler

[Service]
WorkingDirectory=%h/dibs
ExecStart=/usr/bin/php artisan schedule:work
Restart=always

[Install]
WantedBy=default.target
```

`dibs-claims.service` is the same with `Description=Dibs claim watcher` and
`ExecStart=/usr/bin/php artisan dibs:claims:watch`. Then:

```bash
systemctl --user daemon-reload
systemctl --user enable --now dibs-scheduler dibs-claims
loginctl enable-linger "$USER"   # keep them running while you're logged out
```

## Connecting agents

Agents launch the MCP server themselves, as a child process:

```bash
php /path/to/dibs/artisan mcp:start todo
```

For example `claude mcp add dibs -- php /path/to/dibs/artisan mcp:start todo`, or the same command in
the `.mcp.json` / `opencode.json` shapes the README shows for Docker (`"command": "php"`, the rest as
arguments). The server identifies the claiming agent as its own parent process, so it must be started
by the agent directly, on the same host.

## Claim liveness

Liveness reads `/proc/<pid>/stat` of the claiming agent, which is world-readable, so the watcher and the
MCP server can run as any user, not necessarily the agents' own. If `/proc` is mounted with `hidepid`,
other users' processes are hidden, so Dibs can't tell a dead agent from a hidden one and reports every
claim as "liveness unverifiable" rather than dead; claims then only expire with their lease. To get
verified liveness there, run Dibs as root or as a member of the group named by the mount's `gid=`
option.

## Upgrading

Back up `database/database.sqlite` and `.env` first (see the README's backup section), then:

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
systemctl --user restart dibs-scheduler dibs-claims
```

Reload PHP-FPM or Apache too if they cache code (OPcache), and reconnect agent sessions so their MCP
servers start on the new code.
