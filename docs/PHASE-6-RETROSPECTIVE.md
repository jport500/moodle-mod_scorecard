# Phase 6 retrospective — mod_scorecard

Internal archaeological record of how Phase 6 (JSON template export and
import) actually unfolded. Written immediately after v0.7.0 shipped
(2026-04-28) so the lessons are captured while still warm. Not a
release note, not a changelog, not customer-facing — this is for future
Claude Code sessions reading project history (and for future John
re-orienting on the codebase) to understand the methodology that
worked, the discoveries the phase made, and the calibrations to carry
into Phase 7+.

CHANGES.md says what shipped. This document says how it shipped, why
the shape worked, what surprised us along the way, and what
methodology insights are durable enough to carry into subsequent
phases.

The Phase 4 retrospective at `080fe57`
(`docs/PHASE-4-RETROSPECTIVE.md`) established the shape; the Phase 5a
retrospective at `ffdcddb` (`docs/PHASE-5A-RETROSPECTIVE.md`)
continued it; the Phase 5b retrospective at `f699fad`
(`docs/PHASE-5B-RETROSPECTIVE.md`) was the third instalment. This is
the fourth. The four retrospectives together form the methodology
archive for the surfaces shipped through v0.7.0 — Reports, Grade API,
Completion, Privacy, Backup/Restore, JSON Templates.

Phase 6 is the first phase whose conceptual surface is plugin-specific
rather than canonical Moodle subsystem work. The
`MOODLE-ACTIVITY-MOD-PHASES.md` template at lms-light-docs explicitly
notes "Phases beyond 5b are plugin-specific feature work; no general
template applies." Phase 6's methodology archaeology may inform future
activity-mod feature phases without prescribing template shape — the
patterns are portable, the phase boundaries are not.

---

## 1. Trajectory data

Phase 6 kickoff predicted **5–8 round-trips** for the JSON template
surface, framed as a calibration-tax-moderate phase: the JSON envelope
shape and validation patterns were genuinely new conceptual surfaces,
but the helper-decomposition + serialization conventions inherited
from Phase 5b's backup XML work were directly portable. The prediction
explicitly anticipated one round-trip per sub-step at the floor with
upper-bound allowance for any operator-facing UI surfaces.

Phase 6 closed at **7 round-trips total**: 6 forward-progress
sub-steps with one of them (6.5 → 6.5b) absorbing an effective second
round-trip on the same conceptual sub-step via architectural reversal.
Within the kickoff range, in the lower-middle of the prediction band —
calibration-tax-honest in the
"upper-bound-allowance-materialized-as-reversal" sense.

### Per-sub-step prediction vs actual

| Sub-step | Commit | Prediction | Actual | Notes |
|----------|--------|-----------:|-------:|-------|
| 6.0 — SPEC v0.4.2 → v0.5.0 (§9.6 directive) | `ee99869` | 1 | 1 | SPEC-only commit; first sub-step; CHANGES Spec status block introduced |
| 6.1 — Export pipeline (helper + endpoint + UI + tests) | `1f18817` | 1 | 1 | Export envelope shape locked; lang cache miss banked at walkthrough |
| 6.3 — Validation helper (errors + warnings two-array) | `526d736` | 1 | 1 | One in-gate FORMAT_HTML fix-forward; insights 2/3/4 banked |
| 6.4 — Instantiation via add_moduleinfo + populate | `6f58d04` | 1 | 1 | Three in-gate tooling fix-forwards; insights 5/6/7 banked; phpcs caveat closed |
| 6.5 — UI architectural reversal (course-nav → manage empty-state) | `9425845` | 1–2 (3 upper) | 2 | Original 6.5 never committed; 6.5b consolidated forward; insight 8 banked |
| 6.6 — Docs and v0.7.0 release | `c4fa24d` | 1 | 1 | Cleanest calibration-tax-floor sub-step of Phase 6 |

Note on numbering: the kickoff anticipated a 6.2 (export tests
companion sub-step) but the Q15 disposition folded the export-side
PHPUnit work into 6.1 directly, reflecting the helper-decomposition
discipline (test the helper at first introduction, not in a follow-up
sub-step). 6.2 never materialized as a commit. The numbering preserves
the kickoff structure — sub-step 6.3 stays 6.3 rather than slipping to
6.2 — to keep the retrospective and the kickoff cross-referenceable.

### Cumulative growth

```
After 6.0: 1 round-trip
After 6.1: 2
After 6.3: 3
After 6.4: 4
After 6.5b: 6  (the 6.5 → 6.5b reversal absorbed 2 round-trips conceptually)
After 6.6: 7 — v0.7.0 shipped, Phase 6 closed
```

The trajectory was linear-at-1-per-sub-step from 6.0 through 6.4 and
again at 6.6, with the 6.5 → 6.5b reversal absorbing the
upper-bound allowance Phase 6's prediction had reserved for
operator-facing UI surfaces. This is the structural shape Phase 5a and
Phase 4 produced via fix-forward sub-steps (4.6 → 4.6.5,
5a.5 → fix-forward); Phase 6's variant kept the reversal within the
same sub-step rather than committing the broken state and then
fix-forwarding. See section 5 for the methodological detail of why the
within-sub-step reversal was the right shape.

### Range comparison

Phase 6's range comparison: kickoff 5–8, actual 7. The 7-round-trip
outcome is one above the lower-middle of the realistic floor. Honest
expectation 6 became the realistic floor once the kickoff predicted
six independent sub-steps; the +1 came from the 6.5 → 6.5b reversal.
Calibration-honest in the
"upper-bound-allowance-materialized-as-reversal" sense.

The prediction band's structure (5–8) anticipated either: (a) one
fix-forward sub-step in the Phase 4/5a shape (8 round-trips total), or
(b) one architectural reversal absorbed within a sub-step (7
round-trips total), or (c) zero friction with all six sub-steps
landing in 1 round-trip apiece (6 round-trips total — the Phase 5b
shape). Outcome was (b). The prediction structure correctly bracketed
the realistic outcomes; the actual outcome landed at the
within-bracket point predicted by the operator-facing-UI-surface
concern.

### Test count growth

168 tests / 728 assertions at v0.6.0 → 201 tests / 908 assertions at
v0.7.0. Phase 6 added +33 tests / +180 assertions across:

- **6.1 export tests** (helper-level envelope shape; round-trip
  identity for items + bands; soft-deleted exclusion; sortorder gap
  preservation through whitelist projection).
- **6.3 validation tests** (15 distinct error conditions exercised;
  warnings vs errors disposition; permissive-on-unknown-fields
  forward-compat; strict-on-schema_version).
- **6.4 instantiation tests** (add_moduleinfo invocation + populate
  helper integration; sortorder gap preservation through
  template → DB; transactional rollback under populate failure
  conditions; multi-band consistency).
- **6.5b populate-existing tests** (empty-state precondition; round-trip
  via empty-create-then-populate; warnings confirmation flow surface).

phpcs zero/zero plugin-wide preserved through every sub-step,
including the docs sweep at 6.6. This is the third consecutive phase
to ship phpcs-clean (Phase 5a and 5b also held the floor); the pattern
is now durable methodology rather than incidental.

### Type B aggregate

Counting consistently with prior retrospectives — Type B items
absorbed in-gate via fix-forward action without a separate commit:

- **6.0**: 0 Type B.
- **6.1**: 1 Type B (lang cache miss surfaced at walkthrough; banked
  as insight 1).
- **6.3**: 1 multi-part Type B (FORMAT_HTML strict-int rejection in
  test fixtures; banked as insights 2/3/4).
- **6.4**: 3 Type B (Test 8 transaction-rollback dropped to lifecycle
  hook coverage; phpcs path-probe re-verification; CLI smoke
  auto-cleanup pattern; banked as insights 5/6/7).
- **6.5b**: 1 architectural Type B — the reversal itself, distinct
  from tooling Type B in nature; banked as insight 8.
- **6.6**: 0 Type B.

Total: 6 Type B across 6 sub-steps. Phase 4 absorbed 8 Type B across
8 sub-steps; Phase 5a absorbed 8 across 8; Phase 5b absorbed 8 across
6. Phase 6 absorbed 6 across 6 — at first reading this looks like a
declining count, but the architectural Type B at 6.5b is structurally
heavier than three tooling Type B added together, so the per-phase
budget remains roughly constant when weighted by impact rather than
count. The Phase 5b retrospective named "per-phase Type B budget
appears roughly fixed regardless of sub-step count, possibly bounded
by conceptual-surface count" as a tentative observation; Phase 6's
data is consistent with that framing if architectural Type B is
weighted appropriately.

---

## 2. What worked

The compound-dividend insight from Phase 4, 5a, and 5b carried forward
cleanly: a mature pattern bank from Phases 1–5b absorbed cleanly into
Phase 6's surfaces where they could, even though JSON template
work itself had genuinely new conceptual ground (envelope shape,
validation rule density, populate-existing operator workflow). Six
specific patterns carried the load.

**Pattern bank compounding from Phase 5b's serialization conventions.**
Phase 5b's backup-XML stepslib + restore-side processors had
established the canonical shape for serializing scorecard's nested
data structures. Phase 6's JSON envelope reused the same projection
discipline at a different syntactic layer: enumerate fields, cast to
native types, omit soft-deleted rows, preserve sortorder gaps as
positional opaque values. The XML stepslib at 5b.3 → JSON envelope at
6.1 transition was structurally a syntax change with the same
underlying contract. The whitelist-projection pattern (field-by-field
array_map cast, no `SELECT *` shortcuts) carried over directly. Phase
6's calibration tax for serialization shape was effectively zero — the
tax had been paid at Phase 5b.

**Pure-function helper convention extended consistently.** Established
at 6.1 (`scorecard_template_export_data`); extended to 6.3
(`scorecard_template_validate`) returning errors + warnings two-array
structure; extended to 6.4 (`scorecard_template_import` and
`scorecard_template_populate`); each helper testable in PHPUnit
without HTTP plumbing. The endpoint files (`export.php`,
`import.php`) became thin shells that call the helpers. This is
operationally the same pattern Phase 4 banked as
"compound-helper-design"; Phase 6 applied it to every new helper
introduced rather than designing for one consumer first. The
test architecture cost was substantially lower than 6.5's
originally-anticipated full-UI-simulation test approach would have
been, and the helpers are reusable — `scorecard_template_populate`
exists as a parallel path alongside `scorecard_template_import`
without code duplication, exactly because the helper boundary is
pure-function.

**Three-shape empirical verification consistently applied.** Phase 5b
retrospective Section 4 established the five-shape framework; Phase 6
operationalized three shapes (PHPUnit integration tests against real
DB; browser walkthrough; full-pipeline CLI smoke against scorecard
id=2) consistently across sub-steps. Browser walkthrough at 6.1 +
6.5b proved load-bearing — caught operator-facing regressions
code-level gates couldn't see (the 6.1 lang cache miss; the 6.5
architectural mismatch). See section 4 for the per-shape
operationalization detail.

**Pre-flight evidence catching architectural surfaces.** mod_assign
restore-side instantiation reading at 6.4 (specifically, the
`add_moduleinfo` invocation pattern with `$moduleinfo->modulename`
and `$moduleinfo->section` populated correctly) grounded the Q
disposition for instantiation method selection. The pre-flight surfaced
the `lib.php` `scorecard_add_instance` signature wrinkle (the
function takes `$scorecard, $mform = null` rather than just
`$scorecard`) — useful to know before the helper invocation; would
have surfaced as a Type B otherwise. SPEC §14 v1.1 row evidence at 6.0
strengthened Q4 disposition (whether to introduce a directive at all
versus inline reference) from "warranted" to "structurally necessary"
once the v1.1 row's "Template import/export" line was confirmed as
the load-bearing forward reference.

**Forward-only methodology through architectural reversal.** The
6.5 → 6.5b reversal landed without commit history rewriting. Original
6.5 implementation never committed (Claude held it locally pending
walkthrough); when walkthrough surfaced the architectural mismatch,
the reversal consolidated cleanly into the 6.5b commit; clean origin
state preserved through the reversal. This is the right discipline —
reversals shouldn't require commit-history rewriting; they should
consolidate forward into a single replacement commit when caught at
gate. See section 5 for the full methodology detail.

**phpcs caveat closure mid-flight.** The path-probe error at 6.1
("phpcs unavailable; carry forward and re-verify") was carried through
6.3; at 6.4 the binary was confirmed always present at
`/home/john/.composer/vendor/bin/phpcs`. Retro-lint of 6.1 + sweep of
6.3 both clean; phpcs reinstated as standard gate from 6.4 forward.
Caveat closed within Phase 6 rather than carrying forward
indefinitely. This is the canonical worked example of the banked
"re-verify carry-forward caveats" insight (insight 6 — see section 6).

The compound effect: Phase 6 added new product surface (JSON template
export/import; validation helper; populate-existing UI) but did not
introduce new conceptual surface in the methodological sense.
Templates compose from existing patterns — helper-decomposition,
serialization projection, validation discipline, operator-facing UI
shape, lang-key conventions, phpcs/lint patterns — rather than
inventing new ones. The 5–8 estimate was correctly
calibration-tax-moderate; the actual 7 reflects the absorbed pattern
bank applied honestly to a moderately-new conceptual surface.

---

## 3. Per-subsystem calibration-tax compression curves

Phase 5b retrospective Section 2 introduced per-subsystem compression
curves as the correct unit of analysis for phases with multiple
conceptual surfaces. Phase 6 operationalizes the same framing with
sharper compression curves than Phase 5b's, because Phase 5b's pattern
bank already covered serialization + lifecycle + helper-decomposition
surfaces.

### The JSON envelope shape curve

Heavy at 6.0 (directive drafting + SPEC integration; canonizing the
envelope structure including `schema_version`, nested `plugin`
producer fingerprint, ISO 8601 `exported_at`, soft-delete exclusion
semantics distinct from §9.4 backup/restore); negligible at 6.1 (the
envelope just builds the directive in PHP). One sub-step compression.

The compression here was sharper than Phase 5b's Privacy API curve
because the directive-first approach (SPEC §9.6 written before the
implementation) front-loaded the conceptual work into a
SPEC-modification-only sub-step. By the time 6.1 implemented the
export pipeline, the directive specified the envelope shape exactly;
the implementation was pure construction.

### The field whitelist projection curve

Heavy at 6.1 (first time enumerating all 18 scorecard fields + 7 item
fields + 6 band fields with native-type casts; deciding what to
include vs exclude vs omit; deciding default handling for nullable
fields); trivial at 6.3 (the validator just consumed the same field
list inversely — for each declared field, validate type and
constraints; for each undeclared field, warn but accept). One
sub-step compression.

The whitelist projection at 6.1 mirrored Phase 5b's backup XML
projection (each `set_source_table` with named field list); the
compression was effectively zero across the JSON-vs-XML syntax
boundary. This is the third consecutive phase where field-level
projection has been the load-bearing pattern: Phase 4's CSV export
projected attempts + responses; Phase 5b's privacy export and backup
XML projected the same data; Phase 6's JSON envelope projects items +
bands. The discipline of "enumerate fields explicitly, cast to native
types, no SELECT * shortcuts" is now durable methodology.

### The validation rule density curve

Heavy at 6.3 (15 distinct error conditions including missing fields,
wrong types, wrong schema_version, cross-plugin name, scale invalid,
displaystyle non-radio, format constants invalid, band range invalid;
plus per-field rule logic; plus the errors + warnings two-array return
shape distinguishing import-blocking from import-informational);
trivial at 6.4 (instantiation assumed validated input — `assert`-style
preconditions only). One sub-step compression.

The 6.3 calibration tax was paid in two layers: (1) enumerating which
conditions are errors versus warnings (15 errors, 4 warnings), and
(2) the in-gate FORMAT_HTML fix-forward when test fixtures used the
constant directly and the strict-int validator rejected it. Both were
paid in one round-trip. The validator's contract, once established,
absorbed the populate-existing path at 6.5b without needing to revisit
the rule density.

### The activity-creation lifecycle curve

Heavy at 6.4 (`$moduleinfo` construction with all required fields;
`add_moduleinfo` invocation against the destination course;
transaction wrapping for atomicity; cleanup on failure path); negligible
at 6.5b (helper composition only — the populate-existing path bypasses
activity-creation entirely, since the operator already created the
empty scorecard via standard "Add an activity"). One sub-step
compression with architectural shape change.

This curve is structurally interesting: 6.5's original architecture
would have re-paid the activity-creation tax (course-nav entry
creating a scorecard from a template via a custom controller); 6.5b's
reversed architecture reused the standard Moodle add-activity flow and
added a populate-only helper that takes an existing scorecard ID.
The architectural reversal eliminated re-paying a calibration tax that
6.4 had already paid in a different shape. See section 5 for the full
reversal archaeology.

### The operator-facing UI architecture curve

Heavy at 6.5/6.5b (form + endpoint + state machine for empty-state
detection + warnings confirmation flow for non-blocking
disposition); minimal at 6.6 (docs only — README operator-facing
sections describing the import workflow). One sub-step compression
with reversal absorbed.

The 6.5 → 6.5b reversal absorbed an effective doubling of this
sub-step's calibration tax — original 6.5 paid for course-nav hook
architecture; 6.5b paid for empty-state-conditional manage.php
affordance. Both were UI architecture taxes; only one shipped. The
reversal didn't compress the curve; it replaced the curve's payment
target. Net cost: 2 round-trips of UI architecture work for 1
sub-step's worth of shipped UI. The cost was justified by shipping
the right architecture rather than the wrong one — see section 5 for
why the reversal was the right response.

### The release artifact authoring curve

Heavy at 6.0 (CHANGES `### Spec status` block first appearance for
this plugin's release-notes structure; the v0.7.0 entry's earlier
sections drafted at 6.6 inherited the structural conventions
established at 6.0); near-trivial at 6.6 (mostly populating sections
established at 6.0 plus adding the `### Operator action` and
`### Quality gates` blocks at the same shape Phase 5b's v0.6.0 had
used). One-and-a-half sub-step compression — the structural
conventions ride forward across phases, with each release entry
applying the conventions established the first time the structure
appeared.

### The methodology insight

The compression curves trace pattern-bank-fill rate per conceptual
subsystem. Each subsystem's calibration tax compresses across
sub-steps as the bank accumulates relevant precedent. Phase 6's
curves were sharper than Phase 5b's because Phase 5b's pattern bank
already covered serialization + lifecycle + helper-decomposition
surfaces; Phase 6 paid calibration tax only on genuinely-new aspects
(JSON envelope shape; validation rule density; populate-existing
operator workflow). The aspects that re-used Phase 5b's bank
(serialization projection; helper-pure-function discipline;
empirical verification gate) paid effectively zero tax.

The forward application: phases that ride mature pattern banks should
predict per-subsystem curves with the genuinely-new surfaces paying
full tax and the absorbed surfaces paying near-zero. Phase 7+
predictions should decompose by surface, weight by pattern-bank
coverage, and aggregate to phase level.

---

## 4. Three-shape empirical-bootstrap-state-verification operationalization

Phase 5b retrospective Section 4 established the five-shape framework
for empirical-bootstrap-state-verification gate discipline; Phase 6
operationalized three shapes consistently across sub-steps. The three
shapes were inherited from Phase 5b directly (not extended with new
shapes), reflecting that Phase 6's surfaces — export, validation,
instantiation, populate, UI — fit cleanly into the existing
framework's shape categories.

Worth cataloging which shape applied where + what each caught,
because the per-shape coverage analysis is the load-bearing evidence
that the discipline genuinely catches different classes of regression.

### Shape A — PHPUnit integration tests against real DB fixture

Applied at every Phase 6 sub-step except 6.0 (no code) and 6.6
(release; 201/908 count verified post version bump). Caught:
helper-logic regressions; envelope shape mismatches; sortorder gap
preservation invariants under whitelist projection; transaction
rollback semantics in the populate path; round-trip identity
invariants (export → JSON → validate → populate → re-export
produces structurally identical envelopes modulo timestamp churn).

PHPUnit's strength here is contract-grain assertion: each test
exercises one helper or endpoint with controlled inputs and asserts
on the output structure directly. The fixture data is synthetic
(generators produce items + bands + scorecards as needed), which is
appropriate for contract-grain verification but insufficient for
real-data-shape verification — see Shape C for that complement.

### Shape B — Browser walkthrough at operator-facing UI surfaces

Applied at 6.1 (export affordance discoverability — does the operator
see the "Export template" button on the manage page? does the
download produce the expected file?) and 6.5b (full import flow —
does the empty-state suppress the affordance correctly when the
scorecard is populated? does the warnings confirmation form preserve
the JSON across the round-trip? does the success notification
display?). Lang cache purge non-negotiable before walkthrough;
Phase 6 reaffirmed this discipline at 6.1 (where the lang cache miss
surfaced precisely because the walkthrough rendered literal
`[[<key>]]` text for the new strings).

Caught: lang cache miss at 6.1; **architectural mismatch at 6.5b**
(course-nav entry creating new scorecards versus manage-empty-state
populating existing); empty-state suppression logic; warnings
confirmation form UX; sesskey CSRF discipline applied to the
confirmation surface; the `scorecard-name-slugified-template.json`
download filename rendering correctly across browsers.

The 6.5b walkthrough surfaced the architectural mismatch directly —
the operator's mental model when sitting in the manage.php empty-state
view was not "I want to create a scorecard from a template via course
nav" but rather "I just made an empty scorecard via the standard
add-activity flow; let me populate it from a template." This is the
single load-bearing piece of evidence behind the entire Phase 6
methodology insight 8 (architectural reversal at within-sub-step
grain). Section 5 expands on this.

### Shape C — Full-pipeline CLI smoke against dev DB scorecard id=2

Applied at 6.1 (export envelope round-trip), 6.3 (round-trip identity
invariant + 6 fault-injection corruptions exercising the validator's
error catalogue), 6.4 (full pipeline through populate against a
freshly-created destination course; cleanup via `delete_course`).

Caught: HTML byte-identical preservation through Notion-pasted prompts
(real `<p>` tags with embedded `<strong>` and `<em>` markup, special
characters, line breaks; the export pipeline must preserve these
verbatim because operators copy authoring content from external
documents and any reformatting destroys the operator's intent); the
sortorder gap [1, 2, 3, 4, 6] preserved through soft-deleted item
id=7's slot (the round-trip had to preserve the gap, not renumber);
producer-fingerprint version reading from `version.php` at export time
(the helper reads `$plugin->release` not `$plugin->version` for
operator-readable provenance); transaction rollback against real
schema constraints (the populate helper's transaction must wrap items
+ bands inserts as atomic; failure mid-band must roll back the items
already inserted).

Shape C's distinguishing property: realistic data shape. The dev DB
scorecard id=2 has 6 items with operator-pasted prompts containing
real HTML structure, 4 bands with messages containing real
operator-authored markup, and a soft-deleted item creating a sortorder
gap. PHPUnit fixtures are synthetic (`'Frozen snapshot label 0'` etc);
real data forces the export pipeline to handle the actual byte
sequences operators produce. This is the same methodology insight
banked at Phase 5b retrospective Section 8 item 10
("empirical-against-real-data discipline catches a class of
regression test fixtures miss"); Phase 6's HTML-preservation case is
the next worked example.

### The progression and the methodology insight

Each shape catches a different class of regression. PHPUnit catches
helper-logic regressions (the contract-grain failures); browser
walkthrough catches operator-facing UX regressions (the
mental-model-mismatch failures); CLI smoke catches integration
regressions against real production data (the
real-shape-vs-synthetic-shape failures). Missing any shape leaves a
class of regression undetected.

The 6.5 → 6.5b architectural reversal demonstrates the principle
directly. PHPUnit at the 6.5 gate was clean (199/896 — the count
before the reversal absorbed the new tests); phpcs was clean; lang
cache was purged. ALL code-level gates passed. ONLY the browser
walkthrough surfaced the architectural mismatch. If Phase 6 had
shipped with PHPUnit + phpcs only as gate discipline, the wrong
architecture would have shipped to v0.7.0 and the reversal would have
been a fix-forward sub-step at v0.7.1+ rather than an in-gate
within-sub-step correction.

This is exactly what Phase 5b retrospective Section 4's gate-discipline
framing was anticipating — different verification shapes catch
different classes of regression. Phase 6 produced the canonical worked
example of the framing: code-level gates clean, walkthrough catches
the architectural mismatch, reversal lands forward-only. The
methodology asset isn't that walkthroughs are useful (well-known); the
asset is that the three shapes are independent verification methods
each catching a different regression class, and the three together
constitute a load-bearing gate-discipline floor.

The forward application: future phases should treat the three shapes
as cumulative gate discipline — apply all three at every sub-step
where the sub-step's surface intersects the shape's coverage. Phase
6's pattern: PHPUnit at every sub-step touching helpers; walkthrough
at every sub-step touching operator-facing UI; CLI smoke at every
sub-step touching real data integration. Mismatches between
sub-step surface and shape coverage should be intentional decisions,
not omissions.

---

## 5. The 6.5 → 6.5b architectural reversal as methodology insight

This is the load-bearing section of the retrospective. Phase 6's
eighth banked methodology insight; the sharpest operationalization of
Phase 5b retrospective Section 3's three-Q1-reversal pattern at a
different rework grain.

The 6.5 → 6.5b reversal is the canonical worked example for
"operator-workflow mismatch surfaces at walkthrough; reverse the
disposition rather than ship the wrong architecture; layer the
reversal disclosure into operator-facing artifacts at appropriate
depth." It deserves the depth this section gives it because the
specific shape is novel relative to Phase 4's and Phase 5a's
fix-forward shapes, and the future-application implications differ
correspondingly.

### What surfaced

The 6.5 kickoff disposition selected option (c) — course-navigation
entry point creating new scorecards from templates — as the
operator-facing UI architecture. The reasoning the kickoff named was
plausible: "the operator's mental model when importing a template is
'I want to create a scorecard in this course'; the template IS the
scorecard creation mechanism; therefore the entry point should be
where operators initiate course-level scorecard creation, which is
the course nav." The Q29 disposition was technically defensible — the
course nav hook convention exists precisely for this kind of
course-level entry point, and the secondary nav patterns supported it
cleanly.

Implementation went smoothly through the gate. The 6.5 implementation
landed locally (uncommitted, as Claude's working state during
implementation). PHPUnit at 199/896 (the count after the new test
files for the course-nav surface but before the eventual reversal
added the populate-existing surface tests). phpcs clean plugin-wide.
Lang cache purged. The code-level gates were satisfied.

The walkthrough surfaced the operator-workflow mismatch immediately.
John's report from the walkthrough, paraphrased honestly: "I just
made an empty scorecard via the standard 'Add an activity'; this
course-nav entry doesn't match what I expected. I expected to see
the import affordance on the manage page of the scorecard I just
created — that's where I am, and that's where I'd look for it." The
operator's actual workflow was NOT "I want to create a scorecard in
this course; the template IS the scorecard creation mechanism" but
rather "I just made an empty scorecard via standard 'Add an
activity'; let me populate it from a template."

The kickoff's Q29 reasoning was plausible but not the most useful
framing of the operator's mental model. The operator's actual mental
model surfaced empirically through the walkthrough — and was
fundamentally different from the architectural assumption the kickoff
disposition had encoded.

### Why kickoff disposition was wrong

The Q29 disposition was grounded in pre-flight evidence about course
nav hook conventions and secondary nav patterns — the technical
correctness of the architecture. It was NOT grounded in operator
workflow analysis — the operational correctness of the architecture
relative to how operators actually behave. The disposition was right
about what's structurally possible in Moodle and wrong about what
operators expect.

The kickoff's reasoning ("the operator's mental model when importing
is 'I want to create a scorecard in this course'") was a plausible
hypothesis but had not been verified against operator behavior. The
Q29 text presented the hypothesis as a derived conclusion ("therefore
the entry point should be the course nav") rather than as an
unverified hypothesis ("if operators think of templates as a scorecard
creation mechanism, the course nav is the right entry point;
verification needed"). The hypothesis-as-conclusion framing made the
disposition feel decided when it should have been provisional pending
walkthrough.

This is a sharper version of Phase 5b retrospective Section 3's
three-Q1-reversal pattern. Phase 5b's kickoff defaults were
"defensive" architectural choices that pre-flight evidence revealed
the architecture precluded; Phase 6's kickoff disposition was an
operator-workflow assumption that walkthrough evidence revealed was
inverted. Both share the structural property: kickoff dispositions
encoded as decided when they should have been provisional pending the
verification shape that catches the relevant regression class.

### Why walkthrough caught it (and code-level gates couldn't)

Code-level gates verify that the code does what the code is supposed
to do. They don't verify that the code does what operators expect.
PHPUnit tests assert that `scorecard_template_import_courselevel`
returns the expected structure given a template and a courseid; phpcs
asserts that the implementation conforms to the moodle-cs sniff set;
lang cache purge ensures lang strings resolve. None of these touch
operator expectation.

The architectural mismatch was invisible to code-level verification
because the implementation was technically correct. The
`scorecard_template_import_courselevel` helper would have shipped a
working create-from-template path; the course-nav hook would have
worked; the secondary nav would have rendered; the imports would have
created scorecards correctly. The bug was that operators wouldn't have
found the affordance because they wouldn't have looked at the course
nav — they would have looked at the manage page of the empty
scorecard they just created.

This is exactly what Phase 5b retrospective Section 4's
gate-discipline framing was anticipating — different verification
shapes catch different classes of regression. The walkthrough is the
verification shape that catches operator-mental-model mismatches.
Without the walkthrough, the wrong architecture would have shipped.

### Why reversal was the right response

Architectural reversals are not free. The reversal at 6.5 → 6.5b
required: (1) discarding the uncommitted 6.5 implementation work
(course-nav hook, secondary nav surface, courselevel-create-new
helper); (2) re-implementing the operator-facing UI as a manage.php
empty-state-conditional affordance plus a new
`scorecard_template_populate` helper for the populate-existing path;
(3) adding new tests covering the reversed architecture; (4)
preserving the original `scorecard_template_import` create-new helper
as a parallel path for programmatic use cases (since the helper
itself was correct; only its operator-facing UI invocation was
wrong).

The cost was significant. But shipping the wrong architecture would
have compounded across future sub-steps. Phase 7+ work that touches
the operator-facing import surface would have inherited the
course-nav architecture; operator confusion would have produced
support load; eventually a fix-forward at v0.7.x or v0.8.0 would have
been required to correct the architecture; the fix-forward would have
been more expensive than the in-gate reversal because users would
have established mental models around the wrong architecture in the
interim.

The reversal cost was 1 additional round-trip on the conceptual 6.5
sub-step. The fix-forward cost would have been: 1 additional sub-step
in a future phase, plus operator confusion in the interim, plus
deprecation discipline for the original course-nav hook (Moodle plugins
that ship deprecated entry points must maintain them through a
deprecation cycle). The in-gate reversal was strictly cheaper than the
fix-forward alternative.

This is the same shape as Phase 5b retrospective Section 3's
three-Q1-reversal pattern revealing the maxfiles=0 architectural fact.
Phase 5b applied the pattern at within-sub-step pre-flight grain —
kickoff Q1 disposition reversed before implementation began, based on
pre-flight evidence. Phase 6 applied the same pattern at
within-sub-step rework grain — kickoff Q29 disposition reversed
mid-sub-step after implementation surfaced the mismatch via
walkthrough. Same pattern, different rework grain. The methodology is
generalizable across grains: when verification evidence reveals a
disposition was wrong, reverse the disposition rather than ship the
wrong architecture.

### How the reversal landed without commit history rewriting

Forward-only methodology: the original 6.5 implementation never
committed. Claude held the 6.5 work in local working state pending
walkthrough; when the walkthrough surfaced the mismatch, the local
state was discarded (via `git restore` against modified files, plus
deletion of the new files Claude had created for the course-nav
surface) and the 6.5b implementation began from a clean tree. The
6.5b commit landed forward as a single replacement commit (`9425845`
in the git log). The git history shows the reversal honestly without
requiring `git rebase -i` or any history-rewriting operations.

This is the right discipline. Reversals shouldn't require
commit-history rewriting; they should consolidate forward into a single
replacement commit when caught at gate. The discipline depends on the
implementation work being held locally until gate verification
completes — committing aggressively before walkthrough would have
produced a 6.5-commit-then-6.5b-revert-commit shape that is messier
and harder to read in `git log` than the single 6.5b consolidated
commit. Phase 6 reaffirms the discipline of "complete gate
verification before commit" as load-bearing for forward-only reversal
shape.

### What changed (architectural delta)

The 6.5 → 6.5b reversal made six concrete architectural changes:

1. **Course nav hook removed from `lib.php`**. The original 6.5
   implementation added a `scorecard_extend_navigation_course` hook;
   the 6.5b implementation has no such hook. The course nav surface is
   untouched by Phase 6.
2. **Endpoint shifted from courseid-based create-new to cmid-based
   populate-existing**. The original `import.php` took
   `?courseid=<id>` and created a new scorecard; the 6.5b
   `import.php` takes `?id=<cmid>` (the course module id of an
   existing empty scorecard) and populates it.
3. **Section selector dropped from the import form**. The original
   form let the operator choose which course section to create the
   new scorecard in; the 6.5b form has no section selector because
   the scorecard already exists in its section per the standard
   add-activity flow. Operators move post-import via the standard
   Moodle course editor.
4. **Capability shifted from `mod/scorecard:addinstance` at course
   context to `mod/scorecard:manage` at module context**. The original
   capability was correct for create-new (operator must be able to add
   activities to the course); the populate-existing capability is
   correct for populate-existing (operator must be able to manage the
   already-created scorecard). The semantic shift matches the workflow
   shift exactly.
5. **New `scorecard_template_populate()` helper introduced alongside
   the original `scorecard_template_import()` helper**. Both helpers
   coexist; both are pure-function and PHPUnit-testable. The
   create-new helper remains available for programmatic use cases
   (e.g., a future bulk-create script); the populate-existing helper
   is the operator-facing import path.
6. **manage.php affordance gates on empty-state**. The "Import
   template" affordance appears on the manage page of a scorecard
   only when the scorecard has zero items AND zero bands; once
   populated, the affordance disappears. This empty-state precondition
   is the architectural marker that the import is a populate-existing
   operation, not a replace-existing operation (overwrite/append modes
   are deferred to v0.8+ per SPEC §9.6 v0.7.0 single-version-only
   semantics).

The architectural delta is large in scope but small in code volume —
the reversal didn't introduce a substantial new codebase, it shifted
which Moodle conventions the existing codebase composed against.

### Why the disclosure layered into operator-facing artifacts matters

This is the methodology insight beyond the reversal itself: the
reversal disclosure layered into operator-facing artifacts at
appropriate depth. Three artifact layers carry the reversal honestly:

1. **CHANGES.md `### Quality gates` subsection** documents the
   reversal as in-gate course-correction: "Architectural reversal at
   sub-step 6.5 → 6.5b documented as in-gate course-correction.
   Original 6.5 implementation (course-nav entry creating new
   scorecards) reversed to manage.php empty-state populate-existing
   model after walkthrough surfaced operator-workflow mismatch with
   the kickoff disposition. Reversal was forward-only (no commit
   history rewriting); 6.5 was never committed." This is the
   operator-facing depth — release notes consumers understand what
   shipped and what was reversed, in language meaningful to operators.
2. **Tag body short-pointer** names the reversal explicitly. The
   `v0.7.0` annotated tag body includes a one-line pointer to the
   reversal as part of the v0.7.0 summary; future operators
   inspecting the tag history see the reversal honestly.
3. **README operator-facing import section** implicitly carries the
   architectural model the documentation describes. The README's
   import workflow narrative ("create a scorecard via standard 'Add an
   activity' → land on manage.php → click 'Import template'")
   describes the populate-existing model directly. Operators reading
   the README absorb the architecture as the documentation
   describes; they don't need to know about the reversal to use the
   plugin correctly.

The disclosure depth is graduated: CHANGES.md is the most explicit
(release-notes-grain operator information); tag body is medium
(annotated-tag-grain summary information); README is implicit (the
architecture model is just the documented architecture model). This
graduated approach honors the operator's bandwidth — most operators
need the README's implicit architecture only; some operators (those
investigating release notes for upgrade decisions) need the CHANGES.md
explicit; few operators (those investigating git tag history for
incident archaeology) need the tag body explicit. The disclosure
appears at each layer at the depth appropriate to that layer's
audience.

This is sharper than Phase 5b retrospective Section 8's
"documentation-debt + metadata-completeness fix bundling" pattern.
Phase 5b's pattern was about consolidating multiple documentation gaps
into a single sub-step's docs delivery; Phase 6's pattern is about
layering a methodology reversal disclosure across multiple
operator-facing artifact depths. Both are about appropriate operator
disclosure; the depths and granularities differ.

### Methodology insight banked (#8 of 8 from Phase 6)

The insight as banked: **"When implementation surfaces operator-workflow
mismatch with kickoff disposition, reverse the disposition rather than
ship the wrong architecture; layer the reversal disclosure into
operator-facing artifacts (CHANGES, tag body, README) at appropriate
depth."**

Why it matters: code-level gates don't catch operator-workflow
mismatches; walkthrough does. When walkthrough surfaces a mismatch,
the cheapest correction is in-gate reversal (rather than fix-forward
in a subsequent sub-step or phase). The reversal must be honest in
artifact disclosure — operators consuming release notes, tag history,
or README content should encounter the architecture the plugin
actually ships, with the depth of disclosure matched to the artifact's
audience.

Future application: any phase introducing operator-facing UI where
walkthrough is a load-bearing gate. Specifically, any phase where the
kickoff disposition encodes an operator-workflow assumption that
hasn't been verified against operator behavior. The reversal pattern
is most likely to apply where the kickoff Q text presents an
operator-mental-model hypothesis as a derived conclusion rather than
as a verification target. Phase 7+ kickoffs introducing
operator-facing surfaces should explicitly call out which Q
dispositions are operator-workflow hypotheses pending walkthrough
verification, distinct from architectural-fact dispositions verifiable
at pre-flight.

---

## 6. Banked methodology insights catalog

Eight insights from Phase 6, each catalogued as a single
incident-fix-applicability paragraph the reader can apply without
consulting the underlying memory file. Pointer-quality density —
future readers reaching for "what was insight #5 again?" want
orientation, not full re-derivation.

### Insight 1 — Lang cache purge after string addition

**Surfaced at**: 6.1 walkthrough — browser rendered literal `[[<key>]]`
text instead of the new lang strings; PHPUnit and phpcs were both
clean; the lang strings existed in `lang/en/scorecard.php`; the cache
held an outdated snapshot. PHPUnit doesn't exercise lang strings the
same way operator-facing rendering does — test environments bypass
the cache invalidation path that operator-facing rendering relies on.
Walkthrough catches what tests miss because walkthrough is the only
verification shape that exercises the actual cache path. **Future
application**: any sub-step adding lang strings should treat cache
purge as walkthrough preamble; current Phase 7+ discipline is
manual-step documentation in walkthrough plans.

### Insight 2 — FORMAT_* constants are string-typed in Moodle source

**Surfaced at**: 6.3 in-gate FORMAT_HTML fix-forward — the strict-int
validator for `*format` columns rejected `FORMAT_HTML` directly when
test fixtures used the constant in test data setup. Moodle's
`lib/weblib.php` defines FORMAT_* as strings (`'1'`, `'0'`, etc),
not integers; most consumer code coerces via PHP loose comparison;
strict-int validation rejects the string forms. Fix is at the
fixture site, not at the validator: cast `(int)FORMAT_HTML` before
feeding strict-int validation. **Future application**: any test
fixture using Moodle FORMAT constants in contexts requiring
strict-int. The cast-at-fixture-site discipline preserves the
validator's correctness; this is also the load-bearing example for
insight 3.

### Insight 3 — Course-correct fixtures before loosening validator

**Surfaced at**: 6.3 in-gate, same incident as insight 2. When the
strict-int validator rejected FORMAT_HTML from test fixtures, the
available responses were (a) cast at the fixture site, (b) loosen
the validator, or (c) introduce a coercion shim. The first is
correct; the latter two weaken the validator. Production templates
will never contain string-typed format values because the producer
side casts during export; only test fixtures reach the validator
with string-typed values, so the test fixtures are wrong and the
validator is correct. **Future application**: any plugin work with
strict validation patterns. When a strict validator rejects
unexpected input, ask "does production produce this input shape?" —
if no, the rejection is correct and the test/upstream caller is
wrong. Loosening validators is hard to walk back; once accepted,
future code may depend on the wider input shape.

### Insight 4 — Validation vs instantiation are different operation categories

**Surfaced at**: 6.3 commit exchange. Validation is decision-shaped —
collect failures across the entire input and return them as a
structured list so the caller can decide (block, warn, prompt
confirmation). Instantiation is action-shaped — commit the action or
throw with a specific failure reason; no "list of all failures"
because the action stops at first failure. The 6.3 helper returns a
two-array `['errors' => [...], 'warnings' => [...]]` because
validation's contract is "tell the caller everything wrong"; the 6.4
helper throws on failure because instantiation's contract is "do
this or explain why you couldn't." **Future application**: any
plugin work with validate-then-act helper pairs. Design the
validator with decision-shape; design the action with action-shape.
The 6.5b warnings confirmation flow is the worked example —
validator returns warnings; UI presents them; operator decides; on
confirmation the actor is called. The contract boundary is explicit.

### Insight 5 — Test-env-specific trigger → more reliable trigger

**Surfaced at**: 6.4 Test 8 drop. The planned test was a
transaction-rollback verification triggered by deliberately
violating a band-range constraint mid-insert; the trigger required
MySQL strict mode active in the test environment, which was
unreliable across runs. Test architecture should validate behavior,
not validate Moodle's transaction primitive (already validated by
Moodle core's tests). Course-correction was to drop Test 8 and rely
on lifecycle-hook coverage (the populate helper's transaction wrap
is exercised by every test that runs the populate path; failure
modes are exercised by the end-to-end smoke against real DB).
**Future application**: any test design encountering test-env
brittleness. When a planned test requires test-env-specific behavior
to trigger reliably, course-correct toward a more reliable trigger
covering the same conceptual surface. Conceptual-surface coverage is
the goal; specific test design is the means.

### Insight 6 — Re-verify carry-forward caveats

**Surfaced at**: 6.4 phpcs path-probe correction. 6.1's pre-flight
had reported phpcs unavailable ("path probe failed; carry forward");
the caveat carried through 6.3, suppressing phpcs as a gate. At 6.4
deliberate re-verification confirmed the binary always present at
`/home/john/.composer/vendor/bin/phpcs` — original probe used the
wrong candidate. Retro-lint of 6.1 + 6.3 both clean; phpcs reinstated
as standard gate from 6.4 forward. Carry-forward caveats that aren't
re-verified at intervals can suppress gate discipline silently
indefinitely. **Future application**: any phase with carry-forward
caveats. Re-verify at fixed intervals (every 2-3 sub-steps) or at
any sub-step where the caveat's constraint intersects new work,
whichever comes sooner. Cost is small (one targeted probe); cost of
incorrect caveat suppression is potentially large.

### Insight 7 — CLI smoke scripts auto-cleanup via standard Moodle helpers

**Surfaced at**: 6.4 `delete_course()` pattern. 6.4's CLI smoke
script created a destination course, populated it, asserted on
populated state, and needed to clean up — leaving the course in dev
DB would produce accumulated test data interfering with future smoke
runs. The cleanup uses `delete_course($newcourse->id, false)` (the
`false` suppresses the redirect inappropriate for CLI context).
**Future application**: any future smoke script that creates
entities for verification. Pattern: create → exercise → assert →
cleanup, all within a single script invocation. Use Moodle's
standard helpers; don't write custom cleanup that bypasses them (DB
DELETE statements skip event/lifecycle hooks Moodle relies on for
referential integrity). Operationally useful for repeatable dev
runs.

### Insight 8 — Operator-workflow-mismatch reversal at within-sub-step grain

**Surfaced at**: 6.5 → 6.5b architectural reversal (Section 5 covers
in depth). Code-level gates don't catch operator-workflow
mismatches; walkthrough does. The 6.5 implementation passed PHPUnit,
phpcs, and lang cache purge; only the walkthrough surfaced the
architectural mismatch (course-nav entry creating new scorecards vs
manage.php empty-state populating existing). When walkthrough
surfaces a mismatch, in-gate reversal is cheaper than fix-forward at
a subsequent phase. The reversal must be honest in artifact
disclosure — release notes, tag history, README content should match
the architecture the plugin actually ships, with disclosure depth
matched to artifact audience. This insight operationalizes Phase 5b
retrospective Section 3's three-Q1-reversal pattern at a different
rework grain (Phase 5b at within-sub-step pre-flight; Phase 6 at
within-sub-step rework). Same pattern; methodology generalizable
across grains. **Future application**: any phase introducing
operator-facing UI where walkthrough is load-bearing gate. Phase 7+
kickoffs should explicitly distinguish architectural-fact
dispositions (verifiable at pre-flight) from operator-workflow
dispositions (verifiable only at walkthrough; must be framed as
hypotheses pending confirmation, not as derived conclusions).

### Aggregate observation across the eight

The eight insights span three categories: tooling discipline
(insights 1, 6, 7 — lang cache, caveat re-verification, CLI
cleanup); API design discipline (insights 2, 3, 4 — FORMAT_*
typing, fixture-vs-validator correction, validation vs instantiation
shapes); methodology discipline (insights 5, 8 — test-env triggers,
operator-workflow reversal). Roughly 3 + 3 + 2 across categories.
Phase 5b's bank was heavier on methodology grain; Phase 4's heavier
on tooling. Grain distribution per phase reflects the conceptual
surfaces in scope; Phase 6's three-grain balance reflects that JSON
template work touched operational, API, and methodology surfaces
roughly equally.

---

## 7. What we'd do differently

Calibration-honesty surface. Worth naming honestly — not as
self-criticism but as forward methodology refinement. The retrospective
is the load-bearing reference for "what the kickoff would do
differently next time, knowing what the gate verification surfaced."

### Q29 grounding at kickoff drafting

The Q29 disposition at sub-step 6.5 kickoff was technically defensible
but operationally wrong. The kickoff drafting reasoning ("operator's
mental model when importing is 'I want to create a scorecard in this
course'") was plausible but not the most useful framing.

Better disposition reasoning would have grounded Q29 in operator
workflow analysis from the start, not just architectural cleanliness
analysis. The kickoff Q text presented the operator-mental-model
hypothesis as a derived conclusion ("therefore the entry point should
be the course nav") rather than as an unverified hypothesis ("if
operators think of templates as a scorecard creation mechanism, the
course nav is the right entry point; verification needed").

The hypothesis-as-conclusion framing made the disposition feel
decided when it should have been provisional pending walkthrough.

**Forward refinement**: future kickoffs introducing new operator-facing
surfaces should explicitly frame Q dispositions through "what does the
operator's workflow actually look like" first, not just architectural
cleanliness analysis. The Q text should distinguish:

- **Architectural-fact dispositions**: verifiable at pre-flight; may
  default defensively pending verification.
- **Operator-workflow dispositions**: verifiable only at walkthrough;
  must be framed as hypotheses pending walkthrough confirmation, not
  as derived conclusions.

The distinction matters because the verification shape and rework
cost differ. Architectural-fact dispositions can reverse at pre-flight
(cheap); operator-workflow dispositions reverse only at walkthrough
(expensive — requires implementation work to be discardable). Phase
6's 6.5 → 6.5b reversal cost was the difference between these two
verification shapes when the disposition shape was misclassified.

### phpcs path probe at 6.1

The pre-flight at 6.1 reported phpcs unavailable; the binary was
always present at `/home/john/.composer/vendor/bin/phpcs`. Caveat
carried forward through 6.3 and most of 6.4 before re-verification
corrected it.

The original probe used the wrong path candidate. The correct path
was discoverable via `which phpcs` or via `composer global show -i`;
the original probe didn't try either. The caveat suppressed phpcs as
a gate at 6.1 and 6.3 — both retro-linted clean once phpcs was
reinstated, but the gate-discipline weakness during those sub-steps
is real.

**Forward refinement**: future pre-flights should verify path
assumptions explicitly. Specifically, probe multiple plausible paths
before reporting "unavailable":

- `which <binary>` (PATH-based)
- `composer global show -i | grep <binary>` (Composer-global-installed)
- `vendor/bin/<binary>` (Composer-local)
- `~/.composer/vendor/bin/<binary>` (Composer-global-bin)

Carry-forward caveats should also get re-verified at fixed intervals
(every 2-3 sub-steps) rather than only when something else surfaces
the question. The discipline: any caveat carried at a sub-step gate
gets a re-verification audit at the next +2-or-+3 sub-step gate, even
if no incident has surfaced.

This refinement is operationally lightweight (one targeted probe per
caveat per audit interval) and high-value (suppressed gate discipline
is the worst-case outcome of unverified caveats).

### Sub-step 6.5b walkthrough scope

The 6.5b walkthrough validated 8 flows across the architectural
reversal:

1. Empty-state suppression on populated scorecards.
2. Empty-state affordance visibility on empty scorecards.
3. Capability gating on `mod/scorecard:manage`.
4. JSON file upload + parse.
5. Validator error path (block import, show errors, no DB write).
6. Validator warnings path (prompt confirmation, preserve JSON across
   the round-trip, sesskey CSRF).
7. Successful populate (DB rows inserted; redirect to manage.php with
   notification).
8. End-to-end via empty-create-then-populate (scorecard freshly
   created via standard add-activity → land on manage → import →
   populated state correct).

Worth recognizing that 8 flows is heavy for a single walkthrough.
Future operator-facing UI sub-steps could potentially split walkthrough
into "smoke" (3-4 critical flows) + "comprehensive" (full coverage)
phases. The 8-flow walkthrough caught everything cleanly at 6.5b, but
the walkthrough duration was substantial — and the walkthrough is
operator-time-bounded (John is the only operator running it; 8 flows
take real time).

**Forward refinement**: not a regret per se — 8 flows caught
everything cleanly — but worth noting as a scaling consideration if
future operator-facing UI surfaces are heavier. Phase 7+ kickoffs
introducing operator-facing UI with anticipated walkthrough scope >5
flows should consider explicit "smoke" + "comprehensive" walkthrough
phasing. The smoke pass catches gross failures (architecture mismatch,
critical UX blockers) early; the comprehensive pass validates edge
cases and confirms the critical-path coverage at gate close. This
splits walkthrough cost across two operator sessions rather than
demanding one extended session.

### Round-trip prediction range at 6.5 vs 6.5b

The original 6.5 prediction was 1-2 honest, 3 upper-bound. The
architectural reversal pushed effective cost to 1 honest + 1 honest =
2 round-trips on the conceptual sub-step. Prediction range
accommodated it (3 upper-bound), but worth recognizing that
operator-facing UI sub-steps may benefit from wider prediction ranges
to absorb potential architectural reversals.

Phase 6's prediction of 5–8 phase-total accommodated the reversal
cleanly (actual 7 within range). But the per-sub-step prediction at
the 6.5 kickoff stage was tight — 1-2 honest with 3 upper-bound left
only 1 round-trip of allowance for reversal scenarios. If the
reversal had been heavier (e.g., requiring a follow-up sub-step to
adjust adjacent infrastructure), the per-sub-step prediction would
have been blown.

**Forward refinement**: Phase 7+ kickoffs introducing operator-facing
UI should consider explicit "reversal allowance" in round-trip
prediction. The discipline: if the sub-step's conceptual surface is
operator-facing UI AND walkthrough is the load-bearing gate AND the
kickoff disposition includes any operator-workflow hypothesis, widen
the prediction range to accommodate at least 1 reversal allowance.
The widening is cheap at prediction time (just a wider range) and
expensive to omit at gate time (a reversal that blows the prediction
range produces operator-facing calibration-honesty surface that's
better avoided).

This refinement is tightest for phases where multiple operator-facing
UI sub-steps stack — each sub-step's reversal allowance compounds
into the phase-total prediction. Phase 6 had only one
operator-facing UI sub-step (6.5/6.5b); a hypothetical Phase 7 with
three operator-facing UI sub-steps would benefit from
phase-total reversal allowance widening proportionally.

---

## 8. Closing + handoff to Phase 7+

v0.7.0 ships JSON template export and import. mod_scorecard at this
release has 201 PHPUnit tests / 908 assertions; phpcs clean
plugin-wide; MATURITY_ALPHA preserved (BETA bump deferred to a
deliberate release decision based on production-usage signal); 8
banked methodology insights catalogued in Section 6; round-trip
identity contract empirically verified end-to-end across export →
validate → populate against real dev-DB data (scorecard id=2 with 6
items including operator-pasted HTML, 4 bands, sortorder gap from
soft-deleted item id=7).

The headline trajectory number — 7 round-trips against 5–8 predicted —
is real but should not be the headline lesson. The headline lessons
are:

1. **Code-level gates don't catch operator-workflow mismatches;
   walkthrough does.** The 6.5 → 6.5b reversal is the canonical worked
   example. PHPUnit + phpcs + lang cache purge were all clean at the
   6.5 gate; only the walkthrough surfaced the architectural mismatch.
   Future phases introducing operator-facing UI must treat walkthrough
   as load-bearing gate discipline, not optional verification.

2. **In-gate reversal is cheaper than fix-forward at subsequent
   phases.** The reversal at 6.5 → 6.5b cost 1 additional round-trip
   on the conceptual sub-step; the alternative fix-forward at v0.7.x
   or v0.8.0 would have cost an additional sub-step plus operator
   confusion in the interim plus deprecation discipline for the
   original architecture. Reversals are not free, but shipping the
   wrong architecture is more expensive.

3. **Carry-forward caveats demand periodic re-verification.** The
   phpcs path-probe caveat at 6.1 was incorrect; it suppressed gate
   discipline at 6.1 and 6.3 before re-verification at 6.4 corrected
   it. Caveats that carry indefinitely without re-verification
   weaken gate discipline silently.

4. **The three-shape empirical verification framework operates
   independently across grains.** PHPUnit catches helper-logic
   regressions; walkthrough catches operator-facing UX regressions;
   CLI smoke catches integration regressions against real production
   data. Missing any shape leaves a class of regression undetected.
   Phase 6's data is the canonical worked example.

5. **Per-subsystem calibration-tax compression curves accelerate as
   the pattern bank matures.** Phase 6's compression curves were
   sharper than Phase 5b's because Phase 5b's pattern bank already
   covered serialization + lifecycle + helper-decomposition
   surfaces. Future phases riding mature pattern banks should predict
   per-subsystem with the genuinely-new surfaces paying full tax and
   the absorbed surfaces paying near-zero.

None of these are Phase 6-specific. All of them apply to Phase 7+
kickoff drafting immediately.

### Pattern bank and cumulative state

Phase 7 inherits the full Phase 1–6 pattern bank — JSON template work
specifically informs portable artifact distribution surfaces; the
architectural reversal pattern (Section 5) informs any phase
introducing operator-facing UI where walkthrough-time reversal is a
real possibility. The eight banked methodology insights catalogued in
Section 6 (3 tooling + 3 API design + 2 methodology) are direct
precedent for future phase work surfacing similar issues.

Cumulative state at end of Phase 6: six phases shipped (Phase 1
skeleton v0.1.0 → Phase 2 authoring v0.2.0 → Phase 3 learner
submission v0.3.0 → Phase 4 reporting v0.4.0 → Phase 5a gradebook +
completion v0.5.0 → Phase 5b privacy + backup/restore v0.6.0 → Phase
6 JSON templates v0.7.0); four retrospectives shipped (Phase 4, 5a,
5b, and now 6); seven annotated tags v0.1.0–v0.7.0 on origin; roughly
50 cumulative round-trips across all phases (Phase 4 at 8, 5a at 8,
5b at 6, 6 at 7, plus earlier phases at ~20–22 estimated). The
calibration anchor for first-time-activity-mod-with-templates is now
established; future activity-mod work has Phase 6's archaeology as
direct precedent for portable artifact distribution surfaces.

The lms-light-docs `MOODLE-ACTIVITY-MOD-PHASES.md` template explicitly
notes "Phases beyond 5b are plugin-specific feature work; no general
template applies." Phase 6 is the first Phase-beyond-5b for
mod_scorecard. The methodology archive at the four retrospectives +
the banked memory files + the in-progress lms-light-docs
METHODOLOGY.md synthesis is the load-bearing reference for future
activity-mod work venturing into Phase-beyond-5b territory; Phase 6's
specific archaeology may inform but does not prescribe template shape.
Patterns are portable; phase boundaries are not.

Phase 6 closed at v0.7.0. Pattern bank carries forward.
