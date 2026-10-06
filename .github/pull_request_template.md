## What and why

## Checks

Run in this order (see CONTRIBUTING.md; raw `docker compose exec` forms work without Composer):

- [ ] `composer pint`
- [ ] `composer phpstan`
- [ ] `composer rector` (dry-run shows no changes)
- [ ] `composer pest`, and `composer pest:browser` if the UI changed
- [ ] Tests cover the happy path and the sad paths (validation, conflicts, failures)
- [ ] Docs updated in this PR (README, `docs/`, `.env.example`, `skills/dibs/SKILL.md`, `CLAUDE.md`) where behavior changed
