# Phase 4 retrospective — mod_scorecard

Internal archaeological record of how Phase 4 (the manager-facing reports
surface) actually unfolded. Written immediately after v0.4.0 shipped
(2026-04-27) so the lessons are captured while still warm. Not a release
note, not a changelog, not customer-facing — this is for future Claude
Code sessions reading project history (and for future John re-orienting
on the codebase) to understand the methodology that worked, the
discoveries the phase made, and the calibrations to carry into Phase 5.

CHANGES.md says what shipped. This document says how it shipped, why
the shape worked, and what would still surprise us if we tried to
extrapolate from Phase 4 to Phase 5 without thinking.

---

## 1. Trajectory data

Phase 4 kickoff predicted **21–28 round-trips** for the report-page
surface, framed cautiously because reports were genuinely new product
territory after Phases 1–3 (skeleton, authoring, learner submission).
The estimate accounted for new helpers, capability-gating UI work,
group-mode integration, CSV streaming, and pagination — five
substantive sub-step surfaces, each with potential for the kind of
back-and-forth that calibration phases tend to attract.

Phase 4 closed at **8 round-trips total**: 7 forward-progress
sub-steps plus 1 fix-forward. Roughly one-third of the cautious
estimate.

### Per-sub-step prediction vs actual

| Sub-step | Commit | Prediction | Actual | Notes |
|----------|--------|-----------:|-------:|-------|
| 4.1 — report data layer + page scaffold | `9e9e116` | 4–5 | 1 | First Phase-4 sub-step, calibration-phase pace expected |
| 4.2 — expandable per-attempt detail block | `1ced09e` | 2–3 | 1 | Helper introduced (`scorecard_get_attempt_responses`) |
| 4.3 — group filter integration | `fd93b5d` | 1–2 | 1 | Pattern bank already absorbed |
| 4.4 — CSV export | `d4bf8e8` | 3–4 (orig) / 1–2 (cal.) | 1 | Recalibrated downward after 4.3; held |
| 4.5 — pagination via flexible_table subclass | `a8ce3d2` | 1–2 | 1 | Architectural shift, still 1 round-trip |
| 4.6 — polish (scope-prefix CSS) | `9877662` | 1 | 1 | Sub-Q reframed scope from kickoff |
| 4.6.5 — Fix: limitedwidth body class | `5701621` | n/a (fix-forward) | 1 | Phase-1 reflex regression discovered at 4.6 close |
| 4.7 — docs and v0.4.0 release | `96dc281` | 1 | 1 | Sub-Qs reframed CHANGES.md voice and upgrade.txt scope |

### Cumulative growth

```
After 4.1: 1 round-trip
After 4.2: 2
After 4.3: 3
After 4.4: 4
After 4.5: 5
After 4.6: 6
After 4.6.5: 7  (fix-forward inserted between 4.6 and 4.7)
After 4.7: 8 — Phase 4 closed at v0.4.0
```

The trajectory was linear-at-1-per-sub-step from 4.1 through 4.7. The
4.6.5 fix-forward was the only "extra" round-trip, and it was not a
Phase-4-introduced bug — it was a Phase-1 reflex regression (missing
`$PAGE->add_body_class('limitedwidth')`) that surfaced only at Phase 4
close when accumulated chrome made the percept threshold low enough
for side-by-side comparison to reveal it. See section 3 for that story.

The v0.3.0 → v0.4.0 commit range contains 10 commits total: the 8 Phase
4 commits above plus 3 pre-Phase-4 fix-forwards (`946d09b` SPEC §9.1
cap matrix, `f3e2928` manager empty-state link, `3d6e5f9` persistent
manage affordance) that landed after v0.3.0 was tagged but before
Phase 4.1 began. Those fix-forwards ship with v0.4.0 but didn't consume
Phase 4 round-trips.

---

## 2. What made the trajectory possible

The compound-dividend insight: a mature pattern bank from Phases 1–3
absorbed cleanly into Phase 4's surfaces, so each sub-step paid no
calibration tax — only the raw scope tax of writing the new code. Five
specific patterns carried the load.

**Helper decomposition reuse.** `scorecard_get_attempt_responses` was
introduced in 4.2 (`1ced09e`) for the per-attempt detail render. Its
data-structure shape — a map of `attemptid → response rows joined to
items via LEFT JOIN, preserving live prompt text on soft-deleted item
rows — turned out to be the right shape for three subsequent sub-steps
without modification: 4.3's group-filtered table render, 4.4's CSV
export item-set derivation (pure-PHP iteration, no second SQL query),
and 4.5's pagination per-page response fetch (subclass calls the
helper inside `query_db()`). One helper, four use sites, designed once.
Section 5 expands on this as a methodology insight.

**Capability-graduation discipline.** SPEC §9.1's three-cap design
(`mod/scorecard:view`, `:viewreports`, `:export`) had been latent
documentation through Phases 1–3 — the caps existed in `db/access.php`
from v0.1.0 but no UI surface differentiated them. Phase 4 was the
first sub-step where the differentiation paid off:
- 4.1 gates the entire reports tab on `:viewreports`.
- 4.4 gates the Export CSV button on `:export`, separate from
  `:viewreports`, so audit roles can view-but-not-download.
- The Phase 1 fix-forward (`946d09b`) also corrected an `editingteacher`
  archetype omission on `:view` discovered during 4.1 walkthrough, with
  a `db/upgrade.php` savepoint at `2026042602` to propagate to existing
  deployments. The capability matrix was finally exercised end-to-end
  during Phase 4.

**Snapshot-rule consistency.** SPEC §11.2's snapshot-only-reads
convention — band labels and messages frozen at submit time on attempt
rows — was already established and tested in Phase 3's result page.
Phase 4's report attempt-table column extraction and CSV export both
read the same snapshotted columns (`bandlabelsnapshot`, etc.) without
re-deciding the rule. The audit-honest contract from Phase 3 carried
forward to Phase 4 reports without friction.

**phpcs convention absorption.** Phase 4.3 (`fd93b5d`) was the first
sub-step that produced zero phpcs nits on first pass. By 4.4 the
comment-style convention (descriptive sentence opener, lowercase
non-comment continuation, `@param`/`@return` discipline on every
method) was fully internalized. 4.5 introduced new patterns
(flexible_table subclass with `col_*` formatter methods) that initially
tripped a phpcs convention — descriptive docblock sentences on
one-line methods — but that was a write-time-discipline reflex gap,
not a churn cycle.

**Lang-key conventions.** 23 `report:*` keys were added across Phase
4's six sub-steps. All landed alphabetically within the file and
within the `report:` namespace; punctuation conventions (period
on full sentences, no period on column-label fragments, parenthetical
fragments unterminated) were consistent throughout. Phase 4.6's
explicitly-budgeted lang-string sweep audit found nothing to fix —
write-time discipline had held across five sub-step contributors. This
became its own banked methodology insight (see section 8).

The compound effect: Phase 4 added new product surface but did not
introduce new conceptual surface. Reports compose from existing
patterns — capability gates, helper-decomposition, snapshot reads,
phpcs/lang conventions — rather than inventing new ones. The cautious
21–28 estimate had silently assumed calibration tax that the absorbed
pattern bank already eliminated.

---

## 3. The 4.6.5 layout regression late-discovery pattern

**The bug.** All four scorecard top-level pages (`view.php`,
`report.php`, `manage.php`, `submit.php`) omitted
`$PAGE->add_body_class('limitedwidth')` after `$PAGE->set_context()`.
This is the body class Boost (and Moodle's standard themes derived
from it) uses as the layout selector that constrains the main content
area to a centered column with proper margins. Without it, content
fills 100% of the viewport, alerts and forms span edge-to-edge, and
the activityheader region (Course Menu tab) lays out in a different
flow.

**The convention.** Grep of `/var/www/html/moodle/public/mod/*/view.php`
finds `add_body_class('limitedwidth')` on book, choice, data, feedback,
folder, lesson, page, quiz, url, wiki, plus mubook (MuTMS) and
videoflow — 12 of 12 surveyed core mod plugins use this single line.
There is no inverse convention. mod_scorecard simply missed the reflex
at Phase 1 PAGE-setup time, and the omission propagated forward to
every subsequent top-level page added in Phases 2 (manage.php), 3
(submit.php), and 4 (report.php).

**How it surfaced.** During the Phase 4.6 close, the user (John)
opened scorecard's view.php in one browser tab and mod_quiz's
view.php in a parallel tab and noticed the visual mismatch directly:
content cards bleeding edge-to-edge, Previous-attempt callout spanning
the viewport, Course Menu tab in a noticeably different vertical
position. Screenshots were uploaded; root-cause diagnosis took one
diagnostic round-trip (read the four scorecard top-level pages, grep
core for the convention, confirm the missing reflex), and the fix
landed in 4.6.5 (`5701621`) as a four-line change (one
`add_body_class` call per page, all placed identically after
`set_context`).

**Why it didn't surface earlier.** Three points worth naming.

First, **the missing-state still works structurally**. Pages render,
content is readable, alerts function correctly, forms POST cleanly,
PHPUnit passes (it asserts on rendered HTML strings, not on body
classes). There is no functional defect. The defect is purely visual
relative to a convention.

Second, **manage.php** had been in production since Phase 2 (`v0.2.0`,
2026-04-26) and the user had used it heavily for authoring during the
Phase 3 build. Yet the missing constraint never read as wrong on
manage.php. Hypothesis: manage.php's content is dominated by
`.item-row .d-flex .border-bottom` rows that visually look like
bordered list-cards regardless of viewport width. The cards' visible
edges read as deliberate; the missing column constraint hid behind
them. The percept threshold for noticing the bug was higher than
manage.php's content provided.

Third, **Phase 4 cumulatively raised the percept threshold**.
Phase 4.2's `<details>` summary blocks added expandable chrome.
4.3's group selector added another row. 4.4's Export CSV button
added a third row. 4.5's flexible_table chrome added pagination
controls above and below. The user-facing surface of report.php
became dense with Bootstrap-styled chrome elements, each of which
naturally wants to live in a centered column. By the end of 4.6, the
mismatch against mod_quiz was unmistakable. The bug had been there
since Phase 1; the visibility threshold finally crossed.

**The methodology lesson.** Structural-but-not-functional bugs have
low percept-and-test thresholds. They survive PHPUnit suites because
the markup is correct. They survive in-phase walkthroughs because
the page "works" — operators can complete the workflow. They surface
only when accumulated chrome makes the visual mismatch obvious or
when explicit comparison-against-convention-reference is performed.

The reflex banked: at release-readiness time, do explicit side-by-side
viewport comparison of plugin pages against parallel core-activity
pages. mod_quiz for activity modules; mod_assign for content-heavy
authoring; format_topics for course formats. Look specifically at
content-area alignment, alert/notification width, header/footer flow,
and tab/breadcrumb position. The check is fast (5 minutes per page);
the bugs it catches are otherwise invisible to test infrastructure.

This insight is durable independent of `limitedwidth` — that's the
specific reflex this regression exposed, but the generic pattern
("in-phase walkthroughs scoped to the change miss cross-cutting
structural bugs that propagated from earlier phases") will resurface
in Phase 5+ whenever a Phase-1 reflex turns out to have been wrong.

---

## 4. Kickoff-drafting drift pattern

By Phase 4.6, a recognizable pattern had emerged: kickoff prompts
were extrapolating from per-sub-step delta memory instead of reading
actual files. This is structurally inevitable as phases accumulate —
the kickoff-author (typically John, sometimes informed by prior
session memory) holds an aging mental model of the file state, and
the gap between memory and reality compounds with each sub-step that
edits the same file.

Three specific instances during Phase 4:

**4.5 pre-flag #1 — pagination-page conflation.** The kickoff
referred to "page-level fetch" without disambiguating "browser page
level (full attempts list)" from "pagination page level (the 25-row
slice the user is viewing)." Claude Code surfaced this as a sub-Q
during Q1 disposition, asking explicitly which level the kickoff
intended for response fetching. The user confirmed pagination-page
level (per-page fetch in `query_db()`), which became the
load-bearing 4.5 architectural decision (bandwidth scales with page
size, not total attempts).

**4.6 Q1 — styles.css consolidation framing.** The kickoff stated
that styles.css had accumulated additions from "4.1
(`.scorecard-report-empty`), 4.2 (`.scorecard-report-detail` and
friends), 4.4 (`.scorecard-report-actions`), and 4.5 (no custom CSS
added)" — a four-phase consolidation framing. Claude Code read
styles.css and confirmed only the Phase 4.2 detail block actually
existed in the file. Phase 4.1's `.scorecard-report-empty` was an
inline class hook on a Bootstrap `.alert .alert-info`, never added
to the stylesheet. Phase 4.4's `.scorecard-report-actions` was the
same pattern — class hook only, Bootstrap utility classes did the
visual work. The "consolidation across four phases" framing was
hollow; the actual polish-shaped improvement was a scope-prefix fix
on two leak-risk selectors that already existed.

**4.7 Q1 — CHANGES.md voice.** The kickoff drafted a "5–10 line
operator-facing summary" shape for the v0.4.0 entry. Claude Code
read CHANGES.md and confirmed every prior entry (v0.1.0, v0.2.0,
v0.3.0, v0.3.0 hotfix) was 70–120 lines with structured subsections
(`### Shipped` / `### Quality gates` / `### Spec status` /
`### Followups carried forward`). The 5–10-line variant existed
nowhere in the file. The kickoff convention was drafted from memory
of how CHANGES.md "should" look; the file's actual convention was
substantively different.

**The pattern.** Each instance was caught reactively during Q
disposition by reading the file before accepting the framing. Each
instance produced a sub-Q that reframed the work to match reality.
None landed silently; the discipline of "read the file before
drafting accumulated-state framings" worked as designed when invoked
at the Q gate.

The reflex banked (`feedback_kickoff_evidence_ground.md`) captures
the rule: when a kickoff prompt references state that has accumulated
across multiple prior sub-steps, the kickoff-author must read the
actual file before drafting — extrapolation from per-sub-step delta
memory drifts unreliably past three to five phases.

The Phase 5+ application: include "read the file before drafting
accumulated-state framings" as a pre-flight discipline step in every
sub-step kickoff for any phase that touches files first edited
multiple sub-steps prior. Catching reactively at Q gates worked for
Phase 4 (because each Q gate explicitly re-read the file) but
prevents better than catches.

---

## 5. The compound-helper-design pattern as a Phase 4 mechanic

The single most load-bearing mechanic of Phase 4's pace was the
helper introduced in 4.2 — `scorecard_get_attempt_responses` — and
the design discipline behind it.

**The shape.** The helper takes an array of attempt ids and returns
a map keyed by `attemptid` whose values are arrays of response rows.
Each response row is a left-joined row from `{scorecard_responses}`
+ `{scorecard_items}` so that `responsevalue`, the joined `prompt`,
and the joined `deleted` flag are all present in one fetch.
Soft-deleted items still surface their original prompt text (because
the join is to the live items table; the items aren't actually
gone, just flagged), so audit-honest report rendering can show
"this is what the learner answered, on this prompt that's since
been removed" without a second query.

**The use sites.** Designed for one consumer (4.2 per-attempt detail
render), the helper turned out to be the right shape for three more
without modification:

- **4.3 group-filtered table render** uses the helper from
  `report.php`, batch-fetching all visible attempts' responses in
  one call before the table render loop. Group filtering happens at
  the attempt-fetch layer (`scorecard_get_attempts` with a group
  parameter); the response helper is filter-agnostic.

- **4.4 CSV export item-set derivation** uses the helper from
  `export.php`. The export needs the union of itemids ever
  referenced across all in-scope attempts, with live items first
  by sortorder and deleted items at the end. The
  `scorecard_get_export_item_set()` helper iterates the existing
  data structure in pure PHP and produces the union — no second
  SQL query, no second helper, no schema knowledge in the export
  layer.

- **4.5 pagination per-page response fetch** uses the helper from
  inside the `report_table` flexible_table subclass's `query_db()`.
  Only the visible page's attempt ids are passed in, so the response
  fetch's bandwidth scales with page size (25 rows) rather than
  total attempts. The helper itself didn't change shape; only the
  caller's filtering of which attempt ids to pass changed.

**The discipline.** Design data structures for reusability, not
helpers for one call site. The helper's return value was shaped to
be **data**, not output. PHPUnit assertions target the data
structure directly without HTML rendering; UI consumers (renderer
methods, CSV writer, table subclass) compose the data however their
specific output layer requires. The output layer is platform
infrastructure (Moodle's renderers, csv_export_writer,
flexible_table); the data layer is the contract.

This was banked at the time as
`feedback_compound_helper_design.md`: "one helper feeding 3
sub-steps beats 3 parallel helpers. Returns-data shape over
streaming shape: PHPUnit targets the data, output layer is platform
infrastructure." Phase 4 is the canonical evidence for the rule.

The Phase 5+ application: when introducing a helper, ask
explicitly: what other sub-steps in this phase (or adjacent phases)
might consume this data? Could the data shape support those
consumers without a per-consumer variant? If yes, design the shape
once. If no, the helper is genuinely single-purpose and design
narrowly. The discipline isn't "always design for reuse" — it's
"think one sub-step ahead about whether reuse is plausible, and
only narrow the shape when reuse is implausible."

---

## 6. Walkthrough discipline observations

Phase 4 sub-steps each had a manual UI walkthrough plan at the
sub-step gate. Six to eight check items per gate, scaled to the
sub-step's scope. The user confirmed each item explicitly; "check #N
pass" meant the structural property was verified empirically, not
inferred from PHPUnit greens or phpcs zero-counts.

**What worked.**

- **Six-to-eight items per gate.** Enough to cover the sub-step
  surface; few enough that each got real attention. Scoped to "what
  changed in this sub-step" plus a small margin of regression
  surface from prior sub-steps.

- **Belt-and-suspenders edge cases.** 4.2's CSV special-character
  check (commas and quotes in prompts), 4.5's 50-attempt
  performance check (twice the pagination threshold), 4.4's
  filter-aware export verification — each caught real edge-case
  state that would have been easy to skip.

- **Explicit item-by-item confirmation.** The user reported each
  check's outcome individually rather than batch-confirming
  "everything passed." Item-level confirmation surfaces partial
  successes ("4 of 6 work; #3 has a copy nit") that batch-confirm
  hides.

- **Out-of-Claude verification for code-effect checks.** Walkthroughs
  ran in a real Moodle UI with real DB state, real PHP execution.
  PHPUnit tests can mock generously; walkthroughs reveal what the
  composition of mocked components actually produces.

**What had a blind spot.**

- **Walkthroughs were scoped to the sub-step's changes**, not to
  cross-activity comparison. The 4.6.5 layout bug had been present
  since Phase 1 but was invisible from each phase's "did the new
  thing work" check; only "compare against mod_quiz" surfaced it.
  Walkthroughs caught what the sub-step modified, not what the
  sub-step exposed.

- **The "is this how a user would see it" check** was strong within
  sub-step scope but absent at the cross-sub-step level. The
  user-experience comparison against neighboring activities is a
  separate check, run at release-readiness rather than at sub-step
  close. Phase 4 happened to surface this because Phase 4.6 was
  late in the phase; if Phase 4 had been shorter, the layout bug
  could easily have shipped in v0.4.0 and surfaced post-release.

The reflex banked
(`feedback_parallel_surface_comparison.md`): explicit
comparison-against-core-activity walkthrough at release-readiness
time. mod_quiz / mod_assign / mod_feedback / mod_lesson as
references; check across multiple viewport widths and multiple
roles; look specifically for content-area alignment, alert/notice
width, header/footer flow, button group spacing.

For Phase 5+: include the comparison check as an explicit gate
discipline at the docs/version-bump sub-step (Phase 5a's eventual
5x.7 equivalent). Don't relegate it to "if we remember." The
bugs it catches are structural and ship-quality-affecting; the
cost is small (~30 minutes per release).

---

## 7. Phase 5 implications

Phase 5a (gradebook integration via Moodle Grade API) and Phase 5b
(privacy provider implementation + nested backup steps + itemid
metadata) are **calibration-tax phases**. The Grade API and Privacy
API are new conceptual surfaces. Predictions for these phases must
NOT extrapolate from Phase 4's pace. Doing so would silently set up
the same trajectory miscalibration as Phase 4's 21–28 estimate but
in the opposite direction — over-confidence based on absorbed
pattern bank that doesn't apply to API surfaces the plugin has not
previously exercised.

**Phase 5a (gradebook integration).** The Grade API is a substantial
Moodle subsystem: grade items, grade categories, grade item types
(activity vs manual vs offline), grade modes (POINT vs SCALE vs
TEXT vs NONE), gradebook update APIs (`grade_update()`,
`grade_get_grades()`), gradebook deletion lifecycle on activity
removal, grade history, and the `_supports` callback declarations
(`FEATURE_GRADE_HAS_GRADE`, `FEATURE_GRADE_OUTCOMES`,
`FEATURE_ADVANCED_GRADING`). mod_scorecard's Phase 1 stubs declare
these features as off; Phase 5a will turn them on selectively and
implement the gradebook write path.

Best-case Phase 5a: 5–6 round-trips if the API absorbs cleanly and
no schema changes are needed beyond what Phase 1 anticipated.
Worst-case: 10+ round-trips if the gradebook integration discovers
schema gaps in `scorecard_attempts` (e.g., a need to track
`gradetimestamp` separately from `timecreated` for
gradebook-recompute scenarios) or if the activity-completion
integration needs to interlock with grade-update events in
non-obvious ways.

**Phase 5b (privacy + backup + itemid metadata).** Privacy provider
implementation has its own substantial surface: `get_metadata()`
declarations for both DB tables and any external-data-store usage,
`get_contexts_for_userid()`, `export_user_data()`,
`delete_data_for_user()`, `delete_data_for_users()`,
`delete_data_for_all_users_in_context()`. Each method must handle
both the live data and the snapshot fields correctly per SPEC §11.2's
snapshot stability rule. Plus the v0.1.0-followup itemid metadata
addition to `scorecard_responses` (a SPEC §9.5 correction documented
in v0.1.0 release notes but not yet implemented).

Nested backup steps add another conceptual surface:
`backup_scorecard_stepslib.php` currently captures settings only
(per the v0.1.0 known-limitation note); Phase 5b implements full
round-trip backup of items, bands, attempts, and responses with
snapshot fidelity.

Best-case Phase 5b: 4–5 round-trips. Worst-case: 8+ round-trips,
particularly if the privacy provider and the backup steps have
non-obvious interactions (which they do — both touch the same
nested data structures).

**Both phases earn calibration-risk inflation.** The pattern bank
from Phases 1–4 will help — test fixture shape, lang-key conventions,
capability-gate placement, helper-decomposition discipline, phpcs
patterns — but the API integration itself pays full setup tax. The
methodology insight that compounds across phases is the **discipline**,
not the round-trip count. Phase 4's 8-round-trip outcome should not
be treated as the new baseline; it's evidence that pattern-bank-rich
phases run fast, not that all phases will run that fast.

After Phase 5a and 5b absorb the Grade API and Privacy API patterns
into the bank, future phases that iterate on those APIs (e.g., a
hypothetical Phase 6 adding gradebook-passthrough features or
Phase 7 enhancing privacy data export) should run faster again.

The discipline to apply at Phase 5a kickoff: predict cautiously,
recalibrate aggressively at gates. If actual ≪ predicted, the
calibration risk overestimate was real; revise downward for
remaining sub-steps. If actual ~= predicted, the prediction
captured the calibration risk correctly. If actual ≫ predicted, the
calibration risk was under-estimated; widen subsequent predictions.

---

## 8. Memory bank state at Phase 4 close

Seven new entries banked off-commit during Phase 4. Plus refinements
to two existing entries. Reader should not need to dig through
individual memory files to know what was learned; the one-line
summaries below capture each.

**New entries (in chronological order of banking):**

1. **`feedback_php82_dynamic_property.md`** (banked at 4.5 close).
   PHP 8.2+ dynamic-property deprecation on `\flexible_table`
   subclasses: `$rawdata` is declared on `\table_sql` but NOT on
   `\flexible_table`, so subclassing flexible_table directly trips
   the deprecation when assigning `$this->rawdata`. Declare the
   property explicitly. Visible only via PHPUnit warnings.

2. **`feedback_phpcs_one_line_method_docblock.md`** (banked at 4.5
   close). moodle-cs requires a description sentence in every method
   docblock, including one-line `col_*` formatters on flexible_table
   subclasses. `@param`/`@return` alone fails. Earlier phases
   coincidentally satisfied this because methods were substantive;
   one-line formatters don't.

3. **`feedback_kickoff_evidence_ground.md`** (banked at 4.6 close).
   Kickoff-drafting reflex: when a kickoff references state that has
   accumulated across multiple prior sub-steps (e.g. "file X has
   additions from phases A, B, C"), read the actual file before
   drafting. Memory of per-sub-step deltas drifts past 3-5 phases.

4. **`feedback_write_time_discipline_pays_audit_time.md`** (banked
   at 4.6 close). When alphabetical insertion order, voice
   consistency, punctuation conventions, and schema discipline are
   maintained on every commit, retroactive sweeps find nothing to
   fix. An empty audit pass is signal that write-time hygiene held,
   not waste. Phase 4.6's lang-string sweep no-op is the canonical
   evidence: 23 `report:*` keys, all alphabetical, all consistent.

5. **`feedback_moodle_limitedwidth_phase1_reflex.md`** (banked at
   4.6.5). Every Moodle plugin top-level page that renders chrome
   (`$OUTPUT->header()`) must call
   `$PAGE->add_body_class('limitedwidth')` after `$PAGE->set_context()`.
   12 of 12 core mod plugins follow this; missing-state still works
   structurally but looks wrong against neighboring activities.
   Add to Phase 1 PAGE-setup checklist.

6. **`feedback_parallel_surface_comparison.md`** (banked at 4.6.5).
   At release-readiness time, walk a parallel surface against a
   core activity (mod_quiz, mod_assign, mod_feedback, mod_lesson)
   and compare visually for structural mismatches. Catches
   Phase-1 reflexes that walkthroughs alone miss because the
   missing-state still works.

7. **`feedback_moodle_phpunit_reinit_after_version_bump.md`**
   (banked at 4.7). After bumping `$plugin->version` in version.php,
   PHPUnit refuses to run with the opaque "Moodle PHPUnit
   environment was initialised for different version" error. Run
   `php public/admin/tool/phpunit/cli/init.php` to refresh
   component metadata. Reflex: bump → init → test, never skip.

**Refinements to existing entries:**

- **`feedback_phase_calibration_risk.md`** updated twice during
  Phase 4 (after 4.3 and after 4.5/Phase 4 close) with the firmed
  trajectory data. Currently reflects "Phase 4 closed at 8
  round-trips against 21–28 original" plus the
  pattern-bank-maturity weighting refinement: predictions for
  phases that ride a mature pattern bank should anchor to
  absorbed-pattern baseline, not worst-case scope estimation.

- **`feedback_compound_helper_design.md`** was banked earlier than
  Phase 4 but had its load-bearing example confirmed during Phase 4:
  `scorecard_get_attempt_responses` feeding 4 use sites (4.2, 4.3,
  4.4, 4.5) without modification.

**Aggregate insight.** Phase 4 was a memory-bank-rich phase: seven
durable methodology entries banked, each grounded in a specific
Phase 4 incident with concrete evidence. The `feedback_*` memory
type is doing the work it was designed for — rules captured at
their evidence point, with `**Why:**` and `**How to apply:**`
framing that makes the rule applicable to future situations rather
than tied to its origin context. Phase 5+ should continue the
discipline: when an incident produces a generalizable rule, bank
it immediately; when an incident is single-context, capture in
commit message or this kind of retrospective and don't pollute
the memory bank with non-generalizable observations.

---

## Closing

v0.4.0 ships with the manager-facing reports surface complete, the
Phase-1 layout regression resolved, three pre-Phase-4 fix-forwards
formally tagged, and seven methodology insights durably banked.
Phase 4 is closed.

The headline trajectory number — 8 round-trips against 21–28
predicted — is real but should not be the headline lesson. The
headline lesson is that mature pattern banks compound positively,
that kickoff drift compounds negatively, that structural-but-not-
functional bugs hide below percept thresholds until cross-cutting
chrome accumulates, and that comparison-against-convention-reference
catches the bugs walkthroughs alone miss. None of these are Phase
4-specific. All of them apply to Phase 5a and 5b kickoff drafting
immediately.

Phase 5a calibration-tax estimate stands at 5–10 round-trips for the
gradebook surface. Phase 5b stands at 4–8 for privacy + backup +
itemid metadata. Both predictions explicitly assume calibration-risk
inflation rather than extrapolating from Phase 4's pace.

Next operations after this retrospective lands and ships:
- One-time scheduled audit (~2 weeks out) re-grepping the LMS Light
  plugin tree for new top-level pages added without `limitedwidth`,
  to catch the reflex regressing in adjacent plugin work.
- Phase 5a kickoff drafting, with explicit calibration-risk framing
  and the read-the-file pre-flight discipline applied from kickoff
  drafting forward.

Phase 4 closed at v0.4.0. Pattern bank carries forward.
