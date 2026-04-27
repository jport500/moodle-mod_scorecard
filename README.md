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

**v0.4.0 — Phase 4 (Reporting) shipped 2026-04-27.** Managers can now
review submitted attempts in a paginated report with per-attempt
expandable detail, group filter integration, and CSV export. Phases
1–3 (skeleton, authoring, learner submission) shipped previously;
the plugin remains MATURITY_ALPHA — gradebook integration (Phase 5a)
and full backup/privacy provider (Phase 5b) remain planned, so
operators relying on grade flow should wait for those releases.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 — Skeleton | Install schema, capabilities, mod_form, view, privacy provider scaffold, settings-only backup/restore, skeleton tests | shipped v0.1.0 |
| 2 — Authoring | Manage screen with Items + Bands tabs, CRUD, soft-delete, reorder, band coverage validation, lifecycle gate | shipped v0.2.0 |
| 3 — Learner submission | Submission form, validation, attempt + response save, scoring engine, band matching with snapshotting, result page, retake callout | **shipped v0.3.0** |
| 4 — Reporting | Reports tab, expandable detail, CSV export, group-mode awareness, pagination | **shipped v0.4.0** |
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
   modules > Scorecard. Expected version: `2026042700`.

Requires Moodle `2024100100` or later (Moodle 4.5+; tested on 5.1.3).

## Configuration

Activity-level settings are described in `docs/SPEC.md` §4.1. Phase 1
exposes the full settings table:

- Rating scale: `scalemin`, `scalemax`, anchor labels (low/high)
- Result behavior: show result, show percentage, show item summary,
  fallback message (pre-populated at activity creation per §4.1.1)
- Gradebook integration toggle (gradebook write happens in Phase 5a)
- Standard Moodle activity options (visibility, group mode, etc.)

Item authoring and result-band configuration ship in Phase 2 — see
the **Authoring** section below.

## Authoring

The Manage screen at `manage.php?id=<cmid>` lets a teacher define the
scored prompts and result bands that drive a scorecard. Open it from
the activity view's "Add items and result bands" link, visible to
users with `mod/scorecard:manage`. Non-managers are redirected to the
activity view with a clear error notice.

### Items tab

Each item is a single scored prompt — typically one sentence. Add an
item with the **Add an item** button and fill in:

- **Prompt** (required). The question or statement the learner
  responds to. Rich-text editor; one prompt per fieldset on the
  learner submission page (Phase 3).
- **Low / high anchor labels** (optional). Override the activity-level
  scale anchors for this specific item — useful when a particular
  prompt's "1" or "10" should read differently from the rest.
- **Visible to learners**. Uncheck to keep an item as a draft (hidden
  from learners, excluded from scoring) while you continue building.

Reorder items with the up / down arrows on the list. Edit and delete
actions live to the right of each row.

### Bands tab

A result band maps a range of total scores to a label and an
interpretive message. Bands display by minimum score ascending — the
natural numeric order learners encounter at score time.

- **Minimum / maximum score**. Inclusive bounds. Both required.
- **Label** (required). Short result name like "Strong" or
  "Concerning". Shown alongside the learner's total at result time.
- **Result message**. Optional rich-text interpretation rendered under
  the label on the result page.

The Bands tab surfaces coverage problems before attempts start
landing:

- **Overlap blocks save.** Two bands covering the same score (e.g.
  5–20 and 15–25 both covering 15–20) produce an inline error on the
  offending field, naming the sibling and the overlap range. Editing
  a band excludes itself from the check, so re-saving a band without
  changes never trips this.
- **Gap warns.** Score ranges no band covers appear as a yellow
  warning at the top of the list ("Uncovered score ranges: 21–29,
  41–50"). Gaps do not block save — uncovered scores fall through to
  the per-instance fallback message set on the activity edit page.
- **No items yet.** Before any visible items exist, gap detection is
  suppressed and the tab nudges you to add items first; the
  theoretical score range is undefined without items.

### Lifecycle: what changes after the first attempt

Once a learner submits an attempt (Phase 3), some edits become locked
or warned to keep historical attempt scoring stable:

- **Items.** Editing prompt and anchors stays available. Delete
  becomes soft-delete: the row is retained with a "(deleted)" badge
  and strikethrough so historical attempt detail (Phase 4 reports)
  can still resolve the prompt text. Adding new items is allowed,
  but the post-save notification warns that historical attempts will
  not include the new item.
- **Bands.** Editing label and message stays available. Delete
  becomes soft-delete (same reason as items).
- **Activity scale.** `scalemin` / `scalemax` are locked. The activity
  edit form rejects scale changes with a pointer to duplicating the
  activity if a different scale is needed.

### Reports tab

Manager reports shipped in v0.4.0 — see the [Reports section](#reports)
below for the full feature description. The Reports tab on manage.php
redirects to the report page (`report.php`) for users with
`mod/scorecard:viewreports`.

## Learner experience

Once a teacher has authored items and bands, the activity is usable
end-to-end for learners. This section describes what learners see
and which activity-level settings shape the experience.

### Submission form

A learner with `mod/scorecard:submit` lands on the activity view and
sees one fieldset per visible non-deleted item. Each fieldset carries
the item's prompt above a row of radio inputs spanning the activity's
`scalemin` to `scalemax`, with the activity's low / high anchor
labels (or per-item overrides if set) flanking the row. The submit
button posts to `submit.php` with the standard Moodle sesskey.

Items hidden via the "Visible to learners" toggle never appear on
the form. Items soft-deleted between page render and submit are
handled by the validation pipeline: their submitted responses still
write to the audit table, but they don't contribute to the score.

### Validation behaviour

The submission handler validates server-side (clients can disable the
form's `required` attribute) and **collects all errors** before
re-rendering — the learner sees every problem on a single
re-render rather than fix-one-then-resubmit:

- **Missing response on a visible item** — inline error above that
  fieldset asking the learner to answer.
- **Out-of-range value** (POST manipulation) — inline error on the
  fieldset; the activity scale is enforced.
- **Itemid not belonging to this scorecard** (form-level POST
  injection) — single notice at the top of the page asking the
  learner to reload and try again.
- **Every visible item soft-deleted between render and submit** —
  form-level notice that the scorecard has no scorable items right
  now, with a pointer to contact the facilitator.

Selections are preserved across re-render so the learner only fixes
the flagged fieldsets.

### Result page

Shown after submit (with retakes off and `showresult` on) or on any
revisit while no retakes are allowed. The page reads only from
**snapshot fields** captured on the attempt row at submit time, so a
band edited or deleted afterwards never shifts what the learner
sees:

- **Score line** — "Your score: X out of Y", always shown.
- **Percentage** — shown only when the activity's `showpercentage`
  setting is on; rounded to integer for display.
- **Band heading + interpretive message** — the heading shows when
  the attempt matched a band and the band's label is non-empty; the
  message body shows when the band's message is non-empty. A band
  authored with a label but an empty message renders the heading
  without a body, intentionally — that's not the fallback path.
- **Fallback** — when no band matched the attempt's score, the
  activity's per-instance fallback message renders without a
  heading.
- **Item summary** — shown only when the activity's
  `showitemsummary` setting is on; collapsed by default in a
  `<details>` element. Each row shows the prompt and the learner's
  response. Items soft-deleted between submit and revisit render
  with a strikethrough and "(deleted)" badge so the learner sees
  what they actually answered, not what's currently configured.

### Retake handling

When `allowretakes` is on and the learner has a prior attempt, the
view shows a compact **previous-attempt callout** above the form:
the submission timestamp, score, and band label (or "No band match"
on the fallback path). The form itself starts blank — retakes don't
pre-populate from the previous attempt — so each retake is a
deliberate response.

The callout intentionally shows score and band even when
`showresult` is off. `showresult` was specified to gate the
post-submit results page, not all references to past performance;
suppressing the score in the callout would produce a confusing "you
submitted before but we won't tell you anything about it" UX.
Operators wanting full result-blackout should also disable
`allowretakes` (one attempt, no result revealed in the activity).

### Settings reference (learner-visible effects)

- **`showresult`** — gates the post-submit results page. Off ⇒ the
  learner sees a "result not shown" notice instead of the result
  page on revisit.
- **`showpercentage`** — gates the percentage line on the result
  page (and only there).
- **`showitemsummary`** — gates the collapsed per-item summary on
  the result page.
- **`allowretakes`** — controls whether revisiting the activity
  shows the result page (off) or the form with a previous-attempt
  callout (on).

### What's not yet in the learner experience

Gradebook integration lands in Phase 5a — submitted attempts compute
a `totalscore` and store it on the attempt row, but the score isn't
yet propagated to Moodle's gradebook even when `gradeenabled` is on.
Multiple-attempt history with per-attempt drill-down lives in the
manager-only [Reports](#reports) surface; learners themselves see
only their latest attempt's result.

## Reports

The Reports tab at `report.php?id=<cmid>` lets a manager review
submitted attempts for a scorecard. The view is gated by
`mod/scorecard:viewreports`; users without it are redirected to the
activity view with a clear error notice. Open it from the Reports
tab on manage.php (which redirects here) or directly via URL.

### Attempts table

Submitted attempts render in a paginated table. Columns: name,
identity fields (per the site's standard identity-fields
configuration), attempt number, submitted timestamp, total score,
max score, percentage, band label.

Each row carries an expandable `<details>` summary block surfacing
per-item response detail. Items soft-deleted between submit and
review render with a `[deleted]` prefix; out-of-range responses
flag with a red audit suffix. Out-of-range values are only possible
via direct DB tampering or backup/restore mismatch — SPEC §4.5
blocks scale changes once attempts exist — but defensive flagging
remains valuable for audit.

### Group filter

The standard Moodle group selector renders above the table. Users
with `moodle/site:accessallgroups` see the full group list; other
users see only their own groups. The selection persists across
pagination — navigating between pages retains the active filter.
Changing the group filter resets to page 1.

When a specific group is selected and that group has no attempts,
the table renders the "No attempts in the selected group." empty
state instead of the generic "No attempts have been submitted yet."
copy.

### CSV export

The "Export CSV" button above the table is visible to users with
`mod/scorecard:export`. The export capability is separate from
`:viewreports` per SPEC §9.1 — operators may grant on-screen viewing
without download (audit context).

The export is filter-aware: if a group filter is active, only that
group's attempts export. Identity fields are included per the site's
identity-fields configuration; per-item response columns include all
items ever referenced by any attempt in scope (live items first by
sortorder, then deleted items at the end).

Filename format: `scorecard-{shortname}-attempts-{YYYYMMDD-HHMMSS}.csv`.

The button is hidden when no attempts match the active filter (empty
CSV downloads aren't a real use case). Direct URL navigation to
`export.php` when no attempts match redirects back to the report
page with a notification.

### Pagination

Default page size is 25 attempts per page. Pagination chrome (page
links, "Previous"/"Next") renders above and below the table when
total attempts exceed page size. Per-pagination-page response fetch:
when navigating between pages, only the visible page's
attempt-detail responses are fetched, so bandwidth scales with page
size rather than total attempts.

The initials filter (A–Z) is intentionally disabled.

## Running tests

PHPUnit init (one-time per Moodle instance):

```
ddev exec php /var/www/html/moodle/public/admin/tool/phpunit/cli/init.php
```

Run the full mod_scorecard suite via explicit file list:

```
ddev exec bash -c 'cd /var/www/html/moodle && vendor/bin/phpunit \
    public/mod/scorecard/tests/lib_test.php \
    public/mod/scorecard/tests/db_install_test.php \
    public/mod/scorecard/tests/locallib_test.php \
    public/mod/scorecard/tests/locallib_band_test.php \
    public/mod/scorecard/tests/locallib_band_coverage_test.php \
    public/mod/scorecard/tests/lifecycle_test.php \
    public/mod/scorecard/tests/learner_render_test.php \
    public/mod/scorecard/tests/scoring_test.php \
    public/mod/scorecard/tests/submission_test.php \
    public/mod/scorecard/tests/result_render_test.php'
```

Or run an individual test file:

```
ddev exec bash -c 'cd /var/www/html/moodle && vendor/bin/phpunit public/mod/scorecard/tests/lib_test.php'
```

> **Note on the directory-path form.** `vendor/bin/phpunit
> public/mod/scorecard/tests/` silently runs zero tests on Moodle 5.1
> (returns "No tests executed!" with exit 0) — Moodle's bundled
> `phpunit.xml` testsuite paths cover core + standard mod plugins only,
> and PHPUnit 11's stricter testsuite resolution doesn't fall back
> when a contrib directory is passed directly. Use the explicit file
> list above; in CI, assert on the test-count line ("OK (N tests, M
> assertions)"), not just exit code.

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
  specification (v0.4, unchanged through v0.4.0; sha256-verified
  against the canonical raw URL on commit).
- **Release notes:** [`CHANGES.md`](CHANGES.md).

## License

GPL-3.0-or-later. See [`LICENSE`](LICENSE).
