# Contributing to Dibs

Thanks for considering a contribution. Dibs is an experimental alpha, a personal project shared
publicly and maintained in spare time, so response times on issues and PRs vary. Contributions are
welcome.

## Getting set up

Follow the [README](README.md#quick-start) to get a local instance running with Docker Compose. On Linux
the images assume host UID 1000; the README explains the workaround for other UIDs.

## Before you open a PR

Target the `main` branch. CI runs one required check, **Pint, PHPStan, Rector, Pest**, which also runs
the browser suite. Run the same tools locally, in this order, so style and static-analysis problems
don't get mixed into a test-failure investigation:

| Check | Command |
|---|---|
| Code style (auto-fixes) | `composer pint` |
| Static analysis (PHPStan level 6) | `composer phpstan` |
| Modernization (dry-run only) | `composer rector` |
| Tests | `composer pest` |
| Browser tests | `composer pest:browser` |

These `composer` scripts are host-side wrappers around `docker compose exec`, so they need Composer on
your host and a running `app` container (`docker compose up -d`). Without Composer, use the raw form:

```bash
docker compose exec -T -u www-data app vendor/bin/pint
docker compose exec -T -u www-data app vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec -T -u www-data app vendor/bin/rector process --dry-run
docker compose exec -T -u www-data app vendor/bin/pest
docker compose --profile test run --rm app-test vendor/bin/pest tests/Browser
```

The browser suite runs in its own `app-test` container (Node.js and Chromium), built the first time you run it.

## What a good PR contains

- **One feature or fix per PR**, in focused commits. Commit subjects are imperative and specific
  ("Retry GitHub pushes with backoff that honours rate-limit headers", not "fixes" or "updated queue").
  Use the body for why, when it isn't obvious.
- **Tests with the change, happy and sad paths.** Cover validation failures, stale-revision conflicts,
  claim conflicts, push-queue failures and unauthorized access, and assert the specific handled outcome
  (a validation error, a conflict payload), not just "it didn't succeed". Tests use Pest and fake GitHub
  with `Http::fake()`; a test must never make a real network call.
- **Docs in the same commit.** If a change touches behavior, configuration or the agent surface, update
  every document it makes wrong: the README, `docs/` (`docs/agent-interface.md` is the contract for the
  MCP/CLI surface), `.env.example`, `skills/dibs/SKILL.md` and `CLAUDE.md`.
- **The existing architecture.** Livewire components, Artisan commands and MCP tools stay thin and delegate
  to typed Actions in `app/Actions/`. The UI, MCP and HTTP layers never write to the database themselves; an
  architecture test enforces it. `CLAUDE.md` describes the conventions in more detail.
- Don't weaken, skip or delete a test to make a build pass.

## Reporting bugs and suggesting features

Open a [GitHub issue](https://github.com/loki495/dibs/issues/new/choose) with enough detail to reproduce
(for a bug) or the problem you're trying to solve (for a feature request). Screenshots help for UI issues.

## Security issues

Don't open a public issue. Use GitHub's private vulnerability reporting, as described in
[SECURITY.md](SECURITY.md).

## Code of conduct

Be respectful and constructive. Disagreements about approach are fine; personal attacks aren't.
