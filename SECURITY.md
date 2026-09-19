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
