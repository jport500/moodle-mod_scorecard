# mod_scorecard release notes

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
