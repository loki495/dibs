# Host capture bridge

Status: the natural-language capture feature is not built yet, and nothing in the app
calls this bridge. What exists is the SSH mechanism described here, verified end to end,
plus groundwork for the feature (the `CaptureSetting` opt-in row and the
`todo_capture_requests` table). The capture UI and the code that would queue requests and
call the bridge are planned, not built.

## Why this exists

The planned natural-language capture feature needs an LLM to structure free text into a
draft task. Rather than add a new metered API key (Anthropic/OpenAI), it reuses the CLI
agent tools already authenticated on the host (`claude`, `codex`, `agy`, `opencode`)
in one-shot, non-interactive, session-less mode — no separate billing, just their
existing subscription quota.

## The problem it solves

The Dibs app runs inside a Docker container. None of those four CLIs exist inside that
container — they're host-only. A plain `Process::run()` from Laravel can't reach them.

## How it works

```
Dibs container --SSH (forced command, restricted key)--> host wrapper script --> CLI agent
```

1. The container holds a **dedicated, restricted SSH key**
   (`docker/ssh/capture_bridge_ed25519`, gitignored — never commit it). It's bind-mounted
   automatically since the whole repo is already mounted into the container at
   `/var/www/html`.
2. The corresponding public key is in the host's `~/.ssh/authorized_keys`, forced to a
   single command with no shell, no pty, no forwarding:
   ```
   command="/usr/bin/php <repo>/docker/ssh/run-capture-agent.php",no-agent-forwarding,no-port-forwarding,no-pty,no-user-rc,no-X11-forwarding ssh-ed25519 <key> dibs-capture-bridge
   ```
   `<repo>` is the absolute path of your Dibs checkout on the host (e.g. `$HOME/dibs`).
   Whatever command the SSH client asks for is ignored by sshd and this script always
   runs instead — the client's request only reaches the script via the
   `SSH_ORIGINAL_COMMAND` environment variable, which the script treats as untrusted
   input (validated against an allow-list, never passed to a shell).
3. The container connects as: `ssh -i docker/ssh/capture_bridge_ed25519 -p 22222
   <user>@<docker-bridge-gateway-ip>`, where `<user>` is the host account that owns the
   CLI logins. The container's own Docker bridge gateway IP (e.g. `172.23.0.1` for the
   `dibs_default` network; check with `docker network inspect dibs_default`) reaches the
   host without any extra Docker networking config. Bind sshd for this to that gateway
   address (`ListenAddress <docker-bridge-gateway-ip>:22222`), not `0.0.0.0`, so the port
   is never exposed on the LAN or the internet.
4. The SSH client sends the agent name as the (ignored, but read via
   `SSH_ORIGINAL_COMMAND`) remote command, and a JSON request on stdin:
   `{"prompt": "...", "schema": {...}|null}`.
5. `docker/ssh/run-capture-agent.php` (host-side, plain PHP, no framework) dispatches
   to a per-agent function, invokes that CLI one-shot via `proc_open` with an argv array (never shell-interpolated), extracts
   the model's final text reply from that CLI's own JSON output shape, and parses it as
   the draft JSON. Response on stdout: `{"ok": true, "draft": {...}}` or
   `{"ok": false, "error": "..."}`.

## Per-agent invocation and output shape

| Agent | Command | Output shape | How the reply is extracted |
|---|---|---|---|
| `claude` | `claude -p <prompt> --output-format json [--json-schema <file>]` | JSON array of stream events | last element, `type == "result"`, its `.result` string |
| `codex` | `codex exec <prompt> --json --skip-git-repo-check --sandbox read-only [--output-schema <file>]` | NDJSON | last `item.type == "agent_message"` event's `.item.text` |
| `agy` | `agy --output-format json [--json-schema <file>] --print <prompt>` | single JSON object | `.response` (or `.error` on failure, e.g. `RESOURCE_EXHAUSTED` quota errors) |
| `opencode` | `opencode run <prompt-with-schema-described-inline> --format json` | NDJSON | last `part.type == "text"` event's `.part.text` (no schema-enforcement flag exists, so the schema is described in the prompt itself and validated on our side) |

`codex` needed `--skip-git-repo-check` (the forced-command session's cwd isn't a
trusted git repo) and `--sandbox read-only` (defense in depth — this is a pure text
completion, it should never need to run a shell command at all).

Binaries are referenced by **absolute path** in the script — a forced-command SSH session
doesn't source the interactive shell's profile, so PATH is minimal and won't resolve
`~/.local/bin`/`~/.opencode/bin` entries. The defaults are derived from `$HOME`, and each
can be overridden with an environment variable:

| Variable | Default |
|---|---|
| `DIBS_CAPTURE_CLAUDE_BIN` | `$HOME/.local/bin/claude` |
| `DIBS_CAPTURE_CODEX_BIN` | `$HOME/.local/bin/codex` |
| `DIBS_CAPTURE_AGY_BIN` | `$HOME/.local/bin/agy` |
| `DIBS_CAPTURE_OPENCODE_BIN` | `$HOME/.opencode/bin/opencode` |

sshd sets `HOME` for the forced command. To override a path, prefix the forced command
itself, since sshd passes no client environment through:
`command="DIBS_CAPTURE_CLAUDE_BIN=/opt/claude/bin/claude /usr/bin/php <repo>/docker/ssh/run-capture-agent.php",...`

## Verification status

Tested live through the real bridge (container → SSH → host script → CLI → parsed JSON
back): `codex` and `opencode`. `claude`'s output shape was confirmed with a direct
standalone call, not through the bridge. `agy`'s success path has not yet returned a real
reply through the bridge; its error path (e.g. a `RESOURCE_EXHAUSTED` quota error) is
handled by the same code that reports any CLI failure.

## Setting this up on a fresh machine

1. `ssh-keygen -t ed25519 -N "" -f docker/ssh/capture_bridge_ed25519` (the `docker/ssh/`
   directory is gitignored).
2. Append the forced-command line above (with this machine's own `<repo>` path) to
   `~/.ssh/authorized_keys`.
3. Confirm `openssh-client` is in the app image (`docker/setup-dev-container.sh`) and
   rebuild.
4. If any CLI lives somewhere other than the defaults above, set its
   `DIBS_CAPTURE_*_BIN` variable in the forced command.
5. Configure sshd to listen on the container's Docker bridge gateway IP and port 22222
   (not `0.0.0.0`), and confirm it's reachable from the container. Find the gateway with
   `docker network inspect dibs_default --format '{{range .IPAM.Config}}{{.Gateway}}{{end}}'`.
   That address only exists while the Docker network does, so sshd must start after
   Docker has created it, or the bind fails.
