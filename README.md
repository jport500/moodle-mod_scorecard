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

**v0.7.0 — Phase 6 (JSON templates) shipped 2026-04-28.** Operators
can now export a scorecard's authoring structure (items, bands,
settings) as a JSON template file, distribute it, and populate a
freshly-created empty scorecard with the imported items + bands via
the manage page's empty-state affordance. Templates capture
authoring structure only; user data (attempts, responses) lives in
backup/restore. The plugin remains MATURITY_ALPHA pending
earned-by-production-usage signal. Per-tenant theming hooks are
deferred to v1.x.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 — Skeleton | Install schema, capabilities, mod_form, view, privacy provider scaffold, settings-only backup/restore, skeleton tests | shipped v0.1.0 |
| 2 — Authoring | Manage screen with Items + Bands tabs, CRUD, soft-delete, reorder, band coverage validation, lifecycle gate | shipped v0.2.0 |
| 3 — Learner submission | Submission form, validation, attempt + response save, scoring engine, band matching with snapshotting, result page, retake callout | shipped v0.3.0 |
| 4 — Reporting | Reports tab, expandable detail, CSV export, group-mode awareness, pagination | shipped v0.4.0 |
| 5a — Completion + gradebook | Activity completion via "Submit a scorecard attempt" rule, gradebook integration with latest-attempt overwrites and auto-grademax. Per-tenant theming hooks deferred to v1.x. | shipped v0.5.0 |
| 5b — Backup + privacy | Full backup/restore (items, bands, attempts, responses with snapshot fidelity), privacy provider implementation | shipped v0.6.0 |
| 6 — JSON templates | Operator-facing template export from manage.php; populate-existing import flow on empty scorecards via the manage.php empty-state affordance | **shipped v0.7.0** |

## Installation

Standard Moodle plugin install:

1. Place the `scorecard` directory under `<moodleroot>/public/mod/`
   (or `<moodleroot>/mod/` on pre-public docroot installs).
2. Visit Site administration > Notifications, or run the CLI upgrade:

   ```
   php admin/cli/upgrade.php --non-interactive
   ```

3. Confirm install at Site administration > Plugins overview > Activity
   modules > Scorecard. Expected version: `2026042705`.

Requires Moodle `2024100100` or later (Moodle 4.5+; tested on 5.1.3).

## Configuration

Activity-level settings are described in `docs/SPEC.md` §4.1. Phase 1
exposes the full settings table:

- Rating scale: `scalemin`, `scalemax`, anchor labels (low/high)
- Result behavior: show result, show percentage, show item summary,
  fallback message (pre-populated at activity creation per §4.1.1)
- Gradebook integration toggle (gradebook write shipped in v0.5.0; see [Gradebook integration](#gradebook-integration) below)
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

Multiple-attempt history with per-attempt drill-down lives in the
manager-only [Reports](#reports) surface; learners themselves see
only their latest attempt's result. Gradebook propagation
([Gradebook integration](#gradebook-integration)) and activity
completion ([Completion](#completion)) shipped in v0.5.0 and surface
through Moodle's standard gradebook and completion UI rather than
through scorecard's own pages.

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

## Gradebook integration

When the activity-level `gradeenabled` toggle is on, scorecard
submissions propagate to the Moodle gradebook automatically. The
toggle defaults to off — most scorecards are self-assessments where
gradebook integration would be misleading rather than helpful (per
SPEC §9.2 default).

### Grade method: latest-attempt overwrites

Per SPEC §9.2 (Decision v0.4.2), each submission writes the
attempt's `totalscore` as the gradebook value for that user,
replacing any prior entry. Retakes therefore overwrite — the
gradebook always reflects the learner's most recent submission. This
matches mod_assign's convention. Highest/first/average grade methods
are deferred to v1.1+.

### Grade max: explicit or auto-computed

The `grade` activity setting controls grademax. Two modes:

- **Explicit grademax (`grade > 0`).** The set value is grademax
  directly. Use when the operator wants a fixed scale (e.g., "out of
  100" regardless of item count).
- **Auto-grademax (`grade = 0`, the default).** Grademax is computed
  as visible-item count × scalemax. Recomputes automatically on item
  add, remove, or visibility-toggle **while no attempts exist** (SPEC
  §9.2 lifecycle gate). After the first submission, grademax freezes
  to keep historical scoring stable (SPEC §11.2 snapshot rule applied
  to the grade item as well as the attempt row).

### Toggle behavior

Toggling `gradeenabled` from on to off sets the grade item's
gradetype to `GRADE_TYPE_NONE` rather than deleting it — the grade
column disappears from the gradebook view, but the underlying grade
history is preserved. Toggling back on reactivates with the current
`grade` value as grademax. Operators who toggle do not lose prior
gradebook entries.

Deleting the scorecard activity removes the grade item entirely.

## Completion

Scorecard activities support custom completion via the "Submit a
scorecard attempt" rule (per SPEC §9.3). When enabled, the activity
is marked complete for a user as soon as they have at least one
submitted attempt.

### One-way latch semantics

Completion is a one-way latch: any submission, ever, satisfies the
rule. Soft-deleted items, band edits, and similar mutations after
the submission do not un-complete a prior attempt. Retakes do not
change the completion state — the user remains "complete" because
they have submitted at least once.

### Default behavior

New scorecards default to `completionsubmit=1` in the activity edit
form (the form-level default). The natural completion criterion for
a self-assessment is "they submitted," so making this the friendly
default reduces operator setup friction.

Existing scorecards from v0.4.x default to `completionsubmit=0` on
upgrade (the schema floor). Operators see the new "Submit a
scorecard attempt" checkbox in the activity edit form and explicitly
opt in for existing scorecards. Asymmetric defaults reflect
asymmetric deployment-state assumptions: new scorecards get the
operator-friendly default, existing scorecards preserve prior
completion behavior.

### Integration with Moodle activity completion

The scorecard's completion rule integrates with Moodle's standard
activity completion UI: course-level completion criteria can include
"this scorecard is complete," completion reports show the rule
state, and the activity's standard "Done" checkmark appears on the
course page. View tracking (`FEATURE_COMPLETION_TRACKS_VIEWS`) is
also enabled — operators can require "view + submit" completion if
they configure both rules.

## Privacy

The scorecard plugin implements Moodle's Privacy API (per SPEC §9.5)
so operators can fulfil data-subject requests through Moodle's
standard tooling at **Site administration > Users > Privacy and
policies**. Prior to v0.6.0 the plugin held only a privacy provider
scaffold; v0.6.0 ships the full export and deletion implementation.

### What user data the plugin holds

Per-user submission data lives on `mdl_scorecard_attempts` (one row
per attempt: score trio, matched-band snapshot, timestamps) and
`mdl_scorecard_responses` (one row per item per attempt: the response
value, joined to the attempt and the item). Items, bands, and the
scorecard activity itself are author-defined content, not user data.

### Export

Running Moodle's standard data export for a user produces a record
per scorecard activity per attempt, including:

- The score trio (`totalscore`, `maxscore`, `percentage`) as
  recorded at submit time.
- The matched band's label and message at submit time, captured as
  snapshot fields (so a band edited or deleted afterwards never
  shifts what the export shows).
- Each per-item response paired with the prompt the learner saw.
  Items soft-deleted between submit and export render with a
  "(deleted item)" marker on the prompt — the response value is
  still included so the historical record stays complete.

### Deletion

Three deletion scopes are supported via Moodle's standard Privacy
tooling:

- **Delete data for a context** — removes all attempts and responses
  for a single scorecard activity. Use when wiping a course's
  submission history while keeping the activity structure available.
- **Delete data for a list of users in a context** — removes the
  named users' submissions for that activity; other learners' data
  is untouched.
- **Delete data for a single user across all contexts** — removes
  that learner's data from every scorecard activity they have
  submitted (right-to-be-forgotten compliance).

Items, bands, and scorecard activities themselves are never removed
by these flows — only user-submitted data. Privacy deletion does not
touch gradebook entries; operators wanting to also clear gradebook
history should run Moodle's standard gradebook delete alongside.

## Backup and restore

Scorecard activities round-trip through Moodle's standard backup
wizard. Backup creates an `.mbz` archive containing the activity
structure (settings, items, bands) and — when "Include user data" is
on — the user submission data (attempts and responses). Restore
reconstructs the activity in a destination course with the same
structure and any included user data.

### What's always backed up

- Activity settings (scale, anchor labels, allow retakes, show
  result, gradebook integration, completion settings, fallback
  message).
- All items, including soft-deleted ones. The `deleted=1` flag
  round-trips so historical attempt detail (Reports) can still
  resolve the original prompt text post-restore.
- All result bands, including soft-deleted ones. Same reason — band
  labels and messages on historical attempts must remain resolvable.

### What's included only with user data

- All scorecard attempts (one row per learner per attempt).
- All scorecard responses (one row per item per attempt).

### Snapshot fidelity

Each attempt row stores the matched band's label, message, and
message format **as captured at submit time**. These snapshot fields
preserve verbatim through backup and restore — historical attempts
always show the band as the learner saw it at submission, regardless
of any band edits applied afterwards. The plugin does not recompute
band labels or messages from current band state at restore.

### Restore behavior

When you restore a scorecard activity to a different course, or to
the same course as a duplicate, Moodle assigns new internal IDs to
the restored items, bands, and attempts. The plugin remaps
cross-references automatically — restored attempts reference the
restored bands; restored responses reference the restored items.
The remapping is invisible to operators: the wizard shows a normal
restore flow, and the activity behaves identically in the
destination course.

Restoring with "Include user data" off produces an activity with
the full authoring structure (items, bands, settings) but no
attempts or responses — useful when duplicating a scorecard
template to a new course without bringing learner submission
history along.

## Templates

JSON template export and import lets operators distribute a
scorecard's authoring structure across courses and instances.
Shipped at v0.7.0.

### What templates capture (and what they don't)

A template is a JSON file capturing a scorecard's **authoring
structure**:

- The scorecard's settings (name, intro, scale bounds, anchors,
  result-display flags, fallback message, gradebook + completion
  options, etc.)
- The non-deleted items (prompt, anchors, visibility flag,
  sortorder)
- The non-deleted bands (range, label, message, sortorder)

Templates **do not** carry user data — no attempts, no responses,
no gradebook entries. User data lives in backup/restore (see
**Backup and restore** above), which is the right path for
duplicating a scorecard with its submission history.

Templates also exclude soft-deleted items and bands; they
represent the operator's *current* intended authoring structure,
not the historical state. (Backup/restore preserves soft-deleted
rows so historical attempts can resolve their original prompt
and label text; templates have no historical attempts to resolve
and so do not need the soft-deleted rows.)

### Export workflow

From the manage page of any scorecard with content (items or
bands present), click **Export template** above the Items / Bands
/ Reports tab tree. The browser downloads a JSON file named
`<scorecard-name-slugified>-template.json` (the slug is produced
via `clean_filename(format_string($scorecard->name))`; an empty
slug falls back to `scorecard-template.json`).

The downloaded JSON is operator-readable: pretty-printed with
unescaped slashes and unicode, suitable for inspection or
hand-editing. Distribute via email, file share, version control,
or whatever channel makes sense for your workflow.

Export is gated on `mod/scorecard:manage` (the same capability
that gates items + bands authoring on the manage page).

### Import workflow

Import populates an **empty** scorecard with the template's
items + bands + settings. The flow:

1. In the destination course, use Moodle's standard "Add an
   activity or resource" picker to create a new Scorecard. Save
   with default settings; the scorecard exists but has no items
   or bands yet.
2. The new scorecard's manage page surfaces an **Import template**
   button above the tab tree (the empty-state affordance; visible
   only when items AND bands are both empty).
3. Click Import template → upload the JSON file → submit.
4. On success, you land on the populated manage page with a Moodle
   notification: "Template imported. N items and M bands added."

If the JSON validates with **errors** (missing fields, wrong
types, wrong schema_version, cross-plugin name, scale invalid,
displaystyle non-radio, format constants invalid, band range
invalid), the form re-renders with a red error block listing the
specific paths that failed. Fix the source JSON and re-upload.

If the JSON validates with **warnings** (plugin version mismatch
between source and destination plugin, unknown fields that will
be ignored on import), a yellow warnings block surfaces alongside
a "Yes, import anyway" button. Click to acknowledge and proceed;
the operator does not need to re-upload after seeing warnings.

Import is gated on `mod/scorecard:manage` at the module context —
operator already used `:addinstance` to create the empty scorecard
via the standard activity flow; populating it is "manage this
scorecard" semantically.

The import affordance is **suppressed** on populated scorecards
(any items or bands present); the export affordance shows in its
place. Direct-URL access to the import endpoint for a populated
scorecard redirects to manage.php with an info notification.

Overwrite and append import modes are deferred to v0.8+ if
operator demand surfaces; v0.7.0 is create-new-only.

### Filename convention

Exported templates download as `<scorecard-name-slugified>-template.json`
(slug via `clean_filename(format_string($scorecard->name))`).
Examples:

- A scorecard named "Career Fit Score" → `career_fit_score-template.json`.
- A scorecard with HTML or punctuation in its name → cleaned to
  filesystem-safe characters per Moodle's `clean_filename` helper.
- An edge case where the cleaned slug is empty → falls back to
  `scorecard-template.json` (no leading hyphen).

### URL pattern for direct navigation

Operators bookmarking the import flow or linking to it from LMS
Light docs can use the URL pattern:

```
/mod/scorecard/template_import.php?cmid=<empty-scorecard-cmid>
```

Direct-URL access requires the same capability gate as the
manage.php affordance (`mod/scorecard:manage` at module context).
Direct access to the URL for a populated scorecard redirects to
manage.php with an info notification — the URL is not a back-door
around the empty-state gate.

### plugin.version provenance

Each exported template carries a `plugin` object with a `version`
field reading the plugin's release string at export time. This is
**informational provenance only** — the `schema_version` field
("1.0" at v0.7.0) is the format-stability contract.

Templates exported from older plugin versions stamp the older
release string (e.g., `v0.6.0`); on import into a newer version,
the validator surfaces a non-blocking warning. Operator
acknowledges and proceeds via the confirmation form. The actual
items + bands data is unaffected by the version mismatch — the
warning is about producer/consumer asymmetry, not about correctness.

If you maintain a library of templates across plugin versions,
treat `plugin.version` as a useful audit signal but not a hard
compatibility gate. The schema_version field is what binds the
data shape.

## Running tests

PHPUnit init (one-time per Moodle instance):

```
ddev exec php /var/www/html/moodle/public/admin/tool/phpunit/cli/init.php
```

Run the full mod_scorecard suite via explicit file list:

```
ddev exec bash -c 'cd /var/www/html/moodle && vendor/bin/phpunit \
    public/mod/scorecard/tests/access_test.php \
    public/mod/scorecard/tests/db_install_test.php \
    public/mod/scorecard/tests/export_test.php \
    public/mod/scorecard/tests/grade_test.php \
    public/mod/scorecard/tests/learner_render_test.php \
    public/mod/scorecard/tests/lib_test.php \
    public/mod/scorecard/tests/lifecycle_test.php \
    public/mod/scorecard/tests/locallib_band_coverage_test.php \
    public/mod/scorecard/tests/locallib_band_test.php \
    public/mod/scorecard/tests/locallib_test.php \
    public/mod/scorecard/tests/report_test.php \
    public/mod/scorecard/tests/result_render_test.php \
    public/mod/scorecard/tests/scoring_test.php \
    public/mod/scorecard/tests/submission_test.php \
    public/mod/scorecard/tests/template_export_test.php \
    public/mod/scorecard/tests/template_import_test.php \
    public/mod/scorecard/tests/template_import_ui_test.php \
    public/mod/scorecard/tests/template_validate_test.php \
    public/mod/scorecard/tests/privacy/provider_test.php \
    public/mod/scorecard/tests/backup/backup_test.php \
    public/mod/scorecard/tests/backup/restore_test.php'
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
§10 for per-tenant brand color overrides. The actual properties and
Boost-compatible defaults are deferred to **v1.x** — they were
originally scoped with Phase 5a but pulled out to keep that phase
focused on gradebook + completion. Tenant themes that want
mod_scorecard brand alignment before v1.x can override scorecard's
existing class selectors directly via theme SCSS as a stop-gap.

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
  specification (v0.4.2, unchanged through v0.6.0; sha256-verified
  against the canonical raw URL on commit). The 0.4 → 0.4.1 → 0.4.2
  sub-decimal bumps reflect §9.1 capability matrix corrections (Phase 1
  hotfix) and the §9.2 grade-method clarification (Phase 5a.0). Phase
  5b shipped without a SPEC bump — §9.4 (Backup and Restore) and §9.5
  (Privacy API) directives at v0.4.2 were sharp enough to drive
  implementation directly.
- **Release notes:** [`CHANGES.md`](CHANGES.md).

## License

GPL-3.0-or-later. See [`LICENSE`](LICENSE).
