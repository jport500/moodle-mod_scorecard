# mod_scorecard release notes

## v0.7.0 — Phase 6 (JSON templates) (2026-04-28)

**MATURITY_ALPHA. JSON template export and import are now usable
end-to-end; per-tenant theming hooks (deferred to v1.x) and
alternative grade methods (deferred to v1.1+) remain.** This release
ships the template export pipeline (download a scorecard's authoring
structure as JSON), the validation + instantiation helpers, and the
operator-facing import UI surfaced from the empty-state of a freshly-
created scorecard's manage page. Templates capture authoring
structure only (items + bands + activity settings) — user data
(attempts and responses) lives in backup/restore per SPEC §9.4.

### Shipped

**Template export pipeline.** Operator clicks "Export template" on
the manage page of a scorecard with content → browser receives
`<scorecard-name-slugified>-template.json` containing the envelope
canonized in SPEC §9.6: `schema_version` ("1.0"), `plugin` (name +
version producer fingerprint), `exported_at` (ISO 8601 UTC), the
scorecard settings object, items array, and bands array. Soft-deleted
rows excluded — templates represent the operator's current intended
authoring structure, not historical state.

**Template validation helper.** Pure-function helper consumes a
parsed JSON template and returns structured `['errors' => [...],
'warnings' => [...]]`. Errors block import (missing fields, wrong
types, wrong schema_version, cross-plugin name, scale invalid,
displaystyle non-radio, format constants invalid, band range
invalid). Warnings inform but don't block (plugin version mismatch,
unknown fields ignored on import). Strict on schema_version "1.0"
at v0.7.0; permissive on unknown fields for forward-compat with
future schema versions.

**Template instantiation helpers (two parallel paths).**
`scorecard_template_import(array $template, int $courseid, int
$sectionnum)` is the create-new path — scaffolds a fresh scorecard
activity via Moodle's `add_moduleinfo` and populates items + bands.
Useful for programmatic create-from-template workflows.
`scorecard_template_populate(array $template, int $scorecardid)` is
the populate-existing path — assumes the scorecard already exists
(via standard "Add an activity") and inserts items + bands into the
existing row. Both helpers preserve sortorder gaps from the source
template (sortorder is opaque-positional; round-trip identity
preservation).

**Operator-facing import UI (populate-existing path).** Operator
creates an empty scorecard via standard "Add an activity" → lands
on manage.php → sees "Import template" affordance above the tab
tree (visible only when scorecard has zero items AND zero bands;
suppressed otherwise) → uploads JSON file → validation feedback
(structured errors block; warnings prompt confirmation) → success
redirects to manage.php with a Moodle notification. The
populate-existing model matches operator workflow ("I just made an
empty scorecard, let me populate it") rather than create-from-
template-via-course-nav (the architectural reversal at sub-step
6.5 → 6.5b).

**Warnings confirmation flow.** When validation surfaces non-
blocking warnings, the operator sees the warnings list + a separate
"Yes, import anyway" form alongside the warnings block. Hidden
fields preserve the original JSON (base64-encoded) and cmid across
the round-trip so the operator does not have to re-upload after
seeing warnings; sesskey CSRF discipline applied to the
confirmation surface.

**Capability reuse for import.** `mod/scorecard:manage` at module
context gates the import endpoint — operator already used
`:addinstance` to create the empty scorecard via standard workflow;
populating it is "manage this scorecard" semantically. No new
capability introduced.

**SPEC §9.6 directive added at sub-step 6.0** canonizing the format
and import semantics. SPEC v0.4.2 → v0.5.0 sub-decimal bump.
`§14` v1.1 roadmap row "Template import/export" removed (feature
shipped at this release).

### Operator action

**Standard upgrade path.** Run `php admin/cli/upgrade.php
--non-interactive` (or trigger the admin UI upgrade prompt). Phase 6
ships no schema changes — templates are pure code additions
(helpers + endpoints + UI + lang strings). The upgrade is stamp-
only, advancing the version stamp from `2026042704` to `2026042705`.

**Lang cache purge required after upgrade.** New `template:*` lang
strings won't render correctly until caches are purged on a running
site. Run `php admin/cli/purge_caches.php` after the upgrade
completes (or visit Site administration > Development > Purge all
caches). Skipping the purge causes import-flow operator-facing copy
to render as literal `[[<key>]]` text until the cache TTL expires.

**Export workflow.** From any populated scorecard's manage page,
click "Export template" above the tab tree. Browser downloads
`<scorecard-name-slugified>-template.json`. Distribute via email,
file share, version control, or the LMS Light community channel
of choice. Templates are operator-readable JSON; safe to inspect
or hand-edit (validation catches malformations on import).

**Import workflow.** In the destination course, use standard "Add
an activity" → choose Scorecard → save with default settings (or
fill in settings; the imported template's settings will overwrite
nothing because items + bands are empty at this point). Land on
the new empty scorecard's manage page. Click "Import template"
above the tab tree. Upload the JSON file. Submit. On success,
redirect to the populated manage page with a notification
("Template imported. N items and M bands added.").

**plugin.version provenance.** Templates carry a producer fingerprint
(`plugin.version`) reading the release string from `version.php` at
export time. Templates exported between sub-steps 6.1 and 6.6 stamp
`v0.6.0` (the release at the time of export); templates exported
from v0.7.0+ stamp the current release. The `schema_version` field
("1.0") is the format-stability contract; `plugin.version` is
informational provenance only. Cross-version mismatches surface as
warnings (not errors) on import.

**No completion or gradebook surprises on upgrade.** Phase 6 adds
no schema changes; existing scorecards' configuration and stored
data are preserved exactly as they were at v0.6.0.

### Quality gates

- `phpcs --standard=moodle` clean plugin-wide.
- **201 PHPUnit tests / 908 assertions** across the plugin suite
  (up from 168/728 at v0.6.0 — Phase 6 added +33 tests / +180
  assertions covering template export envelope shape with native-
  type round-trip, validation envelope structure + per-field rules
  + permissive-on-unknown warnings, instantiation via add_moduleinfo
  + sortorder gap preservation + transactional rollback,
  populate-existing flow + empty-state precondition + round-trip
  via empty-create-then-populate).
- Empirical-bootstrap-state-verification at every Phase 6 sub-step
  gate exercised production code paths against real dev-DB data:
  Shape 1 (PHPUnit integration tests against real DB), Shape 2
  (browser walkthrough — load-bearing at 6.1 export gate and
  6.5b populate-existing UI gate), Shape 3 (full-pipeline CLI smoke
  against scorecard id=2 with real Notion-pasted HTML preserved
  byte-identically through export → JSON → validate → populate).
- Architectural reversal at sub-step 6.5 → 6.5b documented as
  in-gate course-correction. Original 6.5 implementation (course-
  nav entry creating new scorecards) reversed to manage.php empty-
  state populate-existing model after walkthrough surfaced
  operator-workflow mismatch with the kickoff disposition. Reversal
  was forward-only (no commit history rewriting); 6.5 was never
  committed.

### Spec status

`docs/SPEC.md` is at v0.5 (sha256
`db3c8e5c31cea2662b5a8e413debc5d9ea395cb9683891c92fa6cc282feb5cdd`).
The 0.4.2 → 0.5.0 sub-decimal bump in sub-step 6.0 added §9.6
(Templates — JSON export and import) to canonize the format and
import semantics introduced at this phase: scope (authoring
structure only); import target (create-new only at v0.7.0); JSON
envelope versioning via top-level `schema_version`; producer
fingerprint via nested `plugin` object; ISO 8601 `exported_at`;
soft-delete exclusion (distinct from §9.4 backup/restore semantics).
The §14 v1.1 roadmap row "Template import/export" was removed at
this bump (feature shipped). SPEC sha pinned through the remaining
Phase 6 sub-steps.

**MATURITY_ALPHA preserved — earned-by-production-usage criterion
stands.** Phase 5b retrospective named MATURITY_BETA as deferred to
production-usage signal; v0.7.0 ships more features but doesn't
deliver that signal. Operators evaluating mod_scorecard for
adventurous early adoption can expect ALPHA behavior at v0.7.0;
BETA bump anticipated at a future deliberate release decision.

### Followups carried forward

Phase 6 prerequisites are now closed (export pipeline + validation
+ instantiation + operator-facing populate-existing UI shipped).
The remaining v0.6.0 followups still apply:

- **Soft-delete restore** — operator path to reverse soft-deletion
  on items and bands. Currently soft-delete is one-way; operators
  needing to restore a soft-deleted item must duplicate via DB.
- **Out-of-theoretical-range bands** — SPEC §4.3 quirk handling for
  band ranges that extend beyond the activity's possible score
  envelope. Bands save successfully but never match; warning
  presentation is a v1.x consideration.
- **Doc cleanup** — general documentation review across SPEC, README,
  and inline docblocks. Defensive cleanup; not behavior-changing.

New v0.7.0 followups (deferred per SPEC §9.6 or surfaced at Phase 6
sub-step dispositions):

- **Database-backed template library** — operators browse and select
  templates from an in-platform library rather than swapping JSON
  files through email. JSON files in operator inboxes may cover 80%
  of the use case at v0.7.0; revisit at v0.8+ if community demand
  surfaces.
- **Overwrite and append import modes** — beyond the create-new-only
  semantics canonized at SPEC §9.6 v0.7.0. Overwrite (replace items +
  bands of an existing populated scorecard) and append (add items +
  bands alongside existing) deferred to v0.8+ if operator demand.
- **Cross-version schema compatibility** — when `schema_version`
  beyond "1.0" ships, the validator extends to accept the supported
  version range. v0.7.0 is single-version-only by design.
- **Section selector in import UI** — operator chooses destination
  course section at import time. Deferred at sub-step 6.5b
  Q-reversal-3 (the scorecard already exists in its section per
  the standard add-activity flow before import; operator moves
  post-import via Moodle course editor).
- **"Add an activity" chooser integration** — alternative
  discoverability surface alongside the manage.php empty-state
  affordance. Course-correction-as-scope at sub-step 6.5b deferred
  this to v0.8+ if operator demand surfaces.

Deferred to v1.x explicitly:

- **Per-tenant theming hooks** — CSS custom properties for
  per-tenant brand color overrides. Originally scoped with Phase 5a;
  pulled to v1.x to keep that phase focused; remains deferred.
- **Highest, first, and average grade methods** — alternatives to
  latest-attempt overwrites (per SPEC §9.2 Decision v0.4.2).
- **Cron-deferred bulk grade backfill** — adhoc task for deployments
  with substantial pre-v0.5.0 attempt history. Not needed at LMS
  Light's current pre-launch deployment scale; revisit if scaling
  demands automated propagation rather than per-activity operator
  remediation.

## v0.6.0 — Phase 5b (Privacy and backup/restore) (2026-04-28)

**MATURITY_ALPHA. Privacy provider, backup, and restore are now
usable end-to-end; per-tenant theming hooks (deferred to v1.x) and
alternative grade methods (deferred to v1.1+) remain.** This release
ships the Moodle Privacy API provider with full export and delete
support, the backup-side serialization for items, bands, attempts,
and responses (with userdata gating), and the restore-side processors
with full ID remapping for in-plugin cross-references.

### Shipped

**Privacy provider — export.** When an operator runs Moodle's
standard data export for a user, the scorecard plugin contributes one
record per attempt across every scorecard activity the user has
submitted. Each record includes the score trio (`totalscore`,
`maxscore`, `percentage`), the matched band's label and message at
submit time (snapshotted), and the per-item response values paired
with the item prompts. Soft-deleted items render with a
"(deleted item)" marker so the export remains complete even when
items have been removed since the user submitted.

**Privacy provider — deletion.** Three scopes supported per Moodle
convention: delete all user data for an activity context (operator
wipes a course's submission history while keeping the activity
structure), delete a list of users' data for an activity (named users
removed; others preserved), delete a single user's data across all
contexts (right-to-be-forgotten compliance). Items, bands, and the
scorecard activity itself are never removed by these flows — only
user-submitted data.

**Privacy metadata completeness fix.** `scorecard_responses` metadata
declaration extended with `itemid` (was missing in v0.5.0 — the
graph-traversal link from response to item is required for export to
resolve which prompt a response value answered). Cross-table
soft-delete handling: responses to soft-deleted items round-trip
through export with the prompt text still resolvable.

**Backup steps for items + bands.** Full nested backup elements for
`scorecard_items` and `scorecard_bands` as part of the always-backed-
up authoring structure. Soft-deleted items + bands round-trip with
their `deleted=1` flag preserved (per SPEC §9.4 — historical
reporting requires the original prompt and label text to remain
resolvable post-restore).

**Backup root-element completionsubmit fix.** The `completionsubmit`
column added to `mdl_scorecard` at savepoint `2026042701` was missing
from the backup root-element field list in v0.5.0 — scorecards
backed up with `completionsubmit=1` reverted to the schema floor (0)
on restore. v0.6.0 restores the field; existing scorecards retain
their setting through subsequent backup/restore cycles.

**Backup steps for attempts + responses (userdata-gated).** When
"Include user data" is on in the backup wizard, `scorecard_attempts`
and `scorecard_responses` serialize with all snapshot fields
preserved verbatim per SPEC §11.2 — `bandlabelsnapshot`,
`bandmessagesnapshot`, `bandmessageformatsnapshot`, `totalscore`,
`maxscore`, `percentage`. Restore re-creates these rows without
recomputing from current band state, so historical attempts always
reflect what the learner saw at submit time.

**Restore steps for full nested structure.** Restore-side processors
for items, bands, attempts, and responses, with full ID remapping for
in-plugin cross-references. Restored attempts reference the restored
bands (not the source backup's band IDs); restored responses
reference the restored items. The remapping is invisible to
operators — the wizard shows a normal restore flow; the activity
behaves identically in the destination course.

### Operator action

**Standard upgrade path.** Run `php admin/cli/upgrade.php
--non-interactive` (or trigger the admin UI upgrade prompt). Phase 5b
ships no schema changes — privacy + backup + restore are pure code
additions. The upgrade is stamp-only, advancing the version stamp
from `2026042703` to `2026042704`.

**Privacy: data subject requests now honor scorecard data.** Existing
GDPR / right-to-be-forgotten / data-export workflows continue
unchanged — operators run them through Moodle's standard Privacy
tooling at **Site administration > Users > Privacy and policies**.
Prior to v0.6.0, scorecard data was not included in these flows (the
provider scaffold from Phase 1 declared metadata only); v0.6.0 ships
the full provider implementation.

**Backup/restore: standard Moodle backup wizard fully supports
scorecard activities.** Course backup, activity backup, and restore
into new or existing course all round-trip scorecard data correctly.
No operator-specific configuration required — the wizard's "Include
user data" checkbox controls whether attempts and responses come
along; structure-only restore (items + bands without attempts) works
as a duplication path for scorecard templates.

**No completion or gradebook surprises on upgrade.** Phase 5b adds
no schema changes; existing scorecards' configuration and stored
data are preserved exactly as they were at v0.5.0.

### Quality gates

- `phpcs --standard=moodle` clean plugin-wide (0 errors / 0 warnings).
- **168 PHPUnit tests / 728 assertions** across the plugin suite (up
  from 143/612 at v0.5.0 — Phase 5b added +25 tests / +116
  assertions for privacy provider coverage, backup XML structure +
  userdata gating, and full backup→restore round-trip with snapshot
  fidelity and ID remapping verification).
- Empirical CLI verification at every Phase 5b sub-step gate
  exercised production code paths against real dev-DB data: privacy
  export contract, privacy delete contract with transactional
  rollback, backup XML inspection of items + bands, two-invocation
  backup comparison for userdata gating, and full backup→restore
  round-trip with state-comparison assertions across the dev-DB
  scorecard's 33 attempts and 164 responses.

### Spec status

`docs/SPEC.md` is at v0.4.2 (sha256
`c1ac688608724bf585299e9e2a556947b7608f1ba52a790a19ca2eb6ba903010`),
unchanged through v0.6.0. Phase 5b shipped without a SPEC bump
because §9.4 (Backup and Restore) and §9.5 (Privacy API) directives
at v0.4.2 were sharp enough to drive implementation directly.

**MATURITY_ALPHA preserved — technical prerequisites met but BETA
deferred to earned-by-production-usage.** Phase 5b's full delivery
(privacy + backup + restore) was the gating *technical* prerequisite
for MATURITY_BETA consideration. The technical case is met; the
operational case requires production usage. BETA is therefore
deferred to an earned-by-production-usage trigger rather than a
phase-completion trigger — the Phase 5b retrospective will name
explicit BETA criteria (production deployment count, operator-
reported issue resolution, stable-operation duration). Operators
evaluating mod_scorecard for adventurous early adoption can expect
ALPHA behavior at v0.6.0; BETA bump anticipated at a future
deliberate release decision.

### Followups carried forward

Phase 5b prerequisites from the v0.5.0 followup list are now closed
(privacy + backup + restore shipped). The remaining v0.5.0 followups
still apply:

- **Soft-delete restore** — operator path to reverse soft-deletion on
  items and bands. Currently soft-delete is one-way; operators
  needing to restore a soft-deleted item must duplicate via DB.
- **Out-of-theoretical-range bands** — SPEC §4.3 quirk handling for
  band ranges that extend beyond the activity's possible score
  envelope. Bands save successfully but never match; warning
  presentation is a v1.x consideration.
- **Doc cleanup** — general documentation review across SPEC, README,
  and inline docblocks. Defensive cleanup; not behavior-changing.

Deferred to v1.x explicitly:

- **Per-tenant theming hooks** — CSS custom properties for per-tenant
  brand color overrides. Originally scoped with Phase 5a; pulled to
  v1.x to keep that phase focused on gradebook + completion;
  Phase 5b's scope was too narrow to bring it back in.
- **Highest, first, and average grade methods** — alternatives to
  latest-attempt overwrites (per SPEC §9.2 Decision v0.4.2).
- **Cron-deferred bulk grade backfill** — adhoc task for deployments
  with substantial pre-v0.5.0 attempt history. Not needed at LMS
  Light's current pre-launch deployment scale; v0.7+ revisit if
  scaling demands automated propagation rather than per-activity
  operator remediation.

## v0.5.0 — Phase 5a (Gradebook and completion) (2026-04-27)

**MATURITY_ALPHA. Gradebook integration and activity completion are
now usable end-to-end; full backup/privacy provider (Phase 5b) and
per-tenant theming hooks (deferred to v1.x) remain planned.** This
release ships gradebook integration with latest-attempt-overwrites
semantics, auto-grademax computation from visible items, completion
via the new "Submit a scorecard attempt" rule, and an upgrade path
that preserves prior behavior for existing deployments.

### Shipped

**Gradebook integration.** Scorecard submissions propagate to the
Moodle gradebook for activities with `gradeenabled` set. The grade
method per SPEC §9.2 (Decision v0.4.2) is latest-attempt overwrites:
each submission writes the attempt's `totalscore` as the gradebook
value for that user, replacing any prior entry. Highest, first, and
average grade methods remain v1.1+ scope.

**Auto-grademax computation.** When a scorecard's `grade` setting is
0 (auto mode, the default), grademax derives from visible-item
count × `scalemax`. Recomputes automatically on item add, remove, or
visibility-toggle while no attempts exist (SPEC §9.2 lifecycle
gate); freezes after the first submission per SPEC §11.2's
snapshot-stability rule applied to grade items. When `grade` is
explicit (greater than zero), that value becomes grademax directly
and does not auto-recompute.

**Items-CRUD lifecycle integration.** Item add, update (visibility
toggle), and hard-delete operations recompute grademax via a gated
helper. The gate uses a direct DB count rather than the cached
attempt counter so the recompute callsites do not prime the cache
and surprise subsequent callers in the same request. The soft-delete
branch (when attempts exist) preserves the frozen grademax.

**Completion via "Submit a scorecard attempt" rule.** New custom
completion rule (`completionsubmit`) marks the activity complete for
a user when they have at least one submitted attempt. One-way latch:
any submission, ever, satisfies the rule. Soft-deleted items, band
edits, and similar mutations do not un-complete a prior submission;
retakes do not change the completion state.

**Submit-time hooks.** The submission handler propagates both grade
and completion state immediately on attempt persistence, after the
event triggers. Idempotent on retake — each submission overwrites
the gradebook entry; completion stays "complete" once set.

**Schema migration.** New `completionsubmit` column added to
`mdl_scorecard` via savepoint at `2026042701`. New scorecards default
to 1 in the activity edit form (operator-friendly: the natural
completion criterion for a self-assessment is "they submitted").
Existing scorecards default to 0 (the schema floor); operators see
the new checkbox in the edit form and explicitly opt in. Asymmetric
defaults reflect asymmetric deployment-state assumptions.

**Custom completion class.**
`\mod_scorecard\completion\custom_completion` exposes the
completionsubmit rule via Moodle 5.x's `activity_custom_completion`
API for completion reports, course-level completion criteria, and
standard Moodle completion UI integration. `FEATURE_COMPLETION_TRACKS_VIEWS`
also enabled, so the activity supports the standard view-tracking
completion option in addition to the custom rule.

**Toggle resilience.** Setting `gradeenabled` from on to off updates
the grade item to `GRADE_TYPE_NONE` rather than deleting it, so
toggling back on preserves the gradebook history. Deleting the
scorecard activity removes the grade item entirely (no orphan
columns in the gradebook).

**SPEC clarification (v0.4.2).** SPEC §9.2 grade-method directive
made explicit (Decision v0.4.2): latest-attempt overwrites; other
methods deferred to v1.1+. SPEC sha bumped from 0.4.1 to 0.4.2 in
sub-step 5a.0.

### Operator action

**Standard upgrade path.** Run `php admin/cli/upgrade.php` (or
trigger the admin UI upgrade prompt). The upgrade applies the schema
change (new `completionsubmit` column on `mdl_scorecard`) at
savepoint `2026042701` and advances the version stamp through
`2026042703`.

**Lifecycle-hook fallback for v0.4.x deployments with `gradeenabled=1`
scorecards.** If your deployment has scorecards with `gradeenabled=1`
that already had attempts before this upgrade, edit and save those
activities once post-upgrade to populate gradebook entries from
existing attempts. New scorecards from v0.5.0 onward have grade
items created automatically on save. No action is needed for typical
v0.4.x deployments where `gradeenabled` was unused (the default).

**No completion-state surprises on upgrade.** Existing v0.4.x
scorecards have `completionsubmit=0` (the schema floor) — no
scorecard auto-completes until the operator explicitly enables the
rule via the activity edit form. Per-instance opt-in preserves prior
completion behavior on upgrade.

### Quality gates

- `phpcs --standard=moodle` clean plugin-wide (0 errors / 0 warnings).
- 143 PHPUnit tests / 612 assertions across the plugin suite.
- Manual UI walkthrough at every Phase 5a sub-step gate (5a.1
  through 5a.5 plus the 5a.5 fix-forward) covering paths PHPUnit
  cannot easily exercise: gradeenabled toggle UX, grade column
  appearance per per-instance gating, latest-attempt overwrite
  semantics on retake, completion checkmark appearance on submit,
  soft-delete branch preservation post-attempt, and empirical
  schema upgrade application in DDEV.

### Spec status

`docs/SPEC.md` is at v0.4.2 (sha256
`c1ac688608724bf585299e9e2a556947b7608f1ba52a790a19ca2eb6ba903010`).
The 0.4.1 → 0.4.2 sub-decimal bump in sub-step 5a.0 made the §9.2
grade-method directive explicit (Decision v0.4.2: latest-attempt
overwrites; highest, first, and average deferred to v1.1+). SPEC
sha pinned through the remaining Phase 5a sub-steps.

### Followups carried forward

All v0.4.0 followups still apply (Phase 5b prerequisites,
soft-delete restore, out-of-theoretical-range bands, doc cleanup).
**18 active items** going into Phase 5b.

Deferred to v1.x explicitly (per Phase 5a kickoff scope decisions
and this release's outcome):

- **Per-tenant theming hooks** — CSS custom properties for
  per-tenant brand color overrides. Originally scoped with Phase
  5a; pulled to v1.x to keep this phase focused on gradebook and
  completion.
- **Highest, first, and average grade methods** — alternatives to
  latest-attempt overwrites (per SPEC §9.2 Decision v0.4.2).
- **Cron-deferred bulk grade backfill** — adhoc task
  (`\mod_scorecard\task\backfill_grades`) for deployments with
  substantial pre-v0.5.0 attempt history. Not needed at LMS Light's
  current pre-launch deployment scale; v0.6+ revisit if scaling
  demands automated propagation rather than per-activity operator
  remediation.

## v0.4.0 — Phase 4 (Reporting) (2026-04-27)

**MATURITY_ALPHA. Manager-facing reports surface is now usable
end-to-end; gradebook integration (Phase 5a) and full backup/privacy
provider (Phase 5b) remain planned.** This release ships the Reports
tab and CSV export for managers reviewing submitted scorecard
attempts. Operators on any v0.3.x baseline upgrade automatically via
`php admin/cli/upgrade.php`.

### Shipped

**Reports tab.** A capability-gated report view at
`/mod/scorecard/report.php?id=<cmid>` for users with
`mod/scorecard:viewreports`. Open from the Reports tab on manage.php
(which redirects to the report page) or directly via URL. Non-managers
landing on the URL are redirected to the activity view with a clear
error notice.

**Attempts table.** Columns: name, identity fields (per site config),
attempt number, submitted timestamp, total score, max score,
percentage, band label. Each row carries an expandable `<details>`
summary block surfacing per-item response detail. Soft-deleted items
render with a `[deleted]` prefix; out-of-range responses (only
possible via direct DB tampering or backup/restore mismatch since
SPEC §4.5 blocks scale changes after attempts exist) flag with a red
audit suffix.

**Group filter integration.** Standard Moodle group selector above
the table. `moodle/site:accessallgroups` honored — users without it
see only their own groups. Filter persists across pagination;
selector change resets to page 1. Empty-state copy adapts to the
filter ("No attempts in the selected group." vs the generic "No
attempts have been submitted yet.").

**CSV export.** Capability-gated to `mod/scorecard:export`, separate
from `:viewreports` per SPEC §9.1 to support audit roles that need
on-screen viewing without download. Filter-aware (group selection
narrows the export). Filename format
`scorecard-{shortname}-attempts-{YYYYMMDD-HHMMSS}.csv`. Button hidden
when no attempts match the active filter; direct URL access to
`export.php` with no attempts redirects to the report page with a
notification.

**Pagination.** Default page size 25 attempts via flexible_table
subclass. Pagination chrome (page links, "Previous"/"Next") above
and below the table when total attempts exceed page size.
Per-pagination-page response fetch — bandwidth scales with page
size, not total attempts. Initials filter (A-Z) intentionally
disabled.

**Layout fix.** Scorecard top-level pages now use the standard
Moodle centered-column layout, matching mod_quiz and other core
activities. Affects view, report, manage, and submit pages. The
omission entered at Phase 1 (view.php) and propagated through
subsequent phases; surfaced via side-by-side viewport comparison
with mod_quiz at Phase 4 close.

### Pre-Phase-4 fix-forwards (now formally tagged)

Three fix-forwards landed after v0.3.0 was tagged but before Phase 4
began. They ship as part of v0.4.0:

- **SPEC §9.1 capability matrix correction.** The `editingteacher`
  archetype was missing from `:view`; default Moodle "Teacher" role
  could not see scorecard activity cards in their courses.
  `coursecreator` was dropped from `:addinstance` (was dead-code; the
  `clonepermissionsfrom => moodle/course:manageactivities` directive
  dominated propagation). `db/upgrade.php` savepoint at `2026042602`
  propagates the missing `:view` cap to existing
  editingteacher-archetype roles at the system context. Three
  behavior-level regression tests pin the SPEC §9.1 matrix going
  forward.
- **Empty-state shows "Add items" link for managers** when no items
  are configured. Previously only the learner-facing copy rendered,
  leaving managers with no on-screen path to authoring from view.php.
- **Persistent "Manage scorecard" affordance on learner-facing view**
  for users with `mod/scorecard:manage`. Covers both the
  `:submit`-and-`:manage` and `:manage`-only branches so authors
  always have a path to manage.php from view.php.

### Operator action

**Cap-restoration upgrade.** Existing deployments at version stamp
`2026042601` (the v0.3.0 release tag) need to run
`php admin/cli/upgrade.php` (or trigger the admin UI upgrade prompt)
to apply the savepoint at `2026042602` that restores the `:view`
capability to every editingteacher-archetype role at the system
context. The upgrade step is idempotent on re-run and preserves
explicit admin overrides (deliberate `CAP_PREVENT` settings or
similar). After upgrade, the default "Teacher" role gains visibility
on existing scorecard activities without further configuration.

**Hotfix tag consolidation.** The v0.3.0 hotfix (numeric
`2026042602`, fix-forward commits `946d09b` / `f3e2928` / `3d6e5f9`)
never received its own release tag — the fix landed mid-Phase-4 with
the release string still set to `v0.3.0`. v0.4.0 formally ships that
hotfix bundled with the Phase 4 reporting surface.

**No manual configuration required.** After upgrade, the new Reports
tab appears on manage.php for users with `:viewreports`; the Export
CSV button appears on report.php for users with `:export`.

### Quality gates

- `phpcs --standard=moodle` clean plugin-wide (0 errors / 0 warnings).
- 127 PHPUnit tests / 559 assertions across the plugin suite.
- Manual UI walkthrough at every phase gate (4.1 through 4.6 plus the
  4.6.5 layout fix-forward) covering paths PHPUnit cannot easily
  exercise: capability branches, group filter UX, pagination chrome
  at scale (50-attempt fixture), CSV download integrity, layout
  consistency against mod_quiz reference.

### Spec status

`docs/SPEC.md` remains v0.4 (sha256-verified against canonical raw
URL at commit, unchanged through Phase 4 plus the 4.6.5 fix-forward).
Phase 4 surfaced no spec corrections; SPEC §10.4 (the report-page
section) was substantially complete and required no revisions during
build.

### Followups carried forward

All v0.3.0 followups still apply (Phase 5b prerequisites, soft-delete
restore, out-of-theoretical-range bands, doc cleanup). Closed during
Phase 4: followup #19 (empty-state copy normalization), closed as
intentional voice differentiation between learner and manager copy.
**18 active items** going into Phase 5.

## v0.3.0 hotfix (version stamp 2026042602, 2026-04-26)

**MATURITY_ALPHA. Capability fixes; no release tag bump.** Two SPEC §9.1
corrections of the same shape: natural-English assumptions versus
Moodle's actual role-capability mechanics. Surfaced during Phase 4.1
walkthrough when a user enrolled as the default "Teacher" role could
not see the activity card in their course. The `editingteacher`-on-`:view`
fix was the motivating bug; running the new regression test caught a
second mismatch on `:addinstance` as a side effect of being structurally
complete (coursecreator was listed but never actually got the cap due
to Moodle's `clonepermissionsfrom` propagation pattern). Both corrected
together; the new behavior-level regression test pins the full SPEC §9.1
capability matrix so further mismatches of this shape can't recur silently.

### Operator action required

Existing deployments running version stamp 2026042601 or earlier need
to run `php admin/cli/upgrade.php` (or trigger the admin UI upgrade
prompt). The plugin ships an explicit upgrade step that restores the
missing `:view` cap to every editingteacher-archetype role at the
system context — Moodle's `update_capabilities()` does NOT
re-propagate archetype rows to existing capabilities (it preserves
admin customizations on upgrade), so the upgrade step is what actually
performs the restoration. No manual role-cap editing is required. The
upgrade step is idempotent and skips any role with an existing explicit
override (preserving deliberate `CAP_PREVENT` settings or similar).
After upgrade, the default "Teacher" role gains visibility on existing
scorecard activities without further configuration.

The `:addinstance` correction is purely a code-cleanliness change — the
dead-code `coursecreator` archetype entry was never actually granting
the cap (`clonepermissionsfrom => moodle/course:manageactivities`
dominated), so its removal does not change any deployment's runtime
behavior and needs no upgrade step.

### Changed

- `docs/SPEC.md` §9.1: two table cells corrected. `:view` row gains
  `editingteacher`. `:addinstance` row drops `coursecreator`, leaving
  `manager, editingteacher` (matches the canonical core-plugin pattern
  in mod_quiz / mod_assign / mod_forum). Inline
  `**Decision (v0.4.1):**` callout below the table covers both
  corrections in a single rationale frame. SPEC patch version v0.4 →
  v0.4.1.
- `db/access.php`: `'editingteacher' => CAP_ALLOW` added to
  `mod/scorecard:view`'s archetypes; `'coursecreator' => CAP_ALLOW`
  removed from `mod/scorecard:addinstance`'s archetypes (the
  `clonepermissionsfrom => 'moodle/course:manageactivities'` directive
  remains and continues to drive actual propagation).
- `version.php`: 2026042601 → 2026042602. Triggers
  `xmldb_scorecard_upgrade()` to run the cap-restoration savepoint.
- `db/upgrade.php` (new): explicit upgrade step at savepoint 2026042602
  iterates every editingteacher-archetype role and idempotently assigns
  `mod/scorecard:view = CAP_ALLOW` at system context. Includes an
  architectural-knowledge docblock so future maintainers know why the
  step exists separately from access.php (see "Operator action required"
  above for the rationale).
- `tests/access_test.php` (new): three behavior-level regression tests.
  (a) `test_spec_section_9_1_role_capabilities_match` iterates every
  (capability, role) pair in SPEC §9.1 — covers fresh-install
  propagation. (b) `test_upgrade_step_restores_editingteacher_view_cap`
  simulates the broken pre-fix state and asserts the upgrade step
  restores the cap — covers the upgrade-from-broken-baseline path.
  (c) `test_upgrade_step_preserves_existing_cap_row` asserts the
  upgrade step is idempotent and does not clobber explicit admin
  overrides.

### Quality gates

- phpcs zero/zero on changed files.
- PHPUnit: full plugin suite green; access_test.php contributes 3 tests
  covering both the fresh-install and the upgrade-from-broken-baseline
  deployment paths.

## v0.3.0 — Phase 3 learner submission (2026-04-26)

**MATURITY_ALPHA. Learner-facing experience is now usable end-to-end;
gradebook integration and reports remain in Phases 5a and 4
respectively.** This release lets a learner complete a scorecard from
start to finish: the submission form renders all visible items with
anchor labels and required radio groups, server-side validation
collects per-item errors and form-level POST-injection guards, the
scoring engine totals responses and matches a result band (with
snapshot capture so the result stays stable as bands are later
edited), and the result page shows the snapshotted score, optional
percentage, the matched band heading and message, and an audit-honest
item summary. Retake-enabled scorecards show a "Previous attempt"
callout above the form on revisit.

### Shipped

**Submission form.** Visible at `/mod/scorecard/view.php?id=<cmid>`
for users with `mod/scorecard:submit`. One fieldset per visible
non-deleted item; the activity scale renders as a row of labelled
radio inputs with optional per-item anchor overrides flanking the
row. Form posts to `submit.php` with sesskey + cmid; the submit
endpoint owns the HTTP boundary (login + sesskey + capability) and
delegates to a `scorecard_handle_submission()` helper for everything
else. PHPUnit calls the helper directly with synthesized inputs so
handler logic is exercised without an HTTP simulation.

**Validation collects all errors on a single re-render.** The handler
runs four steps in order: itemid-subset guard against POST injection,
lifecycle gate via re-fetch of visible items, per-item missing +
out-of-range collection, and a duplicate-attempt short-circuit when
retakes is off. Per-item errors render inline above the offending
fieldset; form-level errors render in a notification at the top of
the page. Radio selections are preserved across re-render.

**Scoring engine.** Pure function (no DB), unit-tested across 12
cases including boundary inclusion, off-by-one robustness, and
`coding_exception` paths for empty items and out-of-range responses.
Sums responses for visible items, computes total + max + percentage
(rounded to two decimals for storage), iterates the bands array once
sorted by `minscore ASC, id ASC`, and returns the first matching
band's id, label, message, and format. On no match, the per-instance
fallback message and format snapshot onto the attempt instead.

**Audit-write semantics.** Response rows persist for every itemid
submitted on the form, including items soft-deleted between render
and submit. The engine sums only over visible items at submit time;
audit-only rows survive in `scorecard_responses` so Phase 4 reports
can render "this item was answered before being removed" rather than
silent disappearance. The engine's audit-only contract from the
scoring tests is now load-bearing in production.

**Single-transaction persist.** Attempt INSERT + N response INSERTs
run inside `$DB->start_delegated_transaction()`. The
`\mod_scorecard\event\attempt_submitted` event fires after
`allow_commit()`, so subscribers observe a fully-persisted attempt
with response rows and band snapshots in place.

**Result page.** Reads only from snapshotted columns on the attempt
row (`totalscore`, `maxscore`, `percentage`, `bandid`,
`bandlabelsnapshot`, `bandmessagesnapshot`,
`bandmessageformatsnapshot`); never JOINs to live bands, matching
SPEC §11.2's stability rule. Conditional rendering: percentage when
`showpercentage` is on (rounded to integer for display), band heading
when `bandlabelsnapshot` is non-empty, band message body when
`bandmessagesnapshot` is non-empty. The matched-band-with-empty-
message case renders the heading without a body — NOT a fallback
fallthrough — so empty-message bands behave as deliberately authored.

**Audit-honest item summary.** The collapsible per-item summary
(when `showitemsummary` is on) uses the union of itemids referenced
by the attempt's response rows, rendered with the existing
strikethrough + "(deleted)" badge for items soft-deleted since
submit. Learners revisiting a result see what they actually
answered, not what's currently configured.

**Retake handling.** When `allowretakes` is on and the user has a
prior attempt, the form renders directly with a compact "Previous
attempt" callout above showing the submission timestamp, score, and
band label (or "No band match" on the fallback path). The form
itself preselected radios stay blank — retakes start fresh. The
callout intentionally shows score and band even when `showresult` is
off: `showresult` gates the post-submit results page, not all
references to past performance, and operators wanting total
result-blackout should also disable `allowretakes`.

**Hidden-result branch.** When `showresult` is off and the user has
an attempt (retakes off), view.php short-circuits with a friendly
"result not shown" notice rather than rendering the result page.

### Quality gates

- `phpcs --standard=moodle` clean plugin-wide (0 errors / 0 warnings).
- 86 PHPUnit tests / 341 assertions across nine test files (Phase 2
  baseline 42 / 174 + 10 / 47 learner render + 12 / 44 scoring engine
  + 9 / 42 submission handler + 10 / 28 result render + 3 / 9 retake
  callout, less 2 placeholder assertions trimmed when the 3.4 result
  page replaced the 3.1 placeholder stub).
- Manual UI walkthrough at every phase gate covering paths the
  PHPUnit suite cannot easily exercise: form layout and accessibility
  flow, validation re-render preserving selections, post-submit
  redirect, retake callout placement and copy, and the combinations
  of `showresult` and `allowretakes` settings.

### Spec status

`docs/SPEC.md` remains v0.4 (sha256-verified against canonical raw
URL at commit). Phase 3 added no spec material.

### Followups carried forward

All v0.2.0 followups still apply (Phase 5b prerequisites, restore
action for soft-deleted items / bands, out-of-theoretical-range bands
silently clipped, upstream `CLAUDE.md` doc corrections). Phase 3
added two report-side followups for Phase 4:

- **Flag audit rows with out-of-range values.** When POST tampering
  writes an out-of-scale value on a soft-deleted audit-only itemid,
  the engine ignores it from totalscore but the audit row stores the
  cast value as submitted. Phase 4 reports can flag these rows as a
  tampering / soft-delete-race indicator; optional report-side
  enhancement, not Phase 3 scope.
- **`get_attempt_responses` helper shape.** Phase 3.4 builds itemids
  + responsemap inline in view.php's result branch (two queries:
  response rows then `get_records_list` on the itemid union). Phase
  4 reports may want a richer shape (joined item rows, multiple
  attempts at once); decide helper shape when Phase 4 has actual
  call sites rather than preempting with a speculative refactor.

## v0.2.0 — Phase 2 authoring (2026-04-26)

**MATURITY_ALPHA. Teacher-facing authoring is now usable end-to-end;
learner-facing behaviour still lands in Phase 3.** This release lets
a teacher build a complete scorecard: define scored items with
anchor labels and visibility, define result bands with score ranges
and interpretive messages, and have the system flag overlap and gap
problems before attempts start landing.

### Shipped

A three-tab Manage screen at `/mod/scorecard/manage.php?id=<cmid>`
under the `mod/scorecard:manage` capability. Tabs: **Items**,
**Bands**, **Reports** (Phase 4 placeholder). Non-managers landing
on the URL get redirected to the activity view with a clear error
notice.

**Items.** Add, edit, reorder (up / down arrows), and delete scored
prompts. Each item carries a rich-text prompt, optional low / high
anchor labels overriding the activity-level defaults, and a
visibility toggle for keeping items as drafts during build. Items
append at the end on add; reorder swaps adjacent non-deleted
positions and skips soft-deleted neighbours.

**Bands.** Add, edit, and delete result bands. Each band has an
inclusive score range, a label, and an optional rich-text message.
Bands display by minimum score ascending — natural numeric order.
The bands list surfaces coverage problems live:

- **Overlap blocks save.** Submitting a band that overlaps an
  existing sibling on any score in the range re-renders the form
  with an inline error naming the sibling and the overlap range.
  Editing a band excludes itself from the check, so unchanged
  re-saves never trip this.
- **Gap warns.** When the bands cover the score range incompletely,
  a yellow warning at the top of the list names the uncovered ranges
  sorted ascending. Gaps don't block save — uncovered scores fall
  through to the per-instance fallback message.
- **No items yet.** Before any visible items exist, gap detection is
  suppressed and the tab shows an info notification telling the
  teacher to add items first.

**Lifecycle gate.** Once any attempt is recorded for the scorecard
(starting in Phase 3), several edits become locked or warned to keep
historical attempt scoring stable:

- Hard-delete of items and bands becomes soft-delete (row retained
  with a "(deleted)" badge plus strikethrough on the prompt or label
  for Phase 4 report-detail resolution).
- The activity's `scalemin` / `scalemax` cannot be changed via the
  activity edit form; the form rejects with a clear error.
- Adding new items is still allowed, but the post-save notification
  upgrades to a warning tone, explaining that historical attempts
  will not include the new item.

The lifecycle gate is driven by a single `scorecard_count_attempts()`
helper backed by a `MODE_REQUEST` cache (`db/caches.php`) so multiple
gate checks within one request hit the database once.

### Quality gates

- `phpcs --standard=moodle` clean plugin-wide (0 errors / 0 warnings).
- 42 PHPUnit tests / 174 assertions across six test files
  (Phase 1 baseline 11/79 + items CRUD 12/34 + bands CRUD 5/20 +
  band coverage analysis 11/33 + lifecycle gate 3/8).
- Curl smoke covers list rendering, form GET, action routing, soft-
  vs-hard delete branching, gap warning display, deleted-marker
  rendering, and the renamed-lang-key regression for the shared
  deleted-marker.
- Manual UI walkthrough at every phase gate covering paths the curl
  smoke can't easily exercise (form-POST validation, scale-change-
  blocked-after-attempts, new-item warning notification).

### Spec status

`docs/SPEC.md` is at v0.4 (sha256-verified against canonical raw URL
at commit). The four Phase-1-surfaced corrections listed in the
v0.1.0 release notes are absorbed into v0.4.

### Followups carried forward

- Phase 5b prerequisites (privacy provider, nested backup, itemid
  metadata) tracked in v0.1.0 release notes still apply.
- **Restore action for soft-deleted items / bands** (deferred,
  possible v0.5 spec). Phase 2 surfaces deleted rows visibly with the
  "(deleted)" badge but provides no UI to restore them. If real
  teachers report needing recovery from accidental soft-delete
  before Phase 4 reports ship, this becomes a v0.5 feature.
- **Out-of-theoretical-range bands silently clipped** (possible v0.5
  spec). A band whose range falls entirely outside the theoretical
  score range (e.g., 80–100 on a scorecard whose max possible is 50)
  is clipped from gap analysis and never matches an attempt. The
  current MVP does not warn about this; possible separate warning
  or fold-into-gap-warning copy in v0.5.
- **Doc corrections to upstream `CLAUDE.md` / project docs**: the
  cache-purge example needs `moodle/` prefix; the `admin/cli/*` vs
  `admin/tool/<X>/cli/*` path split needs documentation. Tracked for
  a separate doc-cleanup PR.

## v0.1.0 — Phase 1 skeleton (2026-04-25)

**MATURITY_ALPHA. Not user-facing.** This release establishes the
plugin's foundational structure: installable schema, capabilities,
activity-creation flow, and integration scaffolds. Item authoring,
learner submission, scoring, reporting, gradebook integration, and
full backup/restore land in Phases 2 through 5b.

### Shipped

- **Install schema** (`db/install.xml`): five tables — `scorecard`,
  `scorecard_items`, `scorecard_bands`, `scorecard_attempts`,
  `scorecard_responses` — including snapshot columns
  (`bandlabelsnapshot`, `bandmessagesnapshot`,
  `bandmessageformatsnapshot`) and soft-delete flags on items + bands.
- **Six capabilities** (`db/access.php`): `mod/scorecard:addinstance`,
  `:view`, `:submit`, `:manage`, `:viewreports`, `:export`.
- **Activity contract** (`lib.php`): `scorecard_add_instance`,
  `scorecard_update_instance`, `scorecard_delete_instance` (with
  explicit dependent-row cascade), `scorecard_supports` with phase-
  honest feature flags. Phase 5a stubs for gradebook callbacks.
- **Settings form** (`mod_form.php`): full §4.1 settings table; the
  fallback message is pre-populated with the default lang string at
  activity creation only (not on every edit).
- **Basic view** (`view.php`): activity title + intro, with role-
  branched placeholder for managers ("Add items and result bands"
  link to the Phase 2 manage page) and learners ("not ready yet").
- **Activity icon** (`pix/icon.svg`): clipboard with three increasing-
  length score bars, single-color via `currentColor`, 64×64 viewBox.
- **Privacy provider scaffold** (`classes/privacy/provider.php`):
  metadata declared for `scorecard_attempts` and `scorecard_responses`.
  Data-subject methods ship as type-correct stubs; full implementation
  lands in Phase 5b.
- **Backup/restore scaffold** (`backup/moodle2/`): settings-only
  backup task and stepslib that round-trip the `{scorecard}` row;
  `apply_activity_instance` wires the restored instance into the
  target course module on restore.
- **PHPUnit skeleton tests** (`tests/`): 11 tests covering CRUD round-
  trip, dependent-row cascade on delete, `_supports` feature
  declarations, and schema integrity (table existence, column
  presence, key indexes).
- **Spec snapshot** (`docs/SPEC.md`): v0.3, the design document used
  for Phase 1 build.
- **English language strings** (`lang/en/scorecard.php`): 55 strings
  covering all settings, capabilities, validation errors, view
  placeholders, and privacy metadata descriptions.

### Quality gates

- `phpcs --standard=moodle` clean (0 errors / 0 warnings) plugin-wide.
- 11 PHPUnit tests passing, 79 assertions.
- Manual install + activity-creation smoke verified on Moodle 5.1.3.
- Backup → restore round-trip verified preserving all 18 backed-up
  fields byte-for-byte (including HTML entities in text columns and
  format constants in int columns).

### ⚠️ Known limitation: backup captures settings only

From this version forward, course backups will INCLUDE scorecard
activities — but the captured backup contains **settings only**.
Items, bands, attempts, and responses are **not yet** in the backup.
Restored scorecards in this state will appear with no items, no bands,
and no attempt history. Full backup support (items, bands, attempts,
responses with snapshot fidelity) lands in Phase 5b.

**Operators should not rely on course backup as a content-migration
tool until Phase 5b is released.**

### Phase 5b prerequisites carried forward

These items are encoded in `git log` (commit messages) and tracked for
the Phase 5b release:

- Replace stub bodies in `classes/privacy/provider.php`; verify no
  `Phase 5b: implement` comments remain in the file before tagging.
- Implement nested backup steps for items, bands, attempts, responses
  in `backup/moodle2/backup_scorecard_stepslib.php`; verify full
  round-trip with attempt data preserved including band snapshot
  fidelity (carry forward the byte-for-byte inspection pattern from
  Phase 1.4).
- Add `itemid` declaration to `scorecard_responses` field list in
  `classes/privacy/provider.php::get_metadata()`; add corresponding
  `privacy:metadata:scorecard_responses:itemid` lang string.

### Specification notes for v0.4

The Phase 1 build surfaced four spec corrections that will land in
SPEC v0.4:

- **§4.1 / §8.1 / §11 / §4.5:** rename schema column `minvalue` /
  `maxvalue` → `scalemin` / `scalemax`. `MAXVALUE` is a MySQL reserved
  keyword. (User-facing form labels stay "Minimum scale value" /
  "Maximum scale value".)
- **§8.5:** clarify that single-column foreign keys auto-generate an
  index in Moodle XMLDB; explicit `<INDEX>` elements are needed only
  for compound indexes covering different access patterns.
- **§9.5:** add `itemid` to the privacy metadata declaration for
  `scorecard_responses` (companion to `attemptid` for graph traversal
  during export).
- **New §:** "Avoid SQL reserved words in column names. Use the XMLDB
  editor (`/admin/tool/xmldb/`) when hand-writing `install.xml`; it
  validates against the union of MySQL/PostgreSQL/MSSQL/Oracle
  reserved-word lists."

The spec text in `docs/SPEC.md` remains v0.3 (point-in-time snapshot
of design intent at v0.1.0 release); v0.4 will be applied as a
separate spec-revision commit.
