# Public demo hosting

How the `dibs-demo.ac495.net` instance works and how to stand it up. This is
infrastructure for one specific deployment, not a feature of the app itself --
`config('dibs.demo_mode')` defaults to `false` and every piece here is a no-op
on a normal install.

## Architecture

Adapted from a sibling project's (`homie`) equivalent demo setup, with one
deliberate difference explained below.

- **Per-visitor database isolation**, not one shared database: `ResolveDemoDatabase`
  (`app/Http/Middleware/ResolveDemoDatabase.php`) gives each visitor a private
  SQLite copy of a template, identified by a signed cookie (`demo_instance_id`).
  Two people clicking around the demo at once never see or clobber each other's
  edits -- there is no shared mutable state to reset or protect.
- **The template** (`storage/demo-template.sqlite`) is built once, manually, by
  `php artisan demo:build-template` (migrates fresh + seeds `DemoSeeder`). It is
  never touched by a running request -- only copied. Rebuild it whenever the
  demo dataset itself should change; nothing regenerates it automatically.
- **Daily cleanup**, not a scheduled reset: `demo:cleanup` (scheduled in
  `routes/console.php`, gated by `->when(fn () => config('dibs.demo_mode'))`)
  deletes per-visitor copies older than 24h from `storage/demo-dbs/`. It only
  ever touches `*.sqlite` files in that one configured directory -- there is no
  destructive "wipe everything" command running unattended anywhere in this
  design, unlike an earlier iteration of this same feature (see git history on
  this file's introducing commit if curious).
- **Isolated deployment**: `docker-compose.demo.yml` is a separate Compose
  project (`dibs-demo-app`/`dibs-demo-scheduler`), reading `.env.demo` via
  `env_file` rather than the real `.env` this directory also contains, so a
  real `GITHUB_TOKEN`/`DIBS_GITHUB_OWNER`/`DIBS_GITHUB_REPO` can never leak into
  the demo by falling through to the shared file.

### Where this deviates from homie's version, and why

homie has no login system of its own, so its demo is gated by HTTP Basic Auth,
and its `ResolveDemoDatabase` runs *after* Laravel's session middleware --
harmless there, since Basic Auth is stateless and re-checked every request
regardless of session state.

Dibs authenticates with a real session-backed login. If the database were
resolved to the visitor's own copy *after* `StartSession` ran, their login
session would get written to whatever the default connection was at that
moment, not their own copy -- breaking login on their very next request. So
`ResolveDemoDatabase` here is positioned precisely between `EncryptCookies` and
`StartSession` via Laravel's middleware priority list (`bootstrap/app.php`),
not merely prepended or appended to the `web` group. Getting this wrong is
subtle: a naive `prependToGroup` also runs *before* `EncryptCookies`, so the
visitor-id cookie it reads is still encrypted ciphertext and never validates,
silently minting a brand new visitor identity on every single request. See
the comments in `bootstrap/app.php` and `ResolveDemoDatabase` itself.

Dibs also has no Basic Auth layer -- the demo login is a normal Dibs account
(`demo@example.com`, seeded by `DemoSeeder`) that ships in every visitor's
copy of the template, published openly since the whole point is to let
visitors in.

### A known, already-hit test-suite gotcha

`ResolveDemoDatabase` calls `DB::purge('sqlite')` to force a reconnect after
repointing the config. If a test exercises this middleware under
`RefreshDatabase` (active suite-wide, `tests/Pest.php`), that purge breaks
`RefreshDatabase`'s own teardown bookkeeping -- it rolls back the wrong PDO,
leaves the real one's transaction dangling, and corrupts every later test in
the suite. `tests/Feature/DemoModeTest.php` works around it with an explicit
`beforeApplicationDestroyed` callback that rolls back the real tracked PDO
itself -- the identical fix homie's own equivalent test already needed. If you
ever write another test that exercises this middleware, copy that workaround
rather than rediscovering the corruption.

## Deploying

1. `cp .env.demo.example .env.demo` and fill in `APP_KEY` (`php artisan
   key:generate --show`, paste the value in -- don't run `key:generate`
   pointed at this file directly, since Laravel reads whichever `.env` its
   own working directory resolves to, not `.env.demo` by name).
2. `docker compose -f docker-compose.demo.yml up -d --build`.
3. `docker compose -f docker-compose.demo.yml exec app php artisan
   demo:build-template` -- one-time (or whenever you want to refresh the
   dataset). Confirms `storage/demo-template.sqlite` exists before any real
   visitor traffic arrives; `ResolveDemoDatabase` aborts with a clear 500 if
   it's missing.
4. Confirm it locally first: `curl http://127.0.0.1:8098/login` (or whatever
   `DEMO_APP_PORT` resolves to) should return the login page.

## What you still have to do yourself (outside this repo)

The real `dibs.ac495.net` is LAN-only (`dibs-lan` IP-allowlist middleware in
your personal `docker-compose.override.yml`), and external access to it goes
through Cloudflare Access. A public demo needs the opposite -- reachable
*without* your Access login -- which means two Cloudflare-dashboard changes
this repo has no way to make or verify on its own:

1. **DNS**: a record for `dibs-demo.ac495.net` pointing at this Cloudflare
   Tunnel, alongside your other `*.ac495.net` entries.
2. **Access policy**: an explicit bypass/exclude policy scoped to
   `dibs-demo.ac495.net` so it does *not* inherit whatever Access policy
   currently gates `*.ac495.net` generally. Skipping this step means visitors
   hit your Access login wall instead of the demo.

Once both are done, `docker-compose.demo.yml`'s Traefik labels (Docker
label auto-discovery, matching how the real `dibs.ac495.net` router is
already labeled in your `docker-compose.override.yml`) handle the rest --
no Traefik file-provider edit needed since this container runs on the same
host Traefik itself does.
