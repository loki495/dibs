# Contributing to Dibs

Thanks for considering a contribution. Dibs is a personal project shared publicly, maintained
in spare time, so response times on issues and PRs may vary — but contributions are welcome.

## Getting set up

Follow the [README](README.md#quick-start) to get a local instance running with Docker Compose.

## Before you open a PR

Run the full check suite and make sure it's clean:

```bash
composer pint      # code style (auto-fixes)
composer phpstan    # static analysis
composer rector      # modernization, dry-run only
composer pest        # test suite
```

- Keep PRs focused — one feature or fix per PR is easier to review than a bundle of unrelated changes.
- Add or update tests for behavior changes (Pest). Sad paths (validation failures, conflicts,
  unauthorized access) matter as much as the happy path — see `CLAUDE.md` for the project's
  testing conventions.
- Match the existing code style and architecture: thin Livewire components/Artisan commands
  delegating to typed Actions, which are the layer both the UI and the MCP agent tools call
  into. `CLAUDE.md` covers this in more detail.
- If you're changing the MCP/CLI agent surface, `docs/agent-interface.md` is the contract —
  update it alongside the code.

## Reporting bugs / suggesting features

Open a GitHub issue with enough detail to reproduce (for a bug) or the problem you're trying to
solve (for a feature request). Screenshots help for UI issues.

## Code of conduct

Be respectful and constructive. Disagreements about approach are fine; personal attacks aren't.
