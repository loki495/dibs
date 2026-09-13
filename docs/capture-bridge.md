# Host capture bridge

Status: the SSH mechanism is built and verified end to end (2026-09-13). The Dibs-side
feature that uses it (a natural-language capture UI, settings/opt-in, the DB-backed
request table) is not yet built — see plan issue #53 (nested under #64).

## Why this exists

Plan #53's natural-language capture feature needs an LLM to structure free text into a
draft task. Rather than add a new metered API key (Anthropic/OpenAI), it reuses the CLI
agent tools already authenticated on this machine (`claude`, `codex`, `agy`, `opencode`)
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
   command="/usr/bin/php /home/andres/www/dibs/docker/ssh/run-capture-agent.php",no-agent-forwarding,no-port-forwarding,no-pty,no-user-rc,no-X11-forwarding ssh-ed25519 <key> dibs-capture-bridge
   ```
   Whatever command the SSH client asks for is ignored by sshd and this script always
   runs instead — the client's request only reaches the script via the
   `SSH_ORIGINAL_COMMAND` environment variable, which the script treats as untrusted
   input (validated against an allow-list, never passed to a shell).
3. The container connects as: `ssh -i docker/ssh/capture_bridge_ed25519 -p 22222
   andres@<docker-bridge-gateway-ip>`. sshd listens on `0.0.0.0:22222`, and the
   container's own Docker bridge gateway IP (e.g. `172.23.0.1` for the `todo_default`
   network — check with `docker network inspect todo_default`) reaches the host without
   any extra Docker networking config.
4. The SSH client sends the agent name as the (ignored, but read via
   `SSH_ORIGINAL_COMMAND`) remote command, and a JSON request on stdin:
   `{"prompt": "...", "schema": {...}|null}`.
5. `docker/ssh/run-capture-agent.php` (host-side, plain PHP, no framework — same style
   as sessioneer's host-agent scripts) dispatches to a per-agent function, invokes that
   CLI one-shot via `proc_open` with an argv array (never shell-interpolated), extracts
   the model's final text reply from that CLI's own JSON output shape, and parses it as
   the draft JSON. Response on stdout: `{"ok": true, "draft": {...}}` or
   `{"ok": false, "error": "..."}`.

## Per-agent invocation and output shape (confirmed empirically 2026-09-13)

| Agent | Command | Output shape | How the reply is extracted |
|---|---|---|---|
| `claude` | `claude -p <prompt> --output-format json [--json-schema <file>]` | JSON array of stream events | last element, `type == "result"`, its `.result` string |
| `codex` | `codex exec <prompt> --json --skip-git-repo-check --sandbox read-only [--output-schema <file>]` | NDJSON | last `item.type == "agent_message"` event's `.item.text` |
| `agy` | `agy --output-format json [--json-schema <file>] --print <prompt>` | single JSON object | `.response` (or `.error` on failure, e.g. `RESOURCE_EXHAUSTED` quota errors) |
| `opencode` | `opencode run <prompt-with-schema-described-inline> --format json` | NDJSON | last `part.type == "text"` event's `.part.text` (no schema-enforcement flag exists, so the schema is described in the prompt itself and validated on our side) |

`codex` needed `--skip-git-repo-check` (the forced-command session's cwd isn't a
trusted git repo) and `--sandbox read-only` (defense in depth — this is a pure text
completion, it should never need to run a shell command at all).

Binaries are referenced by **absolute path** in the script
(`/home/andres/.local/bin/claude`, etc.) — a forced-command SSH session doesn't source
the interactive shell's profile, so PATH is minimal and won't resolve
`~/.local/bin`/`~/.opencode/bin` entries.

## Verified 2026-09-13

Live end-to-end through the real bridge (container → SSH → host script → CLI → parsed
JSON back): `codex` and `opencode` both confirmed working. `claude`'s output shape was
confirmed via a direct standalone call (not yet re-run through the bridge itself). `agy`
is currently quota-exhausted (`RESOURCE_EXHAUSTED`, resets ~2026-09-18) — its bridge
path is built and its error case is exercised by the same code path that already
handled codex's `--skip-git-repo-check` failure cleanly, but it hasn't returned a real
success through the bridge yet.

## Setting this up on a fresh machine

1. `ssh-keygen -t ed25519 -N "" -f docker/ssh/capture_bridge_ed25519` (the `docker/ssh/`
   directory is gitignored).
2. Append the forced-command line above (with this machine's own paths) to
   `~/.ssh/authorized_keys`.
3. Confirm `openssh-client` is in the app image (`docker/setup-dev-container.sh`) and
   rebuild.
4. Update `AGENT_BINARIES` in `docker/ssh/run-capture-agent.php` if any CLI lives
   somewhere other than the paths above.
5. Confirm sshd's actual port and that it's reachable from the container's Docker
   bridge gateway IP (`docker network inspect <network> --format
   '{{range .IPAM.Config}}{{.Gateway}}{{end}}'`).
