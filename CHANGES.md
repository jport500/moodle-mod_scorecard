# mod_scorecard release notes

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
