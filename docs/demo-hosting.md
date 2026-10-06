# Hosting a public demo

How to run a public, throwaway demo of Dibs, like the one at `dibs-demo.ac495.net`.
This is deployment infrastructure, not a feature of the app itself:
`config('dibs.demo_mode')` defaults to `false` and every piece here is a no-op on a
normal install.

The examples below use these placeholders:

- `<demo-host>`: the public hostname of the demo, e.g. `dibs-demo.example.com`
- `<lan-ip>`: the LAN address of any reverse proxy that forwards to the demo from another machine
- `<redeploy-webhook>`: an optional URL that tells your host to pull the new image and restart

## Topology

The demo is its own full git checkout, deployed with `docker-compose.prod.yml` and a
plain `.env`, never a bind mount. Keep it separate from any real Dibs instance: a
separate directory at least, and ideally a separate machine. It listens on
`APP_PORT` (default `8112`) on the host.

Put whatever terminates TLS for `<demo-host>` (a tunnel, a reverse proxy) in front of
`http://localhost:<APP_PORT>`. If a second reverse proxy on another machine also
forwards to the demo over the LAN, it reaches it at `http://<demo-machine-lan-ip>:<APP_PORT>`;
that proxy's address is the `<lan-ip>` you must trust (see `TRUSTED_PROXIES` below).

If the hostname sits behind an access-control layer that gates the rest of your
domain, give `<demo-host>` an explicit bypass. The demo is meant to be open.

## Owner convenience: skip the login form (opt-in)

Off by default, and works on any Dibs deployment, not just the demo. `AUTO_LOGIN_EMAIL` names an existing account
to sign in as (the demo defaults to `demo@example.com`). `AUTO_LOGIN_LAN=true` signs it in for requests that carry
no Cloudflare edge header (`CF-Connecting-IP`/`CF-Ray`) and come from a private address; only enable it when nothing
but your tunnel and your LAN can reach the app, since that is what makes "no Cloudflare header" mean "on the LAN".
Requests arriving through Cloudflare are never auto-logged-in. The private-address check uses Laravel's client IP. Behind a reverse proxy
that is the proxy's own Docker address unless `TRUSTED_PROXIES` lists it, so set `TRUSTED_PROXIES` (below) before
enabling `AUTO_LOGIN_LAN`, and never set it to `*`. The README's "Owner auto-login" section has the full conditions.
See `AutoLoginForTrustedRequests`.

## Architecture

- **Per-visitor database isolation**, not one shared database: `ResolveDemoDatabase`
  (`app/Http/Middleware/ResolveDemoDatabase.php`) gives each visitor a private
  SQLite copy of a template, identified by a signed cookie (`demo_instance_id`).
  Two people clicking around the demo at once never see or clobber each other's
  edits. There is no shared mutable state to reset or protect.
- **The template** (`storage/demo-template.sqlite`) is rebuilt automatically on
  every container boot (`docker/entrypoint-prod.sh` calls
  `php artisan demo:build-template` when `DIBS_DEMO_MODE=true`, migrating fresh
  and reseeding `DemoSeeder`). A fresh deploy always starts from a clean
  dataset, with no manual step and no volume needed for it. It is never touched
  by a running request, only copied.
- **Daily cleanup**, not a scheduled reset: `demo:cleanup` (scheduled in
  `routes/console.php`, gated by `->when(fn () => config('dibs.demo_mode'))`)
  deletes per-visitor copies older than 24h from `storage/demo-dbs/`. It only
  ever touches `*.sqlite` files in that one configured directory. No destructive
  "wipe everything" command runs unattended anywhere in this design.
- **A separate fallback database**: `DB_DATABASE` points at a harmless dedicated
  path, never `database/database.sqlite`, so nothing outside the per-visitor
  copies is ever served.
- **Deploy-baked image, not a bind mount**: `docker/Dockerfile.prod` bakes the
  app in (composer install --no-dev, npm build, no dev dependencies), unlike the
  dev-oriented `docker-compose.yml`/`docker/Dockerfile` used for local
  development. This is what makes pulling a new image actually change what's
  running: a bind-mounted dev image would keep serving whatever is checked out
  on disk, whichever image tag is "running".
- **Isolated env**: the demo's `.env` is a completely separate file from any real
  instance's `.env`, so a real `GITHUB_TOKEN`/`DIBS_GITHUB_OWNER`/`DIBS_GITHUB_REPO`
  can never leak into the demo. With those left blank the demo runs local-only: `DemoSeeder` seeds
  into the local repository record, a visitor's new tasks and labels land there too, and nothing is
  ever queued for GitHub (the push-queue page shows only the seeder's own showcase rows).
- **Unambiguous names**: containers and the compose project are `dibs-demo-*`, so
  the demo can't be confused with a real instance in `docker ps` on a shared host.

### Why the middleware position matters

A demo with no login of its own (gated by stateless HTTP Basic Auth, say) can
resolve the visitor's database after Laravel's session middleware without harm.
Dibs authenticates with a real session-backed login. If the database were
resolved to the visitor's own copy *after* `StartSession` ran, their login
session would get written to whatever the default connection was at that
moment, not their own copy, breaking login on their very next request. So
`ResolveDemoDatabase` is positioned precisely between `EncryptCookies` and
`StartSession` via Laravel's middleware priority list (`bootstrap/app.php`),
not merely prepended or appended to the `web` group. Getting this wrong is
subtle: a naive `prependToGroup` also runs *before* `EncryptCookies`, so the
visitor-id cookie it reads is still encrypted ciphertext and never validates,
silently minting a brand new visitor identity on every single request. See
the comments in `bootstrap/app.php` and `ResolveDemoDatabase` itself.

The demo login is a normal Dibs account (`demo@example.com`, seeded by
`DemoSeeder`) that ships in every visitor's copy of the template, published
openly since the whole point is to let visitors in.

### Test-suite gotcha

`ResolveDemoDatabase` calls `DB::purge('sqlite')` to force a reconnect after
repointing the config. If a test exercises this middleware under
`RefreshDatabase` (active suite-wide, `tests/Pest.php`), that purge breaks
`RefreshDatabase`'s own teardown bookkeeping: it rolls back the wrong PDO,
leaves the real one's transaction dangling, and corrupts every later test in
the suite. `tests/Feature/DemoModeTest.php` works around it with an explicit
`beforeApplicationDestroyed` callback that rolls back the real tracked PDO
itself. If you write another test that exercises this middleware, copy that
workaround.

## Deploying

```bash
git clone https://github.com/loki495/dibs.git ~/dibs-demo   # first time only
cd ~/dibs-demo
cp .env.example .env
php -r "echo 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;" >> .env  # or generate after first boot instead
# Edit .env: APP_ENV=demo, APP_URL=https://<demo-host>, SESSION_SECURE_COOKIE=true, APP_PORT=8112,
# DIBS_DEMO_MODE=true, DEMO_DB_TEMPLATE_PATH=/var/www/html/storage/demo-template.sqlite,
# DEMO_DB_STORAGE_PATH=/var/www/html/storage/demo-dbs, DB_DATABASE pointed at a harmless
# dedicated fallback path (not database/database.sqlite), GITHUB_TOKEN/DIBS_GITHUB_OWNER/
# DIBS_GITHUB_REPO left blank. Then set TRUSTED_PROXIES (next section).
docker compose -f docker-compose.prod.yml up -d --build
curl http://127.0.0.1:8112/login   # should return the login page
```

No manual template-build step: `docker/entrypoint-prod.sh` runs
`demo:build-template` automatically on the `app` container's boot.

### TRUSTED_PROXIES: the mixed-content trap

`TRUSTED_PROXIES` is blank in `.env.example`. Behind a proxy it must list the
demo's own Docker network subnet, plus `<lan-ip>/32` for any LAN reverse proxy
that forwards to it:

```bash
docker network inspect dibs-demo_default --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
```

That network only exists after the first `up`, so set a placeholder first and
fix it afterwards. Get this wrong and Laravel doesn't believe the forwarded
`https` scheme: asset URLs render as `http://` on an `https://` page, the
browser blocks them as mixed content, and the page loads with no stylesheets or
scripts.

`env_file` values are baked in when a container is created, so editing `.env`
alone changes nothing. Run `docker compose -f docker-compose.prod.yml up -d` to
recreate the containers.

## Continuous deployment

1. On push to `main`, after `quality` passes, `.github/workflows/ci.yml`'s
   `publish-ghcr` job builds `docker/Dockerfile.prod` and pushes
   `ghcr.io/loki495/dibs:demo` (plus a `:sha-<short>` tag for traceability) to
   GHCR.
2. If the `CD_TRIGGER_URL` repository secret is set, the job then requests it
   (your `<redeploy-webhook>`). The step is `continue-on-error: true`: a missed
   trigger only delays the redeploy, never fails an otherwise-successful publish.
3. Whatever sits behind the webhook (for example an image-update service such
   as Watchtower, or a small script) pulls the new `:demo` tag and recreates
   `dibs-demo-app`/`dibs-demo-scheduler`. `docker/entrypoint-prod.sh` rebuilds
   the demo template fresh on that boot.

Without a webhook, redeploy by hand on the demo host:
`docker compose -f docker-compose.prod.yml pull && docker compose -f docker-compose.prod.yml up -d`.

**One manual one-time step**: a brand-new GHCR package defaults to *private* on
its first push, regardless of the repository's visibility. After the first
successful `publish-ghcr` run, set the container package to Public in GitHub's
package settings, or give the demo host registry credentials. Without one of
those, nothing can pull it.
