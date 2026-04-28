# Phase 5b retrospective — mod_scorecard

Internal archaeological record of how Phase 5b (privacy provider +
nested backup steps + restore-side processors) actually unfolded.
Written immediately after v0.6.0 shipped (2026-04-28) so the lessons
are captured while still warm. Not a release note, not a changelog,
not customer-facing — this is for future Claude Code sessions reading
project history (and for future John re-orienting on the codebase) to
understand the methodology that worked, the discoveries the phase
made, and the calibrations to carry into Phase 6+.

CHANGES.md says what shipped. This document says how it shipped, why
the shape worked, what surprised us along the way, and what
methodology insights are durable enough to carry into subsequent
phases.

The Phase 4 retrospective at `080fe57` (`docs/PHASE-4-RETROSPECTIVE.md`)
established the shape; the Phase 5a retrospective at `ffdcddb`
(`docs/PHASE-5A-RETROSPECTIVE.md`) continued it. This is the third
instalment. The three retrospectives together form the methodology
archive for the surfaces shipped through v0.6.0 — Reports, Grade API,
Completion, Privacy, Backup/Restore.

---

## 1. Trajectory data

Phase 5b kickoff predicted **5–10 round-trips** for the privacy +
backup + restore surface, framed deliberately as a calibration-tax
phase because the Privacy API and the nested-backup/restore
machinery were genuinely new conceptual surfaces for this plugin
(Phases 1–5a had touched authoring, learner submission, reporting,
and the gradebook subsystem — none of which exercise the privacy
provider's full contract or the backup framework's nested-element
model). The prediction explicitly anticipated calibration tax in
early sub-steps, with a recalibrate-at-gates discipline carried over
from Phase 5a.

Phase 5b closed at **6 round-trips total**: 6 forward-progress
sub-steps with no fix-forwards. Within the kickoff range, in the
lower-middle of the prediction band — calibration-tax-honest in the
lower-bound-allowance sense.

### Per-sub-step prediction vs actual

| Sub-step | Commit | Prediction | Actual | Type B count | Notes |
|----------|--------|-----------:|-------:|-------------:|-------|
| 5b.1 — Privacy provider metadata fix + export contract | `0ecb11b` | 1–2 | 1 | 1 multi-part | Privacy API calibration (phpcs warnings only) |
| 5b.2 — Privacy provider delete contract | `f2c8fa0` | 1–2 | 1 | 2 tooling | Privacy API absorbed; transactional-rollback verification shape |
| 5b.3 — Backup steps for items + bands + completionsubmit completeness fix | `7af880c` | 1–2 | 1 | 3 tooling | Backup XML calibration; setAdminUser + MOODLE_INTERNAL banked |
| 5b.4 — Backup steps for attempts + responses (userdata-gated) | `f15e8d0` | 1–2 | 1 | 0 code-logic + 1 tooling post-commit | Backup XML absorbed; heredoc-escape pitfall surfaced + banked |
| 5b.5 — Restore steps for full nested structure | `7ad3999` | 1–2 (3 upper-bound) | 1 | 1 tooling (autoloader) | Restore + integration; course-correction-as-scope demonstrated |
| 5b.6 — Docs and v0.6.0 release | `1a8fb0a` | 1–2 | 1 | 0 | Docs + release; zero-friction sub-step |

### Cumulative growth

```
After 5b.1: 1 round-trip
After 5b.2: 2
After 5b.3: 3
After 5b.4: 4
After 5b.5: 5
After 5b.6: 6 — v0.6.0 shipped, Phase 5b closed
```

The trajectory was linear-at-1-per-sub-step from 5b.1 through 5b.6,
mirroring Phase 4 and Phase 5a's pace. Notably, **no fix-forward
sub-step occurred** — the first phase of the project to ship without
one since Phase 3. Phase 4 and Phase 5a both closed at 8 round-trips
with a single fix-forward each (4.6 → 4.6.5 layout regression,
5a.5 → fix-forward grade_update upgrade-context block). Phase 5b
closed at 6 with none.

The absence of a fix-forward is not because Phase 5b's surfaces were
simpler. The Privacy API and the backup/restore machinery have at
least as many failure modes as the Grade API surface that surprised
Phase 5a. The fix-forward absence is because the gate-discipline
checklist accumulated through Phase 5a was applied from kickoff
forward, plus the calibration-tax structure of Phase 5b's two
subsystems (Privacy at 5b.1–5b.2; Backup XML at 5b.3–5b.4) compressed
quickly enough that the upper-bound surfaces never bit. See sections
2 and 3 for the methodological detail.

### Range comparison

Phase 5b's range comparison: kickoff 5–10, actual 6, lower-bound
infeasible-as-stated (the lower bound of 5 would have required at
least one combined sub-step or an absent surface; six sub-steps each
landing in 1 round-trip was structurally the minimum), honest
expectation 5–7 became the realistic floor once the kickoff predicted
six independent sub-steps. The 6-round-trip outcome is the lower
bound of the realistic floor, against the 5-10 predicted range —
calibration-honest in the under-prediction-allowance sense.

### Test count growth

143 tests / 612 assertions at v0.5.0 → 168 tests / 728 assertions at
v0.6.0. Phase 5b added +25 tests / +116 assertions: 9 in
`tests/privacy/provider_test.php` (5b.1 + 5b.2), 9 in
`tests/backup/backup_test.php` (5b.3 + 5b.4), and 7 in
`tests/backup/restore_test.php` (5b.5). Plus the helper-extraction
work at 5b.5 introduced `tests/backup/backup_testcase.php` as an
abstract base — that's not a test file and contributes 0 tests, but
restructured the backup + restore test infrastructure for shared
fixture and pipeline helpers.

phpcs zero/zero plugin-wide preserved through every sub-step,
including the docs sweep at 5b.6.

### Type B aggregate

8 Type B friction instances absorbed across 6 sub-steps. Phase 5a
absorbed 8 Type B across 8 sub-steps; Phase 4 absorbed 8 Type B
across 8 sub-steps (with the 4.6 → 4.6.5 fix-forward representing
the 9th in some accountings). The per-phase Type B count appears
roughly invariant to sub-step count, suggesting **the per-phase Type
B budget is bounded by the conceptual-surface count, not the
sub-step count**. Phase 5b's two genuinely-new conceptual surfaces
(Privacy API; backup/restore framework) plus the integration sub-
step at 5b.5 produced approximately the same Type B count as Phase
5a's grade-API surface plus completion surface plus upgrade-path
surface at five sub-steps' worth of conceptual ground. Worth
tracking across Phase 6+ to see whether the invariance holds.

---

## 2. Per-subsystem calibration-tax compression curves

The Phase 4 and Phase 5a retrospectives both framed calibration-tax
absorption as a phase-aggregate property: pay the tax in early
sub-steps, ride the pattern bank in later ones. Phase 5b reveals
that framing as too coarse. Phase 5b's overall Type B sequence
(1, 2, 3, 0/1, 1, 0) doesn't fit the simple monotonically-declining
shape from earlier phases. The honest read is that **calibration-
tax compression curves operate per-subsystem within a phase, not
per-phase aggregate**. Compression operates within each conceptual
surface independently, not across all of a phase's surfaces.

### The Privacy API curve

5b.1 paid the calibration tax for the Privacy API surface: metadata
declaration shape, the `\core_privacy\local\metadata\provider`
interface, the export contract via `\core_privacy\local\request\
plugin\provider`, and the LEFT JOIN pattern for soft-deleted items
in the export query. The 1 multi-part Type B was confined to phpcs
warnings (line lengths in metadata declarations; multi-line function
call formatting) — phpcbf-autofixable. The conceptual surface was
new but the implementation pattern absorbed cleanly from
mod_assign's privacy provider as the canonical reference.

5b.2 inherited the Privacy API pattern bank cleanly. The delete
contract (three scopes:
`delete_data_for_all_users_in_context`, `delete_data_for_users`,
`delete_data_for_user`) followed the same pattern shape as 5b.1's
export contract. The 2 tooling Type B items were both inherited
shapes from 5b.1: phpcbf workflow for multi-line formatting, and a
new zsh inline-quoting parse error in the empirical CLI verification
script (resolved by switching to the write-to-file pattern via
heredoc, which became the banked workflow for subsequent
verification scripts). The privacy-API conceptual surface was paid
once at 5b.1 and carried forward to 5b.2 with friction confined to
tooling.

The Privacy API curve's shape: 1 → 2, both multi-part-but-tooling-
only, with the conceptual-surface tax fully absorbed at 5b.1.

### The Backup XML curve

5b.3 paid the calibration tax for the backup framework's nested-
element model: `backup_nested_element` instantiation conventions,
tree-building via `add_child`, source declarations via
`set_source_table` with `backup::VAR_PARENTID`, and the
`backup_activity_structure_step` lifecycle. The 3 tooling Type B
items were all genuinely-new tooling discoveries: (1) `setAdminUser`
required before `backup_controller` invocation in PHPUnit fixtures
(banked as `feedback_moodle_setadminuser_for_backup_controller.md`);
(2) MOODLE_INTERNAL contextual rule — required when files set
global state at top level but not for pure namespace-and-class files
(banked as `feedback_moodle_internal_contextual.md`); (3) phpcbf
workflow for multi-line function-call formatting (already banked
from 5b.2 but applied at 5b.3 again). The conceptual surface was
new and the implementation pattern absorbed from mod_assign's
backup steplib.

5b.4 inherited the Backup XML pattern bank with full compression: 0
code-logic Type B. The userdata-gated structure (attempts +
responses) followed 5b.3's exact pattern with `if ($userinfo)`
wrapping the `set_source_table` calls; the userinfo-toggle on
backup_controller worked first-try via
`$bc->get_plan()->get_setting('users')->set_value(...)`; the
snapshot-field serialization round-tripped exactly. The single
tooling Type B that surfaced (the heredoc-escape pitfall in commit
message body, which required two amend cycles to clean) appeared
post-commit and was banked as
`feedback_commit_message_heredoc_escapes.md` for future commits.

The Backup XML curve's shape: 3 → 0 code-logic + 1 post-commit
tooling, with the conceptual-surface tax fully absorbed at 5b.3 and
the inheritance fully clean at 5b.4.

### The Restore + integration sub-step

5b.5 was the integration sub-step: it touched a third conceptual
surface (the restore framework's `process_<element>` model with
`set_mapping` / `get_mappingid` for in-plugin cross-references) AND
required test infrastructure refactoring (the `backup_testcase`
abstract base extraction). The 1 tooling Type B was the autoloader
discovery — Moodle's test classloader doesn't autoload abstract
base classes from `tests/<subdir>/` paths even when sibling
`*_test.php` files in the same directory ARE autoloaded. The fix
(explicit `require_once(__DIR__ . '/backup_testcase.php')` in both
consumer files plus MOODLE_INTERNAL guard from the banked contextual
rule) was a within-Q-disposition refinement rather than a fallback
to the alternate option. See section 5 for the course-correction-as-
scope methodology refinement detail.

The restore conceptual surface itself absorbed cleanly: mod_assign's
restore_assign_stepslib.php was directly portable, the
`get_new_parentid` / `get_mappingid` framework worked first-try, and
the snapshot-fidelity preservation (per SPEC §11.2) was a matter of
inserting `$data` directly without recomputation. 0 code-logic Type
B at 5b.5 despite the new surface.

### The closing sub-step

5b.6 (docs + release v0.6.0) inherited the full Phase 5b banked
reflex set plus older banks (PHPUnit re-init from Phase 4.7, CLI
root-shim path from earlier Phase 4 work, commit-message-via-file
from 5b.4, MOODLE_INTERNAL contextual rule from 5b.3, phpcbf
workflow from 5b.2). All applied first-try; zero Type B friction.
See section 6 for the within-phase-pattern-bank-compounding-to-zero-
friction discussion.

### The methodology insight

The compression curves operate per-subsystem. Phase 5b's overall
trajectory looks anomalous if read as a single curve (1, 2, 3, 0,
1, 0 doesn't decline monotonically); it looks correct if read as
two independent curves plus an integration step plus a closing
step:

```
Privacy API:    5b.1 (1)  →  5b.2 (2)         absorbed at 5b.1, tooling-only at 5b.2
Backup XML:     5b.3 (3)  →  5b.4 (0+1)       absorbed at 5b.3, code-logic-clean at 5b.4
Restore:                      5b.5 (1)         new surface; absorbed within Q-envelope
Closing:                      5b.6 (0)         zero friction; full banked-reflex set
```

The per-phase calibration-tax framing from Phase 4 and Phase 5a
needed refinement; Phase 5b refined it. Future phases with multiple
conceptual surfaces should expect per-subsystem compression curves
rather than a single monotonic curve. Predict calibration tax
per-subsystem if multiple surfaces are in scope; aggregate the
per-subsystem predictions for the overall phase prediction.

---

## 3. The three-Q1-reversal pattern revealing the maxfiles=0 architectural fact

Phase 5b's most concrete methodology insight, and the one most
directly applicable to future phases. Three consecutive sub-step
pre-flights REVERSED their kickoff-recommended Q1 dispositions. All
three reversals trace to the same architectural fact: scorecard's
text fields have `maxfiles=0` in their authoring editor
configurations (`mod_form.php:105` for fallbackmessage,
`item_form.php:54` for item.prompt, `band_form.php:99` for
band.message). File-related defensive measures are structurally
precluded by the editor configuration itself; defensive defaults at
kickoff time were wrong because the architecture precludes the
threats those defaults address.

### 5b.3 Q1 reversal: no file annotations needed in backup steps

The 5b.3 kickoff defaulted to "annotate file areas defensively in
backup steps." This is the standard mod_assign convention: every
text field that COULD contain `@@PLUGINFILE@@` tokens gets a
`backup_nested_element->annotate_files()` call so the backup pipeline
captures attached files. Defensive shape; no harm if no files are
present.

Pre-flight grep evidence overturned the default. The file area
search across `mod/scorecard/` produced three hits, all maxfiles=0:

```
mod_form.php:105            ['maxfiles' => 0, 'noclean' => false]   (fallbackmessage editor)
classes/form/item_form.php:54   ['maxfiles' => 0, 'noclean' => false]   (item.prompt editor)
classes/form/band_form.php:99   ['maxfiles' => 0, 'noclean' => false]   (band.message editor)
```

All three authoring editors block file uploads at the form
configuration level — users CAN'T embed files via Atto/TinyMCE in
these fields. Therefore @@PLUGINFILE@@ tokens are structurally
impossible in the database; therefore no file areas are registered;
therefore no `annotate_files` calls are needed at backup time. Q1
reversed to "no file annotations needed."

### 5b.4 Q1 reversal: annotate ONLY core tables

The 5b.4 kickoff defaulted to "annotate cross-table refs defensively"
— specifically, that `attempt.bandid → scorecard_bands` and
`response.itemid → scorecard_items` should both get
`annotate_ids('scorecard_band', 'bandid')` and
`annotate_ids('scorecard_item', 'itemid')` calls so restore can
remap them.

Pre-flight reading of mod_assign canonical convention overturned the
default. mod_assign annotates IDs only against Moodle CORE tables —
`user`, `group`, `grouping` — and never against in-plugin tables.
The in-plugin cross-references work via a different mechanism
entirely: backup serializes raw IDs (no annotation needed), restore
processors call `set_mapping` for each row inserted, and downstream
processors call `get_mappingid` to remap. The framework distinction
is sharp: `annotate_ids` is for cross-Moodle-subsystem references
that need automatic resolution; `set_mapping`/`get_mappingid` is for
in-plugin references resolved at restore time. Q1 reversed to
"annotate ONLY core tables."

This was a different fact than 5b.3's (a framework-convention
distinction rather than a maxfiles-architectural-state fact), but
revealed the same methodology pattern: kickoff defensive defaults
are themselves provisional pending architectural verification;
pre-flight evidence may reveal the architecture handles the threat
through a different mechanism than the defensive default assumes.

### 5b.5 Q1 reversal: no decode rules needed

The 5b.5 kickoff defaulted to "add `define_decode_contents` entries
for items.prompt + bands.message defensively, matching mod_assign
convention." `define_decode_contents` lists tables and fields that
might contain `@@PLUGINFILE@@` tokens or inter-activity links so
the restore pipeline can decode them.

Same maxfiles=0 grep evidence as 5b.3. The fields can't contain
@@PLUGINFILE@@ tokens; therefore no decoding is needed at restore;
therefore no `define_decode_contents` entries are needed. Q1
reversed to "no decode rules needed."

### The pattern

The 5b.3 and 5b.5 reversals are the same architectural fact (the
maxfiles=0 editor configuration) viewed from backup-time and
restore-time directions respectively. The 5b.4 reversal is a
different fact (in-plugin cross-references convention) but reveals
the same methodology pattern.

The methodology insight worth banking: **kickoff Q recommendations
should NOT default to "defensive" without architectural
verification.** Defensive defaults are right when architectural
state is uncertain; when architectural state is verifiable at
pre-flight, dispositions should be based on actual state rather
than worst-case assumption. The kickoff-evidence-grounding reflex's
operational value is concretely measurable — three Q1 reversals in
three sub-steps means three potential fix-forward sub-steps avoided
plus three implementations that would have included
incorrect-or-cosmetic code.

This is a sharper version of the kickoff-evidence-grounding reflex
from Phase 4 retrospective Section 4. Phase 4 named "kickoff drift
between author memory and file reality" — the reflex of reading the
file before drafting "X has additions from phases A, B, C" framings.
Phase 5b refines the reflex: kickoff defaults can be wrong even when
accurately derived from prior-phase patterns, because the current
phase's architectural state may differ from what the defensive
default assumes.

The forward application: future kickoff Q drafts should distinguish
**architectural questions** (verifiable at pre-flight; defensive
defaults inappropriate without verification) from **decision
questions** (genuine choices among legitimate options; defensive
defaults appropriate as starting points). The three Phase 5b Q1
reversals were all architectural questions disguised as decision
questions in the kickoff text. Phase 6+ kickoffs should ask "is
this architectural or decisional?" before defaulting to defensive
disposition.

---

## 4. Empirical-bootstrap-state-verification gate discipline operationalized in five distinct shapes

Phase 5b's signature methodology asset. The empirical verification
at every sub-step gate exercised production code paths against real
dev-DB data; by phase close, the discipline had been demonstrated
across five structurally distinct operation shapes. The breadth is
strong evidence the discipline is genuinely general methodology, not
narrow to specific operation types.

The discipline's roots are in Phase 5a.5-fix-forward (where empirical
upgrade application caught the production-context divergence that
PHPUnit missed). Phase 5b extends the discipline beyond upgrade
application into every sub-step's gate verification, with the
operationalization shape adapted to the sub-step's specific work.

### 5b.1 — Shape 1: read-only export

Privacy export against the production `moodle_content_writer`. The
verification script invoked `provider::export_user_data` against a
real user context (admin user, dev DB scorecard id=2 with 33
attempts and 164 responses) and inspected the data structure that
landed in the writer. The verification was read-only — no DB
mutations — because export's contract is read-only.

The shape verified: data presence, field set, snapshot field
preservation, soft-deleted item handling via the LEFT JOIN.
Surfaced the `has_any_data()` test-vs-production divergence that
the first-cut PHPUnit assertions missed: `has_any_data()` exists on
the PHPUnit mock writer but not on the production
`moodle_content_writer`. The empirical verification caught the
divergence before the production code path had any chance to
execute the missing method.

### 5b.2 — Shape 2: state-modifying delete with transactional rollback

Privacy delete contract with rows actually deleted from
`scorecard_attempts` + `scorecard_responses`, wrapped in a PHPUnit
transaction that rolled back on test teardown. The verification
script ran `delete_data_for_all_users_in_context`,
`delete_data_for_users`, and `delete_data_for_user` against the dev
DB inside the transaction; row-count assertions confirmed deletion
behavior; the transaction unwound at the end so the dev DB was
unchanged.

The shape verified: children-first deletion ordering required by
the advisory FK on `scorecard_responses.attemptid → scorecard_attempts.id`,
the three-scope contract, and the preservation of items + bands +
scorecard activity rows (privacy delete touches user data only).
This was the first sub-step where the empirical verification ran a
state-modifying production code path; the transactional-rollback
pattern made it safe to run against the dev DB.

### 5b.3 — Shape 3: file artifact production

`backup_controller` invoked end-to-end against scorecard cm 163;
resulting .mbz extracted to `$CFG->dataroot/temp/backup/`;
`scorecard.xml` parsed and inspected via SimpleXMLElement xpath
queries. Verified items + bands round-trip including soft-deleted
rows; verified the completionsubmit completeness fix bundled at
5b.3 (the v0.5.0 gap where completionsubmit was missing from the
backup root-element field list).

The shape produced an actual file artifact (the .mbz) and inspected
its contents. The empirical verification was no longer just running
production code paths — it was producing the same file artifacts
the production backup wizard produces, then inspecting them with
the same parsing model the production restore wizard uses.

### 5b.4 — Shape 4: file artifact two-invocation comparison

Same backup_controller invocation pattern as 5b.3, but TWO
invocations — `userinfo=true` vs `userinfo=false` — comparing both
.mbz files against the same source data. The comparison verified
the gating mechanism by structural diff: the userinfo-on backup
included all 33 attempts + 164 responses with verbatim snapshot
field values; the userinfo-off backup excluded attempts and
responses entirely while preserving items + bands.

This was the first sub-step where empirical verification asserted
on real production data values, not just shape. User 129's actual
`bandlabelsnapshot="Concerning"`, with its specific HTML message
body starting `<p>Something needs to change. The good news is
you've alre...`, round-tripped through the backup XML byte-for-byte
verbatim. The verification was no longer "the gate works at all" —
it was "the gate preserves the specific snapshot fields the SPEC
§11.2 directive requires."

### 5b.5 — Shape 5: backup → restore round-trip with state-comparison

Most operationally complex Phase 5b shape. The verification:

1. Captured source state (6 items, 4 bands, 33 attempts, 164
   responses, 1 soft-deleted item) on the dev DB scorecard.
2. Ran `backup_to_mbz` with userinfo=true → `.mbz` file produced.
3. Created a fresh destination course via `create_course`.
4. Extracted `.mbz` to backup tempdir under a unique backupid;
   invoked `restore_controller` with `TARGET_NEW_COURSE`.
5. Asserted on the restored scorecard's state vs source: items +
   bands counts identical; soft-deleted item preserved with
   `deleted=1`; new item/band IDs reassigned (no overlap with
   source); `response.itemid` → new item ids via
   `set_mapping`/`get_mappingid`; `attempt.bandid` 3 → 8 (new id)
   but `bandlabelsnapshot="Concerning"` verbatim (snapshot
   preserved); `totalscore=10`, `maxscore=50`, `percentage=20.00`
   verbatim.
6. Ran the gate again with userinfo=true → false — verified
   restore-side gating: 0 attempts, 0 responses, items + bands
   intact.
7. Cleaned up via `delete_course` for both destination courses.

18 of 18 gate checks PASS. The verification exercised the full
backup → restore pipeline against real production data with
state-comparison assertions on every invariant the SPEC and the
implementation were supposed to preserve.

### The progression and the methodology insight

Each shape verifies a different operational dimension:

```
Shape 1 (5b.1):  read-only export                    → data presence, field set, query joins
Shape 2 (5b.2):  state-modifying delete + rollback   → deletion semantics, FK ordering
Shape 3 (5b.3):  file artifact production            → backup file format and content
Shape 4 (5b.4):  two-invocation comparison           → gating mechanisms, value preservation
Shape 5 (5b.5):  bidirectional round-trip            → full pipeline, ID remapping, state-comparison
```

The progression is genuinely methodologically interesting:
read-only operations (1) → state-modifying with rollback (2) → file
artifact production (3) → multi-invocation comparison (4) →
bidirectional round-trip with state-comparison (5). Each shape
requires more bootstrap state than the prior; each verifies
invariants the prior shape couldn't.

The methodology insight worth banking: **the empirical-bootstrap-
state-verification gate discipline scales across structurally
distinct operation types.** When realistic dev-DB data exists (33
attempts, 164 responses for scorecard id=2), use it for verification
— not just synthetic fixtures. Test fixtures often don't exercise
the edge cases real data does (real HTML structure with `<p>` tags
and special characters; real soft-deleted items with responses
attached; real attempt sequences with snapshot field values
deliberately distinct from current band state).

Real-data verification catches a class of regression that test
fixtures miss. Concrete evidence: 5b.4's verification asserted user
129's actual `bandmessagesnapshot` HTML through backup XML
serialization. If a future edit accidentally routed snapshot fields
through `format_text()` (which mutates HTML for safety), PHPUnit
might pass against simple test fixtures (where the snapshot value
is `'Frozen snapshot label 0'` with no mutable HTML structure) but
production would fail (where real `<p>` tags get reformatted to
something else). The empirical-against-real-data discipline catches
this class of regression before production.

The forward application: future phases should expect to
operationalize the discipline against whatever new operation shapes
their conceptual surface introduces. Privacy API surfaces produced
shapes 1 and 2; backup/restore surfaces produced shapes 3, 4, and 5.
Phase 6's surfaces (whatever they turn out to be) will likely
produce one or two new shapes, plus inherit shapes 1–5 as available
patterns.

---

## 5. Course-correction-as-scope methodology refinement

Genuinely new methodology insight, surfaced at 5b.5 and worth its
own section.

The 5b.5 kickoff Q2 authorized course-correction toward (b)
duplicate-with-drift-risk if the (a) extract-abstract-base approach
surfaced PHPUnit discovery quirks. The kickoff text framed the
authority as a binary fallback: "if (a) fails, switch to (b)."

Reality: PHPUnit discovery quirks DID surface — Moodle's test
classloader doesn't autoload `mod_scorecard\backup\backup_testcase`
from a `tests/<subdir>/` location, even though it autoloads
`*_test.php` files in the same directory. The first attempt to
load `backup_test.php` produced the opaque error `Class
"mod_scorecard\backup\backup_testcase" not found` at the
PHPUnit test runner level.

But the resolution was **not** a fallback to (b) duplication. The
resolution was a within-(a) refinement: explicit
`require_once(__DIR__ . '/backup_testcase.php')` in both consumer
files (`backup_test.php` and `restore_test.php`), plus
MOODLE_INTERNAL guard from the banked contextual rule (because the
files now set global state at top level via `require_once`). The
within-(a) refinement preserved the abstract-base architectural
intent — both consumer files extend `backup_testcase`, sharing the
fixture builder and the backup-pipeline helper — while resolving
the autoloader discovery friction.

### Why this matters as methodology

The kickoff Q dispositions had been treating course-correction
authority as binary: pick option (a), authorize (b) as fallback.
That framing is too narrow. Real implementation friction often
admits a within-option refinement that resolves the friction
without abandoning the option's intent. The 5b.5 case was the
canonical example: option (a) was "abstract base for shared
helpers"; the friction was "Moodle's autoloader doesn't find
abstract base classes in tests/<subdir>/"; the refinement was
"abstract base for shared helpers, loaded via explicit
require_once instead of relying on autoload." The architectural
intent (shared helpers via extension) was fully preserved.

The methodology insight worth banking: **pre-authorized course-
correction is not necessarily a binary fallback to the alternate
option; it can be within-option refinement that resolves the
friction without abandoning the option's intent.** Kickoff Q
dispositions should authorize course-correction in scope rather
than direction. "Course-correct as needed" is broader (and more
useful) than "fall back to (b) if (a) fails."

### Forward application

Phase 6+ kickoffs should adopt this framing. When a Q has multiple
options and the recommended option has uncertainty, authorize
course-correction in scope rather than naming a specific alternate
option as the fallback. The scope framing accommodates within-
option refinement (preserves architectural intent), between-option
fallback (abandons intent for a different intent), AND novel
resolutions the kickoff didn't anticipate.

Concretely, Phase 5b.5's Q2 kickoff text said:

> Course-correct to (b) duplicate-with-drift-risk if PHPUnit
> discovery quirks or other refactor friction surfaces at gate.

A scope-framed version would have said:

> Course-correct as needed if PHPUnit discovery quirks or other
> refactor friction surfaces at gate; (b) duplicate-with-drift-
> risk is one available fallback shape, but within-(a) refinement
> or novel resolutions are also in scope.

The scope framing communicates the same authority more accurately.
It's a small phrasing difference but a meaningful methodological
clarification.

This is a sharpening of how kickoff Q dispositions are drafted.
It's not a memory-bank entry (it doesn't surface a Moodle-specific
technical reflex); it's a kickoff-drafting discipline refinement
that's worth carrying into Phase 6+ kickoff drafts.

---

## 6. Within-phase pattern bank compounding to zero friction

Phase 5b.6 closed at 1 round-trip with zero Type B friction. This
is the calibration-tax compression curve at its natural floor —
when the pattern bank is sufficiently mature within a phase, even
substantial scope (3 files, voice-precedent matching, multi-stage
gate verification including empirical upgrade application) produces
zero-friction outcomes.

### What 5b.6 actually involved

The sub-step shipped:

- README.md edits across 6 distinct sites: Status section rewrite
  for v0.6.0 framing; Phase status table row 5b update from
  "planned" to "shipped v0.6.0"; Installation expected version stamp
  bump from 2026042703 to 2026042704; SPEC reference line extension
  noting Phase 5b shipped without a SPEC bump; two new top-level
  sections (## Privacy and ## Backup and restore) totaling ~80
  lines of operator-facing prose; Running tests file list expansion
  from 10 to 17 entries adding access, export, grade, report,
  privacy/provider, backup/backup, and backup/restore (the Phase 4
  + 5a + 5b additions previously not listed).
- CHANGES.md new top entry (~140 lines) matching v0.5.0's structured-
  subsections shape: bolded summary, ### Shipped paragraphs (one
  per shipped feature), ### Operator action, ### Quality gates,
  ### Spec status, ### Followups carried forward.
- version.php two-line bump: numeric `2026042703` → `2026042704`,
  release `v0.5.0` → `v0.6.0`. MATURITY_ALPHA preserved.
- Annotated tag `v0.6.0` at the docs commit with a two-line short-
  pointer body matching the v0.4.0 / v0.5.0 precedent.
- Gate verification: PHPUnit re-init (post-version-bump banked
  reflex from Phase 4.7 applied first-try), plugin-wide PHPUnit
  168/728 regression preserved through edits, plugin-wide phpcs
  zero/zero, empirical upgrade application via `php admin/cli/
  upgrade.php --non-interactive` confirming DB stamp moves to
  2026042704 cleanly.
- Single commit with file-based message body via
  `git commit -F /tmp/commit_msg_5b6.txt` (banked discipline from
  5b.4).

This is real complexity. The implementation was mechanical not
because the work was small but because the patterns were known.

### Which banked reflexes applied

5b.6 inherited the full Phase 5b banked reflex set plus older
banks:

- **PHPUnit re-init** after version-stamp bump (banked Phase 4.7,
  reapplied throughout Phase 5a, applied at 5b.6 first-try).
- **CLI root-shim path** — `admin/cli/upgrade.php` is at the project
  root, not under `public/` (banked earlier in Phase 4 work,
  applied at 5b.6 after one initial mistake in path).
- **Commit-message-via-file** — write to `/tmp/commit_msg_*.txt`,
  invoke via `git commit -F` (banked at 5b.4 after the heredoc-
  escape pitfall, applied at 5b.5 first-try, applied at 5b.6 from
  the start).
- **MOODLE_INTERNAL contextual rule** (banked at 5b.3, applied at
  5b.5 with the new abstract-base, didn't apply at 5b.6 since the
  edited files don't add new top-level state).
- **phpcbf workflow** (banked at 5b.2, applied at 5b.3 and 5b.4,
  didn't surface at 5b.6 since no formatting nits).

The single momentary friction at 5b.6 (the CLI root-shim path
retry) resolved instantly via banked memory rather than as a Type
B. The first attempt at `php public/admin/cli/upgrade.php
--non-interactive` failed with "Could not open input file" because
the script lives at root, not public/; the second attempt at
`php admin/cli/upgrade.php --non-interactive` succeeded. The
correction was a memory-recall, not a debugging session.

### The methodology insight

Worth naming as a refinement to the calibration-tax framing in
Phase 5a retrospective Section 4: **late-phase sub-steps inheriting
the full banked reflex set from earlier sub-steps can achieve zero-
Type-B-friction outcomes even with substantial scope.** This is
operationally distinct from "easy sub-steps land in 1 round-trip"
— 5b.6 had real complexity. The friction surfaces were all banked;
the implementation was mechanical not because the work was small
but because the patterns were known.

The methodology insight: tooling reflexes banked early in a phase
pay forward to later sub-steps in the same phase, not just to
subsequent phases. Phase 4 retrospective Section 2 named "compound
dividend from a mature pattern bank across phases." Phase 5b
refines: within-phase pattern bank compounding has its own
compression curve, and late-phase sub-steps can reach zero Type B
friction within a single phase if the pattern bank matures
sufficiently.

The forward application: Phase 6+ should treat banked-reflex
inheritance as cumulative within the phase, not just across phases.
The first sub-step in a new conceptual surface pays the calibration
tax; subsequent sub-steps in the same surface ride the bank;
late-phase sub-steps in well-trodden territory (docs, release,
follow-up cleanup) can reach zero-friction operation.

---

## 7. Calibration honesty operates bidirectionally

Phase 5a hit upper-middle of its 5–10 prediction range (8 round-
trips, with 5a.5 → fix-forward absorbing the upper-bound allowance).
Phase 5b hit lower-middle (6 round-trips, with no fix-forward).
Both phases shipped at calibration-honest pacing, but in different
directions.

### What "calibration honesty" means in both directions

The Phase 4 retrospective and Phase 5a retrospective both framed
calibration tax as something paid in early sub-steps and absorbed
across the phase. Both phases hit upper-middle of their prediction
ranges, suggesting calibration honesty meant "predict the range,
then hit somewhere inside it via the calibration-tax dynamics." The
structural shape was: 5–10 predicted; 8 actual; the 3 round-trips
above the lower bound were the calibration tax made visible.

Phase 5b complicates this framing. 5–10 predicted; 6 actual; the 1
round-trip above the lower bound was almost negligible. Where did
the calibration tax go? The honest answer is: the calibration tax
was paid in the kickoff predictions themselves. Each sub-step
kickoff predicted 1–2 honest with 3 upper-bound; the upper-bound
allowance was the calibration tax baked into the prediction
structure. Phase 5b's actuals consistently hit the 1-honest lower
bound because the calibration tax was anticipated correctly at
prediction time.

Phase 5a's actuals consistently hit the 1–2 honest range with 1
fix-forward; the upper-bound allowance materialized as the
fix-forward (5a.5 → fix-forward). Phase 5b's actuals consistently
hit the 1-honest lower bound; the upper-bound allowance never
materialized.

### The methodology insight

Worth naming: **calibration honesty isn't always hitting the same
point in the prediction range; it's predicting honestly relative to
actual complexity.** Hitting the lower bound (under-prediction risk
realized as faster-than-expected) is calibration-honest in the
lower-bound-allowance sense. Hitting the upper bound (over-
prediction risk realized as slower-than-expected, possibly via
fix-forwards) is calibration-honest in the upper-bound-allowance
sense. Both are valid trajectory outcomes; the discipline is
predicting honestly relative to the work, not always hitting the
same point.

Phase 5b's lower-middle hit is a specific data point: when pattern
bank inheritance is rich (Phase 5a's grade API patterns plus Phase
4's report patterns plus earlier conventions all available) AND the
new conceptual surfaces compress quickly (Privacy API at 5b.1–5b.2
with mod_assign as canonical reference; Backup XML at 5b.3–5b.4
with mod_assign and mod_choice as canonical references), trajectories
can hit lower-bound naturally. When pattern bank inheritance is
sparse and new conceptual surfaces require fix-forward correction
(5a.5 backfill savepoint broken-as-committed, requiring 5a.5-
fix-forward), trajectories can hit upper-bound naturally.

### Forward application

Future phase predictions should distinguish two sub-questions:

1. **Range prediction.** What's the realistic 5-95th-percentile
   range for this phase's round-trip count? Driven by: number of
   sub-steps anticipated, number of conceptual surfaces in scope,
   richness of pattern bank inheritance, presence of known-difficult
   surfaces (e.g., the Grade API's upgrade-context divergence).

2. **Direction prediction.** Within the range, where does the
   trajectory likely land? Driven by: same factors, but weighted
   by whether the pattern bank covers the conceptual surfaces
   (compressing toward lower bound) versus whether the conceptual
   surfaces are genuinely new and likely to surface fix-forwards
   (extending toward upper bound).

For phases inheriting rich pattern banks against well-known
surfaces, predict toward lower-bound. For phases introducing
genuinely new conceptual surfaces, predict toward upper-bound with
fix-forward allowance. Phase 5b was the former; Phase 5a was a
mix. Phase 6's prediction will depend on what surfaces are in
scope — to be evaluated at Phase 6 kickoff drafting time.

The phrasing for kickoff predictions going forward: rather than
"predict honestly" (vague), "predict honestly relative to expected
calibration tax for the conceptual surfaces in scope" (specific).
Name the surfaces; name the pattern-bank coverage; name whether
fix-forwards are anticipated. The prediction quality improves when
the structure is explicit.

---

## 8. Methodology insights banked + memory bank state at Phase 5b close

Snapshot of new banked tooling reflexes from Phase 5b plus
methodology insights worth retrospective inclusion. The reader of
this retrospective shouldn't need to dig through individual memory
files to know what was learned.

### New tooling reflex memories banked off-commit during Phase 5b

**`feedback_moodle_internal_contextual.md`** (banked at 5b.3). The
`defined('MOODLE_INTERNAL') || die();` guard is contextual: required
for files that set global state at file level (top-level
`require_once`, top-level function definitions, top-level statements
outside classes), forbidden for files that are namespace + use +
class declarations only. moodle-cs flags the wrong shape in either
direction — "Expected MOODLE_INTERNAL" when missing, "Unexpected
MOODLE_INTERNAL" when present unnecessarily. Surfaced when 5b.3's
backup_scorecard_stepslib.php hit the phpcs warning unexpectedly;
the resolution was understanding the rule's contextual nature
rather than blindly adding or removing the guard. Applied at 5b.5
in the abstract-base-with-explicit-require_once shape.

**`feedback_moodle_setadminuser_for_backup_controller.md`** (banked
at 5b.3). `backup_controller`, `restore_controller`, and similar
plan-execution controllers require a valid global `$USER`. PHPUnit
doesn't authenticate one by default; `$this->setAdminUser()`
populates `$USER` with the admin user record for the duration of
the test. Banked because the error message ("invalid user")
produced by the controllers is opaque relative to the fix. Applied
at 5b.4 first-try (no friction) and at 5b.5 in the
`restore_into_new_course` helper.

**`feedback_commit_message_heredoc_escapes.md`** (banked at 5b.4
post-commit). For any nontrivial commit message body containing PHP
variable references (`$variable`), code samples, or special
characters, use Write tool to `/tmp/commit_msg.txt` followed by
`git commit -F /tmp/commit_msg.txt` rather than single-quoted
heredocs with potential backslash interactions. Banked after two
amend cycles in one session (the initial 5b.4 commit had stray
backslash escapes from defensive `\$` in a single-quoted heredoc;
the first amend fixed some but missed others; the second amend via
file-based message landed clean). The discipline was applied at
5b.5 from the start (no amend cycles needed) and at 5b.6 from the
start (zero-friction sub-step).

### Refinements to existing entries (applied during Phase 5b)

**`feedback_moodle_phpunit_reinit_after_version_bump.md`** (banked
during Phase 4.7). Applied at 5b.6 first-try after the
2026042703 → 2026042704 numeric stamp bump. The existing memory
remains accurate; the application count grows. Phase 5a applied
this five times; Phase 5b applied it once at 5b.6.

**`feedback_moodle_cli_paths.md`** (banked earlier; applied at 5b.6
after one initial mistake). The CLI scripts at root, not under
public/. The application was instant via memory-recall once the
first attempt failed.

### Methodology insights worth retrospective inclusion (no separate memory files)

These are not Moodle-API tooling reflexes; they're methodology
refinements that belong in retrospectives rather than the memory
bank. The memory bank captures rules that apply at specific
file-level technical decisions; methodology insights apply at
kickoff drafting and gate verification, where the retrospective is
the load-bearing reference document.

1. **Per-subsystem calibration-tax compression curves** (section 2).
   Refinement to the Phase 4/5a "compound dividend from pattern bank"
   framing. Compression operates within each conceptual surface
   independently, not across all of a phase's surfaces. Future
   phase kickoffs with multiple conceptual surfaces should predict
   per-subsystem rather than per-phase aggregate.

2. **Three-Q1-reversal pattern revealing architectural facts**
   (section 3). Sharper version of Phase 4's kickoff-evidence-
   grounding reflex. Kickoff defensive defaults are themselves
   provisional pending architectural verification; pre-flight
   evidence may reveal architecture precludes the threat the
   defense addresses. Future kickoffs should distinguish
   architectural questions (verifiable at pre-flight; defensive
   defaults inappropriate without verification) from decisional
   questions (genuine choices among legitimate options; defensive
   defaults appropriate as starting points).

3. **Five-shape empirical-bootstrap-state-verification
   operationalization** (section 4). Strong evidence the discipline
   is general methodology, not narrow to specific operation
   patterns. Future phases should expect to operationalize the
   discipline against whatever new operation shapes their
   conceptual surface introduces; treat shape inheritance as
   cumulative across phases.

4. **Course-correction-as-scope methodology refinement** (section
   5). Kickoff Q dispositions should authorize course-correction in
   scope rather than direction. The scope framing accommodates
   within-option refinement, between-option fallback, AND novel
   resolutions the kickoff didn't anticipate.

5. **Within-phase pattern bank compounding to zero friction**
   (section 6). Late-phase sub-steps inheriting the full banked
   reflex set from earlier sub-steps can achieve zero-Type-B-
   friction outcomes even with substantial scope. The implementation
   is mechanical not because the work is small but because the
   patterns are known.

6. **Calibration honesty operates bidirectionally** (section 7).
   Hitting lower-bound is calibration-honest in the under-prediction-
   allowance sense; hitting upper-bound is calibration-honest in
   the over-prediction-allowance sense. Both are valid trajectory
   outcomes. Future predictions should name the conceptual
   surfaces, the pattern-bank coverage, and the fix-forward
   allowance explicitly.

7. **Per-phase Type B budget appears roughly fixed regardless of
   sub-step count** (section 1 trajectory observation). Phase 4: ~8
   Type B across 8 sub-steps. Phase 5a: ~8 Type B across 8 sub-
   steps. Phase 5b: 8 Type B across 6 sub-steps. The bound seems to
   be conceptual-surface-driven rather than sub-step-count-driven.
   Worth tracking across Phase 6+ to see whether the invariance
   holds or whether it's coincidence.

8. **Documentation-debt and metadata-completeness fix bundling
   pattern.** Three instances in Phase 5b: 5b.1 itemid metadata
   (privacy export couldn't resolve which prompt a response value
   answered without itemid in the metadata declaration); 5b.3
   completionsubmit field (the v0.5.0 schema column was missing
   from the backup root-element field list, so backed-up
   completionsubmit settings reverted to the schema floor on
   restore); 5b.6 Running tests file list (the Phase 4 + 5a + 5b
   test additions had accumulated without being added to the README's
   explicit test invocation list). The pattern: completeness fixes
   for prior-phase gaps land at the next opportune sub-step rather
   than as standalone fix-forwards. Worth banking as a discipline:
   docs-and-release sub-steps are the natural collection point for
   documentation debt; subsystem implementation sub-steps are the
   natural collection point for metadata-completeness fixes.

9. **Operator-voice vs methodology-voice discipline for changelogs.**
   Customer-facing release notes preserve methodology-honest content
   (don't omit fixes that ship — both completeness fixes appeared in
   v0.6.0's CHANGES.md ### Shipped subsection) while using operator-
   meaningful voice (don't import methodology archaeology — the
   "5b.1 bundled" / "5b.3 bundled" framing names the fix without
   the inter-development churn detail). The compression is in the
   framing, not the content. The Phase 5a 5a.5 → fix-forward
   precedent already demonstrated this; Phase 5b's two completeness
   fixes were the next application.

10. **Empirical-against-real-data discipline.** When realistic
    production data exists (33 attempts, 164 responses for
    scorecard id=2), use it for verification — not just synthetic
    fixtures. Catches a class of regression test fixtures miss:
    real HTML structure with `<p>` tags and special characters;
    real cross-table soft-delete with responses attached to deleted
    items; real attempt sequences with snapshot field values
    deliberately distinct from current band state. Concrete
    evidence: 5b.4 + 5b.5 verifications asserted user 129's actual
    snapshot field values through backup XML serialization and
    backup → restore round-trip respectively.

### Aggregate memory bank state at Phase 5b close

Phase 4 retrospective listed 7 banked memories and refinements.
Phase 5a retrospective listed 5 new banked memories plus 1 carried
forward. Phase 5b adds 3 new banked memories plus refinements to
2 existing entries.

Total durable reflex catalogue at Phase 5b close (approximate):

- ~9 build-session methodology reflexes (commit trailers, git
  preflight, kickoff evidence-grounding, surface implementer
  improvements, write-time discipline, explicit commit approval,
  commit-message-via-file from 5b.4, etc.).
- ~14 Moodle-specific API/testing reflexes (CLI paths, version
  stamps, archetype names, access propagation, language cache
  purge, PHPUnit re-init, grade_item fetch, request cache priming,
  moodle-cs divergence, upgrade-mode residual flag, grade_update
  upgrade-block, limitedwidth body class, MOODLE_INTERNAL
  contextual from 5b.3, setAdminUser-for-backup-controller from
  5b.3).
- ~3 phase-prediction methodology reflexes (calibration risk,
  compound helper design, parallel-surface comparison).

The catalogue is past 25 entries. The MEMORY.md index is doing the
right work — each entry's hook line lets future-Claude scan the
catalogue without reading every memory file. As the catalogue
crosses 30 entries (Phase 6 will likely push past), an explicit
topical organization (Moodle-API vs build-process vs prediction-
methodology vs gate-discipline-evolution) may become useful. For
now, chronological order with topical hook lines remains
sufficient.

**Aggregate insight.** Phase 5b was a memory-bank-rich phase
similar in shape to Phase 4 and Phase 5a. Three new durable
methodology entries banked plus 10 retrospective-only methodology
insights (this section). The `feedback_*` memory type continues
to do its load-bearing work — rules captured at their evidence
point, with `**Why:**` and `**How to apply:**` framing that makes
the rule applicable to future situations. The retrospective-only
methodology insights are appropriately separate — they apply at
kickoff drafting and gate verification, where the retrospective
document is the load-bearing reference.

---

## Closing

v0.6.0 ships with the privacy provider, backup, and restore
surfaces complete. Three new durable Moodle-internal-API reflex
memories banked. Ten retrospective-only methodology insights
captured. The gate-discipline checklist extended from Phase 5a's
"empirical upgrade application for db/upgrade.php-touching sub-
steps" to Phase 5b's "empirical bootstrap-state verification at
every sub-step gate, in the operationalization shape appropriate
to the sub-step's work." Phase 5b is closed.

The headline trajectory number — 6 round-trips against 5–10
predicted, with no fix-forward — is real but should not be the
headline lesson. The headline lessons are:

1. **Per-subsystem calibration-tax compression curves are the
   correct unit of analysis** for phases with multiple conceptual
   surfaces. Phase 5b's Privacy API and Backup XML surfaces each
   had their own compression curve; aggregate-phase framing
   would have missed the structure. Future phase predictions
   should decompose by conceptual surface, predict per-surface,
   and aggregate to the phase level.

2. **Kickoff defensive defaults are themselves provisional pending
   architectural verification.** Three consecutive Q1 reversals in
   Phase 5b sub-step pre-flights demonstrated that kickoff defaults
   accurately derived from prior-phase patterns can still be wrong
   when the current phase's architectural state precludes the
   threats those defaults address. The kickoff-evidence-grounding
   reflex from Phase 4 needed sharpening; Phase 5b sharpened it.

3. **The empirical-bootstrap-state-verification gate discipline
   scales across structurally distinct operation types.** Five
   shapes operationalized in Phase 5b — read-only export,
   transactional-rollback delete, file artifact production, two-
   invocation comparison, bidirectional round-trip with state-
   comparison. Strong evidence the discipline is general
   methodology, not narrow to specific operation patterns. Future
   phases should expect to operationalize new shapes against
   whatever conceptual surfaces are in scope.

4. **Course-correction authority should be authorized in scope, not
   direction.** Within-option refinement preserves architectural
   intent and resolves friction without abandoning the option's
   intent. Phase 5b.5's autoloader-via-explicit-require_once was
   the canonical example. Future kickoffs should adopt scope-
   framed authorization.

5. **Calibration honesty operates bidirectionally.** Hitting the
   lower bound is calibration-honest in the under-prediction-
   allowance sense; hitting the upper bound is calibration-honest
   in the over-prediction-allowance sense. Both are valid
   trajectory outcomes. Predict honestly relative to expected
   calibration tax for the conceptual surfaces in scope.

None of these are Phase 5b-specific. All of them apply to Phase 6+
kickoff drafting immediately.

Phase 5b closed at v0.6.0. Pattern bank carries forward — now with
two more conceptual-surface banks (Privacy API; backup/restore
framework) and one more meta-pattern (per-subsystem calibration-tax
compression curves) added to the methodology archive.

Next operations after this retrospective lands and ships:

- **Priority 1: lms-light-docs/METHODOLOGY.md synthesis.** Drawing
  from Phase 4, Phase 5a, and Phase 5b retrospectives plus the
  banked memory files. Becomes the canonical "supervised-agentic
  Moodle plugin development methodology" reference for future
  plugin work outside mod_scorecard.

- **Priority 2: lms-light-docs/MOODLE-ACTIVITY-MOD-PHASES.md
  structural template.** Extracted from mod_scorecard's phase
  progression (Phase 1 skeleton → Phase 2 authoring → Phase 3
  learner submission → Phase 4 reporting → Phase 5a gradebook +
  completion → Phase 5b privacy + backup/restore). Becomes the
  canonical "what phases an activity-mod plugin needs and roughly
  what they cover" reference.

- **Priority 3: reflex catalogue.** Opportunistic; not blocking.

These are separate-session deliverables. This retrospective is the
input material; the synthesis documents come after.

Phase 5b retrospective closed. v0.6.0 in production.
