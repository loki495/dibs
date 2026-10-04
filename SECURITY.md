# Security Policy

This app accepts a GitHub personal access token and exposes write operations (create, revise, comment, claim, complete) through its local MCP server, so please report security issues privately rather than opening a public issue.

## Reporting a Vulnerability

Use GitHub's private vulnerability reporting:

1. Go to the [Security tab](../../security) of this repository.
2. Click **Report a vulnerability**.
3. Include as much detail as you can: steps to reproduce, affected version/commit, and potential impact.

You should receive an acknowledgement within a few days. Please don't disclose the issue publicly until it's been addressed.

## Scope

This is a personal-use, self-hosted application. `GITHUB_TOKEN` is read server-side only and never appears in a tool argument, MCP response, or browser bundle — see the README's "Connecting to GitHub" section for the exact least-privilege scopes it needs. Reports involving GitHub token exposure, MCP tool authorization/claim identity spoofing, or push-queue conflict handling are especially appreciated.

## Container trust model

Know these trade-offs before you run Dibs:

- **The `app` container shares the host's PID namespace** (`pid: "host"`). Claim liveness needs it: Dibs checks that a claiming agent's process still exists by reading `/proc/<pid>/stat`. The MCP server, the `todo:agent:*` CLI and the `dibs:claims:watch` loop run there; the loop is the container's long-running process, runs as `www-data` rather than root, and only reads `/proc/<pid>/stat` of claimed PIDs. It writes the result to the database for the UI and never signals or otherwise touches those processes. The `web` container, which serves HTTP, doesn't get it, so a bug in the web tier can't see host processes. Code running in `app` can list every host process. The image remaps `www-data` to UID 1000 (`docker/setup-dev-container.sh`) so the bind-mounted checkout stays writable. If that UID is your login user, `app` can likely also read `/proc/<pid>/environ` of your processes, including secrets passed through environment variables. Command lines (`cmdline`) are readable by any local user on most systems.
- **Anyone who can `docker exec` into these containers has full access to Dibs.** They can write to the database directly and read `GITHUB_TOKEN` from `.env`. Docker access is root-equivalent on the host anyway. Treat the containers as part of your own trusted account, not as a sandbox.

- **Owner auto-login (`AUTO_LOGIN_*`, off by default) trusts the network path, not a credential.** `AUTO_LOGIN_LAN` signs in any request from a private address with no Cloudflare header, and `AUTO_LOGIN_OWNER_EMAIL` trusts Cloudflare Access's email header as it arrives. Both assume Dibs can only be reached through the Cloudflare tunnel or from your LAN, and `AUTO_LOGIN_LAN` behind a reverse proxy also needs `TRUSTED_PROXIES` set to that proxy. See the README's "Owner auto-login" section. It runs in the `web` container, like the rest of HTTP.

Options if this matters to you:

- Drop `pid: "host"` from `app`, and set `DIBS_PROCESS_LIVENESS=false` there. Claims still work, but are recorded as unverified (`is_verified_live: false`). They are only reclaimed once their lease expires, never because their process died, and the UI shows them as "liveness unverifiable" (the watcher has nothing it can check).
- Run the containers under a UID that isn't your login user. Give that user ownership of the checkout's `storage/`, `bootstrap/cache/` and `database/`, instead of remapping `www-data` to UID 1000. Your processes' `environ` is then out of reach; command lines stay visible, as they are to any local user.
