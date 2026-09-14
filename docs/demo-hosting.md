# Public demo hosting

How the `dibs-demo.ac495.net` instance works and how to stand it up. This is
infrastructure for one specific deployment, not a feature of the app itself --
`config('dibs.demo_mode')` defaults to `false` and every piece here is a no-op
on a normal install.

## Where it runs

On `media` (the always-on LAN box), not `work` (where the real
`dibs.ac495.net` runs) -- matching where the sibling `homie`/`insights` demos
already live (`~/demo/homie`, `~/demo/insights` on media), each its own full
git checkout deployed via a `docker-compose.prod.yml` + plain `.env`, never a
bind mount. Dibs' demo follows the identical layout at `~/demo/dibs` on media,
port `8112` (next free after homie's `8110` and insights' `8111`).

Cloudflare's tunnel on media routes `dibs-demo.ac495.net` directly to
`http://localhost:8112` on media itself -- it does not go through Traefik on
`work` at all for real public/remote traffic. Traefik's own entry for this
hostname (`~/www/traefik/dynamic/ac495-sites.yml` on `work`, pointing at
`http://<media-lan-ip>:8112`) is LAN-HTTPS convenience only, matching the
existing `homie-demo-ac495`/`insights-demo-ac495` entries there.

## Architecture

Adapted from `homie`'s equivalent demo setup, with one deliberate difference
explained below.

- **Per-visitor database isolation**, not one shared database: `ResolveDemoDatabase`
  (`app/Http/Middleware/ResolveDemoDatabase.php`) gives each visitor a private
  SQLite copy of a template, identified by a signed cookie (`demo_instance_id`).
  Two people clicking around the demo at once never see or clobber each other's
  edits -- there is no shared mutable state to reset or protect.
- **The template** (`storage/demo-template.sqlite`) is rebuilt automatically on
  every container boot (`docker/entrypoint-prod.sh` calls
  `php artisan demo:build-template` when `DIBS_DEMO_MODE=true`, migrating fresh
  + reseeding `DemoSeeder`) -- a fresh deploy always starts from a clean
  dataset, no manual step, no volume needed for it. It is never touched by a
  running request, only copied.
- **Daily cleanup**, not a scheduled reset: `demo:cleanup` (scheduled in
  `routes/console.php`, gated by `->when(fn () => config('dibs.demo_mode'))`)
  deletes per-visitor copies older than 24h from `storage/demo-dbs/`. It only
  ever touches `*.sqlite` files in that one configured directory -- there is no
  destructive "wipe everything" command running unattended anywhere in this
  design, unlike an earlier iteration of this same feature (see git history on
  this file's introducing commit if curious).
- **Deploy-baked image, not a bind mount**: `docker/Dockerfile.prod` bakes the
  app in (composer install --no-dev, npm build, no dev dependencies, no bind
  mount), unlike the dev-oriented `docker-compose.yml`/`docker/Dockerfile`
  used for local development. This is what makes a Watchtower image pull
  actually change what's running -- a bind-mounted dev image would keep
  serving whatever happens to be checked out on disk regardless of which
  image tag is "running."
- **Isolated env**: the demo's `.env` (in its own `~/demo/dibs` checkout on
  media) is a completely separate file from the real personal instance's
  `.env` on `work` -- there is no shared-directory risk of a real
  `GITHUB_TOKEN`/`DIBS_GITHUB_OWNER`/`DIBS_GITHUB_REPO` leaking into the demo,
  since they're different files on different machines entirely.

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

Container/project names are `dibs-demo-*`, not homie's bare `homie-app`/
`homie-scheduler` convention -- unlike homie, Dibs also has a separate real
personal instance, so keeping the demo's names unambiguous at a glance (e.g.
in `docker ps` on a shared host) is worth the deviation.

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

## Deploying (on media)

```bash
git clone git@github.com:loki495/dibs.git ~/demo/dibs   # first time only
cd ~/demo/dibs
cp .env.example .env
php -r "echo 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL;" >> .env  # or generate after first boot instead
# Edit .env: APP_ENV=demo, APP_URL=https://dibs-demo.ac495.net, APP_PORT=8112,
# DIBS_DEMO_MODE=true, DEMO_DB_TEMPLATE_PATH=/var/www/html/storage/demo-template.sqlite,
# DEMO_DB_STORAGE_PATH=/var/www/html/storage/demo-dbs, DB_DATABASE pointed at a harmless
# dedicated fallback path (not database/database.sqlite), GITHUB_TOKEN/DIBS_GITHUB_OWNER/
# DIBS_GITHUB_REPO left blank.
docker compose -f docker-compose.prod.yml up -d --build
curl http://127.0.0.1:8112/login   # should return the login page
```

No manual template-build step -- `docker/entrypoint-prod.sh` runs
`demo:build-template` automatically on the `app` container's boot.

To pick up a code change later: nothing manual needed. A push to `main` runs
CI, publishes `ghcr.io/loki495/dibs:demo`, and pings Watchtower on media (see
"Continuous deployment" below) -- `docker compose -f docker-compose.prod.yml
up -d --build` on media is only needed for a first-time setup or a manual
rebuild.

## Continuous deployment

Same pipeline as `homie`/`insights`, reusing the already-deployed shared
pieces (`~/dotfiles/cd-trigger`, Watchtower on media -- neither is
Dibs-specific, nothing to set up per-project there):

1. On push to `main`, after `quality` passes, `.github/workflows/ci.yml`'s
   `publish-ghcr` job builds `docker/Dockerfile.prod` and pushes
   `ghcr.io/loki495/dibs:demo` (+ a `:sha-<short>` tag for traceability) to
   GHCR.
2. It then calls `cd.ac495.net` (the `CD_TRIGGER_URL` repo secret --
   already set), which relays a validated, fire-and-forget request to
   Watchtower's own HTTP API on media (`continue-on-error: true`: a missed
   trigger just means Watchtower catches the new image on its next 24h poll
   instead, never worth failing an otherwise-successful publish over).
3. Watchtower (already watching every container on media, no
   per-project label/registration needed) pulls the new `:demo` tag,
   recreates `dibs-demo-app`/`dibs-demo-scheduler`, and
   `docker/entrypoint-prod.sh` rebuilds the demo template fresh on that
   boot.

**One manual one-time step**: a brand-new GHCR package defaults to
*private* on its first push, regardless of the repo's own visibility.
After the first successful `publish-ghcr` run, flip `dibs`'s new container
package to Public in GitHub's package settings (matching `homie`/
`insights`) -- Watchtower on media has no stored registry credentials, so a
private package pulls nothing.

## What was needed outside this repo (done)

- **Cloudflare Tunnel ingress** (`/etc/cloudflared/config.yml` on media,
  root-owned): `dibs-demo.ac495.net -> http://localhost:8112`, added before
  the `"*.ac495.net"` catch-all.
- **DNS**: not needed -- Pi-hole on media and the Cloudflare Tunnel already
  cover `*.ac495.net`.
- **Traefik LAN-convenience entry**
  (`~/www/traefik/dynamic/ac495-sites.yml` on `work`, a symlink into
  `~/dotfiles/traefik/`): added and verified live.
- **Access policy**: an explicit bypass/exclude policy scoped to
  `dibs-demo.ac495.net`, matching whatever `homie-demo`/`insights-demo`
  already use, so it doesn't inherit the Access policy that gates
  `*.ac495.net` generally.
