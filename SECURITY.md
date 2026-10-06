# Security Policy

This app accepts a GitHub personal access token and exposes write operations (create, revise, comment, claim, complete) through its local MCP server, so please report security issues privately rather than opening a public issue.

## Reporting a Vulnerability

Use GitHub's private vulnerability reporting:

1. Go to the [Security tab](https://github.com/loki495/dibs/security) of the repository, or open
   [a new advisory](https://github.com/loki495/dibs/security/advisories/new) directly.
2. Click **Report a vulnerability**.
3. Include as much detail as you can: steps to reproduce, affected version/commit, and potential impact.

If you can't use GitHub, email andres@ac495.net. Dibs is an alpha: only the latest commit on `main` is supported.

You should receive an acknowledgement within a few days. Please don't disclose the issue publicly until it's been addressed.

## Scope

This is a personal-use, self-hosted application. `GITHUB_TOKEN` is read server-side only and never appears in a tool argument, MCP response, or browser bundle — see the README's "Connecting to GitHub" section for the exact least-privilege scopes it needs. Reports involving GitHub token exposure, MCP tool authorization/claim identity spoofing, or push-queue conflict handling are especially appreciated.

## Trust model

Dibs is not a sandbox. The Docker dev containers run as the checkout's owner, write the checkout
and can see your processes; the MCP server has no authentication beyond who can start it; and
mirrored GitHub issues and comments reach your agents as text they may act on. Read
[docs/security-model.md](docs/security-model.md) before you deploy: it covers each deployment type
and every known attack surface, with what Dibs does about it and what you should do.
