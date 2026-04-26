# mod_scorecard

A Moodle activity module for scored self-assessments. Learners answer
scored prompts on a numeric scale, and the activity calculates a total
score, displays a result band with interpretive copy, and optionally
writes the score to the gradebook.

Designed for self-assessments, readiness checks, coaching tools,
diagnostics, and similar professional training workflows where each
response has a numeric value and the learner receives an immediate
total score plus interpretation.

## Status

**v0.1.0 — Phase 1 (Skeleton) shipped 2026-04-25.** This is an alpha
release. The plugin installs, registers a Scorecard activity in the
chooser, persists the activity-level settings, and declares the data
model. **It is not yet user-facing**: item authoring, learner
submission, scoring, reporting, gradebook integration, and full
backup/restore land in subsequent phases.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 — Skeleton | Install schema, capabilities, mod_form, view, privacy provider scaffold, settings-only backup/restore, skeleton tests | **shipped v0.1.0** |
| 2 — Authoring | Manage screen with Items + Bands tabs, CRUD, soft-delete, reorder, band coverage validation | planned |
| 3 — Learner submission | Submission form, validation, attempt + response save, scoring engine, band matching with snapshotting, result page | planned |
| 4 — Reporting | Reports tab, expandable detail, CSV export, group-mode awareness | planned |
| 5a — Completion + gradebook | Activity completion, gradebook integration, per-tenant theming hooks | planned |
| 5b — Backup + privacy | Full backup/restore (items, bands, attempts, responses with snapshot fidelity), privacy provider implementation | planned |

## Installation

Standard Moodle plugin install:

1. Place the `scorecard` directory under `<moodleroot>/public/mod/`
   (or `<moodleroot>/mod/` on pre-public docroot installs).
2. Visit Site administration > Notifications, or run the CLI upgrade:

   ```
   php admin/cli/upgrade.php --non-interactive
   ```

3. Confirm install at Site administration > Plugins overview > Activity
   modules > Scorecard. Expected version: `2026042500`.

Requires Moodle `2024100100` or later (Moodle 4.5+; tested on 5.1.3).

## Configuration

Activity-level settings are described in `docs/SPEC.md` §4.1. Phase 1
exposes the full settings table:

- Rating scale: `scalemin`, `scalemax`, anchor labels (low/high)
- Result behavior: show result, show percentage, show item summary,
  fallback message (pre-populated at activity creation per §4.1.1)
- Gradebook integration toggle (gradebook write happens in Phase 5a)
- Standard Moodle activity options (visibility, group mode, etc.)

Item authoring and result-band configuration ship in Phase 2.

## Running tests

PHPUnit init (one-time per Moodle instance):

```
ddev exec php /var/www/html/moodle/public/admin/tool/phpunit/cli/init.php
```

Run mod_scorecard tests via direct path (the canonical invocation that
works regardless of Moodle's phpunit.xml testsuite configuration):

```
ddev exec bash -c 'cd /var/www/html/moodle && vendor/bin/phpunit public/mod/scorecard/tests/'
```

Or run an individual test file:

```
ddev exec bash -c 'cd /var/www/html/moodle && vendor/bin/phpunit public/mod/scorecard/tests/lib_test.php'
```

> **Note on `--filter`.** `vendor/bin/phpunit --filter mod_scorecard ...`
> returns "No tests executed!" because Moodle's bundled `phpunit.xml`
> testsuite paths cover core + standard mod plugins only; contrib
> plugins' `tests/` directories must be addressed by direct path. Use
> the path-based invocation above.

Plugin-wide phpcs:

```
ddev exec bash -c '~/.composer/vendor/bin/phpcs --standard=moodle /var/www/html/moodle/public/mod/scorecard/'
```

## Per-tenant theming

CSS custom properties (`--scorecard-*`) are reserved in `docs/SPEC.md`
§10. Phase 5a ships `styles.css` with the actual properties and
sensible Boost-compatible defaults. Tenant themes override the
`--scorecard-*` values without modifying plugin files.

## Roadmap

See `docs/SPEC.md` §15 for the full build plan and §14 for the v1.1
roadmap (drag-drop reorder, optional items, per-item weighting,
reverse scoring, categories/subscores, save-draft, charts, PDF
export, AI-assisted item generation).

## References

- **LMS Light project context:** [`lms-light-docs/CONTEXT.md`](https://github.com/jport500/lms-light-docs/blob/main/CONTEXT.md)
  — deployment model, custom-plugin ecosystem, supervised-agentic
  development conventions.
- **LMS Light portable lessons:** [`lms-light-docs/LESSONS.md`](https://github.com/jport500/lms-light-docs/blob/main/LESSONS.md)
  — process patterns and failure modes accumulated across plugin work.
- **Specification:** [`docs/SPEC.md`](docs/SPEC.md) — current plugin
  specification (v0.3 as of v0.1.0 release).
- **Release notes:** [`CHANGES.md`](CHANGES.md).

## License

GPL-3.0-or-later. See [`LICENSE`](LICENSE).
