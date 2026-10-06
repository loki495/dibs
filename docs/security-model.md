# Security model

Dibs is a self-hosted, single-owner tool. It holds your tasks, optionally a write-capable GitHub token,
and it hands text to coding agents that run with your own permissions. This page says what it trusts
in each kind of deployment and lists every attack surface we know of: the attack, what it needs, what
Dibs already does about it, and what you should do. To report a vulnerability, see
[SECURITY.md](../SECURITY.md).

## Trust model by deployment type

| | Docker dev checkout (`docker-compose.yml`) | Production image (`docker/Dockerfile.prod`) | Bare metal ([guide](bare-metal.md)) |
|---|---|---|---|
| Code | Your checkout, bind-mounted read-write into every container | Baked into the image | Your checkout |
| Runs as | `www-data` remapped to `DIBS_UID`/`DIBS_GID`, normally your login user | Apache workers as `www-data` (UID 33); the entrypoint's Artisan calls too. The demo's `scheduler` service runs as root | Whatever user you start it as |
| Host processes visible | `app` shares the host PID namespace; `web` and `scheduler` don't | No | Yes, like any process of that user |
| Claim liveness | Verified (in `app`) | Unverifiable; claims expire with their lease | Verified unless `/proc` is mounted `hidepid` |
| Meant for | Your own machine | A server, or the public demo | Your own machine or server |

The common thread: **the Docker dev setup is not a sandbox.** Its containers run as you, write your
checkout and can see your processes. Treat them as part of your own account. The production image is
the only layout where a compromised web tier stays inside a container.

## Attack surfaces

### Prompt injection through imported GitHub content

- **Attack.** Someone writes instructions into an issue or a comment on the mirrored repository. An
  agent reads them through `todo_show`, `todo_list` or the plan bundle and follows them, with the
  agent's own permissions (shell, files, other credentials), which are far wider than Dibs's.
- **Preconditions.** GitHub mirroring is on, and the attacker can open issues or comment on the mirror
  repository. On a public repository that is anyone with a GitHub account. Dibs imports issues and
  comments from every author.
- **Mitigations today.** None in the app. Comments keep their `author_login`, but agents aren't told
  to distrust them.
- **What to do.** Mirror to a **private** repository, or one where only you can open issues and
  comment. If it must be public, tell your agents (in their instructions or skill) to treat issue and
  comment text as data, never as instructions, and review what they act on.

### Owner auto-login and `TRUSTED_PROXIES`

- **Attack.** With `AUTO_LOGIN_LAN=true`, any request from a private address with no Cloudflare edge
  header is signed in as `AUTO_LOGIN_EMAIL`. If a stranger's request looks like that, they get your
  account.
- **Preconditions.** Auto-login is on (it is off by default), and one of: a reverse proxy in front of
  Dibs with `TRUSTED_PROXIES` blank (every proxied request then comes from the proxy's private
  address); `TRUSTED_PROXIES=*` with Dibs reachable directly (a client can claim a LAN address in
  `X-Forwarded-For`); a public port-forward to Dibs or to a proxy in front of it; or an untrusted
  device on your LAN.
- **Mitigations today.** Requests through Cloudflare (tunnel or proxy) are never auto-logged-in. Only
  RFC 1918 and IPv6 unique-local addresses count as LAN; loopback, link-local and CGNAT/Tailscale
  ranges don't. With trusted proxies, the client address is the rightmost `X-Forwarded-For` entry
  that isn't itself a trusted proxy. The account is never created by auto-login, only signed in.
- **What to do.** Leave it off unless you control the whole network. If you enable it, set
  `TRUSTED_PROXIES` to your proxy's exact address, never `*`, and make sure only your tunnel and your
  LAN can reach Dibs. Details in the README's "Owner auto-login" section.

### The web login

- **Attack.** Password guessing, or a stolen session cookie.
- **Preconditions.** The login page is reachable by the attacker.
- **Mitigations today.** Login attempts are rate-limited (five failures per minute per email and
  address). Accounts are created only with `php artisan todo:user`; there is no sign-up page.
- **What to do.** Use a long password. Serve Dibs over HTTPS and set `SESSION_SECURE_COOKIE=true`
  there. Prefer putting it behind an access layer (VPN, Cloudflare Access) over exposing the login
  page to the internet.

### The read-write bind mount in the Docker dev setup

- **Attack.** Code execution in the `web` container (a bug in Dibs or a dependency) writes to the
  bind-mounted checkout: `app/`, `vendor/`, `.git/hooks/`, `composer.json` scripts. You then run that
  code on the host as yourself, the next time you commit, run Composer or start Artisan.
- **Preconditions.** A code-execution bug reachable from the web tier, and the dev compose file in use.
- **Mitigations today.** `web` doesn't share the host PID namespace, and HTTP is served only by `web`.
  That limits what it can see, not what it can write.
- **What to do.** Don't expose a dev checkout to networks you don't trust. For anything reachable from
  outside, use the production image (code baked in, no bind mount) or bare metal under a dedicated
  user that doesn't own code you run. Running the dev containers under another UID doesn't help here:
  they must own the checkout to work, so that user can still change code you run.

### `pid: host` and reading your processes' environment

- **Attack.** Code running in the `app` container lists every host process and reads
  `/proc/<pid>/environ` of processes owned by the same UID, which can hold API keys and tokens passed
  through environment variables.
- **Preconditions.** Code execution in `app` (the MCP server, the `todo:agent:*` CLI, the watcher, or
  anyone with `docker exec`), and `DIBS_UID` equal to your login user, which is the default.
- **Mitigations today.** Only `app` gets the host PID namespace. Its long-running process, the claim
  watcher, runs as `www-data`, reads only `/proc/<pid>/stat` of claimed PIDs and never signals them.
  Command lines are visible to any local user anyway on most systems.
- **What to do.** If this matters, either drop `pid: "host"` from `app` and set
  `DIBS_PROCESS_LIVENESS=false` there (claims then show "liveness unverifiable" and expire only with
  their lease), or run Dibs under a UID that isn't your login user (`DIBS_UID`/`DIBS_GID`, then
  rebuild and give that user the checkout). The second keeps `environ` out of reach but, as the
  previous section says, not your code.

### Docker access

- **Attack.** Anyone who can run `docker` on the host can `docker exec` into Dibs, read `GITHUB_TOKEN`
  from `.env`, write the database directly, or start a privileged container.
- **Preconditions.** Membership in the `docker` group, or rootful Docker socket access.
- **Mitigations today.** None; Docker access is root-equivalent on the host by design.
- **What to do.** Treat the `docker` group like `sudo`.

### The MCP server has no authentication

- **Attack.** Any local process that can start the MCP server can create, edit, close and claim tasks,
  and comment, as if it were your agent. It can also release or complete another agent's claim if it
  holds that claim's capability token.
- **Preconditions.** A shell as a user who can run `php artisan mcp:start todo` in the checkout, or
  `docker compose exec` into `app`.
- **Mitigations today.** The server speaks stdio only. It never listens on a network port, so there is
  nothing to reach remotely. A claim binds to the claiming agent's real OS process and returns a
  capability token that heartbeat, release and complete require. Claims are a coordination contract
  between cooperating agents, not an access control: anything that can run the server can also read
  the database.
- **What to do.** Treat the ability to start the MCP server as full access to Dibs. Don't expose it
  through a network bridge (an HTTP-to-stdio proxy) without adding authentication in front.

### The GitHub token's blast radius

- **Attack.** Whoever gets `GITHUB_TOKEN` can do what the token allows: with a classic `repo` token,
  read and write every repository you can, private ones included; with a fine-grained token, only what
  you granted.
- **Preconditions.** Read access to `.env`, to the process environment of a Dibs process, or to a
  backup that includes `.env`.
- **Mitigations today.** The token is read server-side only. It never appears in an MCP response, a
  tool argument or the browser bundle. Dibs itself only touches the one configured repository and its
  Projects.
- **What to do.** Use a **fine-grained** token scoped to the one repository (Issues: read and write)
  plus Projects: read and write, as the README's "Token scopes" section lists. Keep `.env` mode `600`.
  Remember that backups of `.env` contain it. Rotate it if any of the surfaces above was exposed.

### The public demo

- **Attack.** The demo is open to anyone. Visitors can try to reach real data or fill the server's disk.
- **Preconditions.** A demo deployment (`DIBS_DEMO_MODE=true`) reachable from the internet.
- **Mitigations today.** Each visitor gets a private SQLite copy of a seeded template, keyed by an
  encrypted cookie; nobody sees anyone else's edits. The demo has its own `.env` with no GitHub token, so
  it runs local-only and never contacts GitHub. `DB_DATABASE` points at a dedicated fallback, never a
  real database. Copies older than 24 hours are deleted daily. It runs from the production image, with
  no bind mount.
- **Known gap.** Every request without the demo cookie creates a new copy, so a script that discards
  cookies can fill the disk before the daily cleanup runs.
- **What to do.** Host the demo on its own checkout, ideally its own machine, never next to a real
  instance's `.env`. Rate-limit it at your proxy or CDN, and keep `storage/demo-dbs` on a volume with
  a size limit. See [demo-hosting.md](demo-hosting.md).

## Data at rest

`database/database.sqlite` holds every task, comment and claim; `.env` holds `APP_KEY` and the GitHub
token. Neither is encrypted by Dibs. Keep both readable only by the user Dibs runs as, and store
backups of them as carefully as the originals.
