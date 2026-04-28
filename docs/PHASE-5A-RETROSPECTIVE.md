# Phase 5a retrospective — mod_scorecard

Internal archaeological record of how Phase 5a (gradebook integration +
custom completion) actually unfolded. Written immediately after v0.5.0
shipped (2026-04-27) so the lessons are captured while still warm. Not
a release note, not a changelog, not customer-facing — this is for
future Claude Code sessions reading project history (and for future
John re-orienting on the codebase) to understand the methodology that
worked, the discoveries the phase made, and the calibrations to carry
into Phase 5b.

CHANGES.md says what shipped. This document says how it shipped, why
the shape worked, what surprised us along the way, and what would
still surprise us if we tried to extrapolate from Phase 5a to Phase 5b
without thinking.

The Phase 4 retrospective at `080fe57` (`docs/PHASE-4-RETROSPECTIVE.md`)
established the shape; this one continues the pattern. The two
retrospectives together form the methodology archive for the Grade
API and Reports surfaces — Phase 5b's privacy provider work will
likely produce a third instalment.

---

## 1. Trajectory data

Phase 5a kickoff predicted **5–10 round-trips** for the gradebook +
completion surface, framed deliberately as a calibration-tax phase
because the Moodle Grade API was genuinely new conceptual surface for
this plugin (Phases 1–4 had touched authoring, learner submission, and
reporting — none of which exercise the gradebook subsystem). The
prediction explicitly anticipated calibration tax in early sub-steps,
with a recalibrate-at-gates discipline.

Phase 5a closed at **8 round-trips total**: 7 forward-progress
sub-steps plus 1 fix-forward. Within the kickoff range, in the upper
half of the prediction band — calibration-tax-honest in the
upper-bound-allowance sense.

### Per-sub-step prediction vs actual

| Sub-step | Commit | Prediction | Actual | Type B count | Notes |
|----------|--------|-----------:|-------:|-------------:|-------|
| 5a.0 — SPEC §9.2 grade-method clarification | `a562554` | 1 | 1 | 0 | SPEC-only commit; voice convention application |
| 5a.1 — Grade callbacks + FEATURE_GRADE_HAS_GRADE flip | `0fe58d5` | 1–2 | 1 | 3 absorbed | Phase 5a's first Grade API surface; banked first reflex |
| 5a.2 — Items-CRUD lifecycle hooks for auto-grademax | `4ccc70a` | 1–2 | 1 | 2 absorbed | Cache-priming hazard surfaced and banked |
| 5a.3 — Submit-time grade propagation hook | `8c7da37` | 1 | 1 | 0 | Smallest sub-step; pattern bank fully absorbed |
| 5a.4 — Completion integration + first schema change | `a12a6b9` | 1–2 | 1 | 1 multi-part | moodle-cs-divergence reflex banked |
| 5a.5 — Backfill upgrade savepoint (broken) | `530f105` | 1–2 | 1 | 3 absorbed | Test-context-divergence trio; passed PHPUnit but broken in production |
| 5a.5 fix-forward — drop synchronous backfill | `b3ac78b` | n/a | 1 | 1 (gate-failure) | Discovered post-push when upgrade applied in DDEV |
| 5a.6 — Docs and v0.5.0 release | `01c3889` | 1 | 1 | 0 | Empirical-upgrade-application gate discipline applied |

### Cumulative growth

```
After 5a.0: 1 round-trip
After 5a.1: 2
After 5a.2: 3
After 5a.3: 4
After 5a.4: 5
After 5a.5: 6 — broken-as-committed; passed PHPUnit; failed empirical upgrade
After 5a.5 fix-forward: 7 — corrected via lifecycle-hook fallback
After 5a.6: 8 — v0.5.0 shipped, Phase 5a closed
```

The trajectory was linear-at-1-per-sub-step from 5a.0 through 5a.6,
mirroring Phase 4's pace. The 5a.5 → fix-forward sequence was the
only "extra" round-trip — exactly the structural shape of Phase 4's
4.6 → 4.6.5 layout-regression fix-forward (see section 6 for the
structural-twin discussion). Both Phase-4 and Phase-5a closed at 8
round-trips with a single fix-forward, despite very different
underlying surfaces.

Phase 5a's range comparison: kickoff 5–10, actual 8, lower-bound
infeasible-after-fix-forward, honest-expectation 6 became the floor
once the fix-forward landed. The honest-expectation floor was
exceeded by 2 round-trips because of the fix-forward and the
subsequent gate-discipline application — still inside the
upper-bound-allowance band.

---

## 2. What made the trajectory possible

The compound-dividend insight from Phase 4 carried forward: a mature
pattern bank from Phases 1–4 absorbed cleanly into Phase 5a's surfaces
where they could, even though the Grade API and Completion API
themselves were genuinely new conceptual ground. Five specific
patterns carried the load.

**Helper-establishment discipline at first use.** Phase 4.5's
`render_table_html` helper validated the "establish helpers when
the first test needs them, absorb subsequent tests cleanly" pattern.
Phase 5a applied this in 5a.1 by establishing two grade-test helpers
the moment they had concrete callsites: `assert_scorecard_grade_item`
(canonical introspection via `\grade_item::fetch`) and
`assert_scorecard_user_grade` (user-facing grade access via
`\grade_get_grades`). Both helpers absorbed every subsequent grade
test through 5a.5 without modification. The 5a.5 upgrade-path test
needed a third pattern (direct DB queries on `grade_grades`) only
because of the upgrade-mode residual flag (see section 4); even then,
the pre-existing helpers covered the pre-savepoint assertions.

**Evidence-grounded test assertions.** Phase 4's reflex of "read the
file before drafting framings" continued to pay off. When 5a.4's
test scaffolding was being designed, the first cut hit the
"Cannot be executed during upgrade" trap — surfacing because of the
cache-rebuild path triggered by `grade_get_grades`. Diagnosing that
required looking at the actual exception trace and the gradelib
source, not extrapolating from "PHPUnit usually works." The same
discipline caught the cache-priming hazard in 5a.2: the failure
manifested as three apparently-unrelated test failures, but tracing
to root cause revealed a single shared issue (the new hook in
`scorecard_add_item` priming the MODE_REQUEST cache).

**Write-time conventions paying audit-time dividends.** Phase 4.6's
methodology insight (alphabetical/voice/punctuation consistency
maintained on every commit eliminates retroactive sweeps) held
through Phase 5a. New lang strings (`completionsubmit`,
`completiondetail:submit`) landed alphabetically with consistent
voice; no audit pass was needed at 5a.6's docs sub-step. Schema
columns followed the established XMLDB conventions; comments matched
the established docblock voice; phpcs nits were minimal (the
moodle-cs-divergence reflex caught novel patterns from imported
core boilerplate, but each was a 1-2 line fix).

**The in-plugin reference test pattern.** mod_scorecard already had
an upgrade-path PHPUnit test from Phase 1's fix-forward (`946d09b` —
SPEC §9.1 capability matrix correction; tests/access_test.php). When
5a.5 needed to test the backfill savepoint, the access_test.php
pattern was directly portable: `set_config('version', $previous,
'mod_scorecard')` to rewind the plugin version, then call
`xmldb_scorecard_upgrade($previous)` directly. The pattern's
prerequisite (`require_once($CFG->libdir . '/upgradelib.php')`) was
also captured in the same reference test. Two of 5a.5's three Type B
items (the downgrade exception and the undefined function) were
caught and resolved within seconds by porting the reference test's
setup. The third (the upgrade-mode residual flag affecting
`grade_get_grades`) was genuinely new and required tracing to
gradelib's internals.

**mod_assign and mod_choice as Grade API and completion-API
references.** When 5a.1's grade callbacks needed shape, mod_assign's
grade_item_update / get_user_grades / update_grades / grade_item_delete
quartet was the directly-portable pattern. mod_assign's latest-attempt
overwrites semantics matched the implicit-then-made-explicit SPEC
§9.2 disposition (Decision v0.4.2). When 5a.4's completion needed
shape, mod_choice's `completionsubmit` column + activity_custom_completion
class + add_completion_rules / completion_rule_enabled methods were
directly-portable patterns. Both core plugins provided structural
templates without explicit context-switching cost.

The compound effect: Phase 5a paid full calibration tax for the
Grade API surface (the six Moodle-internal-API quirks captured in
banked memories — see section 5), but did NOT pay calibration tax for
the methodology around it (helper design, test fixture shapes, schema
migration discipline, lang-string conventions). The tax was localized
to genuinely-new conceptual surface; the surrounding methodology was
already absorbed.

---

## 3. The 5a.5 → fix-forward sequence as worked example of gate-discipline-failure-driven fix-forward

This is the structural twin of Phase 4's 4.6 → 4.6.5 layout-regression
fix-forward, and worth its own section because the pattern recurred —
suggesting it's a durable methodology phenomenon rather than a
one-off Phase 4 artifact.

### What happened

5a.5 was scoped as the upgrade-path backfill: existing v0.4.x
deployments with `gradeenabled=1` scorecards would have grade items
created and populated with user grades from existing attempts at
upgrade time. The kickoff Q4 disposition picked synchronous backfill
over cron-deferred, justified by LMS Light's small deployment scale
(two pre-launch customers, near-zero existing attempt history).

Implementation went smoothly in PHPUnit: the upgrade-path test
simulated the v0.4.x baseline (scorecard exists, grade item
direct-DB-deleted to mirror pre-5a.1 state), called
`xmldb_scorecard_upgrade(2026042701)`, and asserted the grade item
materialized with correct user grades. Three Type B items surfaced
in the test environment (`set_config('version', ...)` rewinding,
`require_once` for upgradelib.php, switching post-savepoint
assertions from `grade_get_grades` to direct DB queries on
`grade_grades`); each was resolved within the round-trip using
patterns from the in-plugin reference test (access_test.php). PHPUnit
landed at 144/620, all green. phpcs zero/zero. SPEC sha unchanged.

The commit landed at `530f105`, was pushed to `origin/main`, and the
DDEV upgrade was attempted to verify dev-environment behavior. The
upgrade failed:

```
Default exception handler: Cannot be executed during upgrade
* line 1224 of /public/lib/setuplib.php: ... thrown
* line 69 of /public/lib/modinfolib.php: call to upgrade_ensure_not_running()
* line 969 of /public/course/lib.php: call to get_fast_modinfo()
* line 629 of /public/lib/grade/grade_item.php: call to course_module_instance_pending_deletion()
* line 233 of /public/mod/scorecard/lib.php: call to grade_update()
* line 354 of /public/mod/scorecard/lib.php: call to scorecard_grade_item_update()
* line 138 of /public/mod/scorecard/db/upgrade.php: call to scorecard_update_grades()
```

The synchronous backfill iteration, which had passed PHPUnit cleanly,
threw "Cannot be executed during upgrade" on the very first
production-like upgrade application.

### Why PHPUnit was wrong about this

`grade_update` is the public Moodle gradebook API. Internally, it
calls `grade_item->is_locked()`, which calls
`course_module_instance_pending_deletion()`, which calls
`get_fast_modinfo()`, which calls `upgrade_ensure_not_running()`.
Moodle 5.x explicitly throws when this guard fires during upgrade
context — the cm-info cache may not be valid mid-upgrade and the
locked-state check refuses to operate without it.

PHPUnit upgrade-path tests don't trigger this guard. The test calls
`xmldb_scorecard_upgrade()` directly, bypassing Moodle's main
upgrade-flow setup — so `upgrade_ensure_not_running()` doesn't have
the upgrade-flag set when the call happens during the test. The
test passes against code that fails 100% of the time in production.

### The fix-forward

Option B (cron-deferred adhoc task) was Moodle-canonical for bulk
grade operations during upgrade but represented a substantial
refactor. Option C (direct DB manipulation bypassing grade_update's
lock check) was brittle. Option A (drop the synchronous backfill,
rely on lifecycle hooks for post-upgrade grade item creation when
operators edit scorecards) was the smallest fix and matched the
realistic deployment context — LMS Light's actual customer base
likely has zero `gradeenabled=1` scorecards from v0.4.x because
gradeenabled was an opt-in toggle with no useful effect before 5a.1.

The fix-forward (`b3ac78b`) emptied the savepoint body, kept the
version-stamp advance + a substantial inline comment naming the
infeasibility and the lifecycle-hook fallback, and removed the
PHPUnit backfill test entirely (testing a code path that no longer
existed). The CHANGES.md ### Operator action subsection in 5a.6
documented the lifecycle-hook fallback for the rare deployment
needing manual remediation.

### Structural twin to Phase 4's 4.6 → 4.6.5

Phase 4's layout-regression fix-forward (4.6.5) shared the same
shape: a problem reached `origin/main` because the reigning
gate-discipline at the time didn't include the verification step
that would have caught it. For 4.6.5, that step was "side-by-side
comparison against a core activity at release-readiness" — the
parallel-surface-comparison reflex. For 5a.5, that step was
"empirical upgrade application as part of gate verification, not
just PHPUnit pass" — the new gate discipline.

In both cases, the fix-forward applied the new gate discipline going
forward. Phase 4.6.5's discipline was applied at v0.5.0's gate (5a.6).
Phase 5a.5-fix-forward's discipline was applied at 5a.6's gate before
commit — the empirical upgrade ran in DDEV, succeeded in 0.09s,
verified the version stamp advanced cleanly. The 5a.6 commit body
explicitly named the gate discipline as part of the verification
record.

The methodology evolution is real: gate-discipline checklists
accumulate over phase work as new failure modes surface. Phase 5b
will likely add a third stratum (see section 6 for the
gate-discipline evolution meta-pattern in detail).

---

## 4. Test-context vs production-context divergence as load-bearing Phase 5a methodology insight

The single most consequential meta-pattern from Phase 5a: **Moodle's
plugin-upgrade subsystem is its own execution context with its own
constraints, distinct from production runtime AND from PHPUnit test
environment. Code paths that work in two of those three contexts may
not work in the third.**

Six Phase 5a Type B items fall into this meta-pattern:

1. **5a.1's `grade_get_grades` returning incomplete `$item`
   structure.** Display-oriented helper; `grade_item::fetch` is the
   canonical introspection path. Failed at the helper level for
   gradetype/grademax assertions; non-divergence in production runtime
   (where the helpers are used for user-facing grade display).
2. **5a.2's MODE_REQUEST cache priming.** New gate-check caller in
   `scorecard_add_item` primed the cache at 0; subsequent
   `scorecard_count_attempts` callers within the same request saw
   stale 0; existing test fixtures (which inserted attempts directly
   without invalidating the cache) worked before but broke after the
   priming hook landed.
3. **5a.5's downgrade exception.** `upgrade_mod_savepoint` rejects
   same-version-to-same-version calls; tests calling
   `xmldb_scorecard_upgrade()` directly need
   `set_config('version', $previous, 'mod_scorecard')` first.
   Test-context divergence: production upgrade flow sets the version
   correctly before invoking the savepoint; tests bypass that.
4. **5a.5's undefined `upgrade_mod_savepoint`.** Tests bypass
   Moodle's main upgrade flow which loads `lib/upgradelib.php`;
   tests must require_once it explicitly.
5. **5a.5's upgrade-mode residual flag affecting `grade_get_grades`.**
   `upgrade_mod_savepoint` leaves a residual upgrade-mode flag in
   `$CFG`; subsequent `grade_get_grades` calls in the same request
   trigger course-cache rebuilds blocked by `upgrade_ensure_not_running`.
   The cache-rebuild path applies in test contexts after the savepoint
   completes; production handles the cleanup differently.
6. **5a.5-fix-forward's `grade_update` structurally blocked during
   upgrade.** The hardest variant: `grade_update` itself fires the
   guard via the cm-pending-deletion check. Production upgrade context
   correctly blocks this; PHPUnit upgrade-path tests don't replicate
   the guard, so tests can pass against code that fails 100% in
   production.

The first two items are local API-shape divergences (test environment
loaded all plugin files; production loads selectively; cache state
differs between the two). The last four items are upgrade-context
divergences specifically — Moodle's upgrade subsystem maintains
structural invariants that downstream APIs respect, but the test
environment doesn't replicate them faithfully.

The implication for kickoff drafting and gate verification:

- **For any sub-step that touches db/upgrade.php**, empirical upgrade
  application in DDEV is required gate verification, not optional.
  PHPUnit alone is insufficient (banked at 5a.5-fix-forward as the
  new gate-discipline addition).
- **For any helper that wraps a Moodle API**, both the test path
  (PHPUnit) and the production path (full Moodle stack) need to be
  considered when designing the helper. Phase 5a's experience with
  `grade_get_grades` vs `grade_item::fetch` is the canonical example:
  the helper that's right for tests may be wrong for production, and
  vice versa.
- **For any cache-backed counter** (MODE_REQUEST in particular), new
  callers need to consider whether their caller-context primes the
  cache for downstream consumers. Direct `$DB->count_records` is
  often the safer choice for gate checks; cached helpers are right
  for repeated reads within an established request flow.

This meta-pattern is the most portable Phase 5a methodology insight.
Phase 5b's privacy provider work will almost certainly hit similar
divergences (see section 7).

---

## 5. Six Type B reflex archaeological summaries

Each banked memory below captures a Moodle-internal-API reflex that
emerged from a specific Phase 5a incident. The memory files include
the full incident-fix-applicability narrative; the summaries here are
single-paragraph archaeological digests.

**`feedback_moodle_phpunit_reinit_after_version_bump.md`** (banked
during Phase 4.7's v0.4.0 release; load-bearing throughout Phase 5a's
five version bumps). After editing `$plugin->version`, PHPUnit refuses
to run with the opaque "Moodle PHPUnit environment was initialised
for different version" error; running
`php public/admin/tool/phpunit/cli/init.php` refreshes the cached
component metadata. The reflex is "bump → init → test, never skip
init." Phase 5a applied this five times (5a.0, 5a.1's discovery via
phpcs/phpunit, 5a.4 schema bump, 5a.5 stamp catch-up, 5a.6 release
bump) — the reflex was applied without surprise each time, indicating
the memory is correctly load-bearing.

**`feedback_moodle_grade_item_fetch_for_introspection.md`** (banked
at 5a.1). `grade_get_grades` is display-oriented; its `->items` array
elements lack `gradetype`, `grademax`, and other canonical grade_items
fields. For PHPUnit assertions on grade item attributes, use
`grade_item::fetch` with the canonical key map. mod_scorecard's
5a.1 testing produced 5 of 7 grade test failures in the first cut
because the helper used `grade_get_grades`; switching to
`grade_item::fetch` resolved all five immediately. Pairs with
`feedback_moodle_upgrade_mode_residual_flag.md`'s observation that
`grade_get_grades` is also unsafe in upgrade-mode contexts — the
helper has both an introspection limitation and a context-safety
limitation.

**`feedback_moodle_request_cache_priming.md`** (banked at 5a.2).
MODE_REQUEST-cached counters (`scorecard_count_attempts` is the
canonical example; backed by `db/caches.php`) prime the cache on
first read. New callers that read the cached counter for gate-checks
within a request can break later callers in that request if a
mutation happens between the priming read and the later read without
the mutator invalidating the cache. The fix: new gate-check callers
should use direct `$DB->count_records` rather than the cached helper
to avoid priming. Phase 5a.2's
`scorecard_recompute_grade_if_no_attempts` hook in `scorecard_add_item`
broke three pre-existing tests by priming the cache; switching the
helper to direct `$DB->count_records` resolved all three.

**`feedback_moodle_cs_diverges_from_core.md`** (banked at 5a.4).
Copying boilerplate from a Moodle core mod plugin (mod_choice's
custom_completion class as the 5a.4 reference) does NOT guarantee
phpcs cleanliness in a third-party plugin. moodle-cs has tightened
several sniffs since core files were last audited
(`declare(strict_types = 1)` vs `=1`, blank line after class brace,
lowercase inline-comment-opener). 5a.4's `classes/completion/custom_completion.php`
landed structurally identical to mod_choice's version but produced
3 phpcs ERRORS and 1 WARNING on the first phpcs run — all four
fixed in seconds, but the reflex worth banking is "budget 1-3 small
fixes when porting a core pattern."

**`feedback_moodle_upgrade_mode_residual_flag.md`** (banked at 5a.5).
`upgrade_mod_savepoint()` leaves a residual upgrade-mode flag in
`$CFG`/runtime state after the savepoint completes. PHPUnit
upgrade-path tests calling `xmldb_<plugin>_upgrade()` directly hit
this flag's downstream effects: `grade_get_grades` and any helper
that triggers `get_fast_modinfo` / `rebuild_course_cache` throws
"Cannot be executed during upgrade." Safe alternatives are
`grade_item::fetch` and direct `$DB` queries on `grade_grades`.
5a.5's upgrade-path test used `grade_get_grades` in the first cut
and failed; switching to direct DB queries resolved.

**`feedback_moodle_grade_update_blocked_in_upgrade_context.md`**
(banked at 5a.5-fix-forward). The tighter and more fundamental
constraint: `grade_update` itself is structurally blocked during
Moodle 5.x plugin upgrade context, regardless of cache state.
Internally, `grade_update` calls `grade_item->is_locked` →
`course_module_instance_pending_deletion` →
`get_fast_modinfo` → `upgrade_ensure_not_running`, which throws when
called during upgrade. PHPUnit upgrade-path tests don't replicate
the production guard and may pass against structurally-broken code.
The lessons: never call `grade_update` from a savepoint; defer to
lifecycle hooks (next instance edit) or cron-deferred adhoc tasks;
add empirical upgrade application in DDEV to the gate-discipline
checklist for any sub-step touching db/upgrade.php.

The six reflexes plus the meta-pattern (test-context vs
production-context divergence — section 4) form the durable Phase 5a
methodology archive. Phase 5b's privacy provider work will inherit
these and likely add its own.

---

## 6. Gate-discipline evolution meta-pattern

A pattern across two consecutive phases now: **gate disciplines
accumulate over phase work as new failure modes are discovered. Each
phase's gate-discipline checklist is a superset of prior phases'**.

The structural shape repeats:

- A sub-step lands at gate, passes the reigning gate-discipline
  (PHPUnit, phpcs, walkthrough), gets committed and pushed.
- Post-push, a problem surfaces that the reigning gate-discipline
  didn't catch.
- A fix-forward applies the discovered discipline going forward, plus
  banks the methodology insight.
- Subsequent gates apply the new discipline; the discipline becomes
  durable.

Phase 4's 4.6 → 4.6.5 was the first instance:

- **Sub-step**: 4.6 polish (CSS scope-prefix consistency).
- **Reigning gate-discipline**: PHPUnit + phpcs + sub-step-scoped
  walkthrough.
- **Problem surfaced post-push**: layout regression visible only
  through side-by-side comparison with mod_quiz at the viewport
  level. The Phase-1 reflex of `$PAGE->add_body_class('limitedwidth')`
  had been missing since Phase 1, propagated forward through every
  subsequent top-level page added.
- **Fix-forward**: 4.6.5 added the body class to all four top-level
  pages.
- **Gate-discipline addition**: parallel-surface comparison against a
  core activity at release-readiness.
- **Banked memory**: `feedback_parallel_surface_comparison.md`.

Phase 5a's 5a.5 → fix-forward was the second instance:

- **Sub-step**: 5a.5 backfill upgrade savepoint.
- **Reigning gate-discipline**: PHPUnit + phpcs + walkthrough +
  parallel-surface comparison (Phase 4.6.5's addition, applied where
  applicable).
- **Problem surfaced post-push**: production upgrade context blocks
  `grade_update`; PHPUnit doesn't replicate the guard.
- **Fix-forward**: 5a.5-fix-forward dropped the synchronous backfill
  iteration, deferred to lifecycle-hook fallback.
- **Gate-discipline addition**: empirical upgrade application
  (`php admin/cli/upgrade.php` in DDEV) for any sub-step touching
  db/upgrade.php.
- **Banked memory**:
  `feedback_moodle_grade_update_blocked_in_upgrade_context.md`.

Both cases share the structural property that the reigning
gate-discipline at the time was insufficient for the failure mode
that surfaced. The fix-forward in each case applied the new
discipline going forward and banked it as durable methodology.

The methodology insight: **the gate-discipline checklist is itself a
phase-evolving artifact**, not a static given. Each phase's
gate-discipline catches what prior phases discovered + whatever new
discipline that phase itself contributes. Phase 5b's gate-discipline
will be a superset of Phase 5a's, with whatever new failure modes
Phase 5b discovers added.

For Phase 5b kickoff drafting, this pattern suggests two reflexes:

1. **Predict the new discipline.** Privacy provider work will likely
   surface bootstrap-state-divergence patterns (privacy provider APIs
   assume Moodle bootstrap state — user context, system context with
   appropriate capabilities, possibly background-task state — that
   test contexts may lack). The likely new gate-discipline:
   "empirical privacy-export-and-delete verification with realistic
   user contexts, not just PHPUnit assertions on the metadata
   declarations." This is a forward-prediction; it may or may not be
   the actual addition.

2. **Apply all accumulated disciplines from the start.** Phase 5b's
   gate-discipline checklist explicitly includes:
   - PHPUnit pass.
   - phpcs zero/zero.
   - Sub-step-scoped walkthrough.
   - Parallel-surface comparison against a core activity at
     release-readiness (Phase 4.6.5).
   - Empirical upgrade application for db/upgrade.php-touching
     sub-steps (Phase 5a.5-fix-forward).
   - Plus whatever Phase 5b itself discovers.

The discipline of "name the discipline at kickoff time" is itself a
methodology insight: making the checklist explicit at kickoff makes
gate verification consistent and reduces the chance of a third
fix-forward landing because a discipline was forgotten.

---

## 7. Phase 5b implications

Phase 5b (privacy provider implementation + nested backup steps +
itemid metadata) is a calibration-tax phase. The Privacy API and
backup/restore APIs are new conceptual surfaces for this plugin —
the existing scaffolds (Phase 1's privacy provider stub at
`classes/privacy/provider.php`, Phase 1's settings-only backup at
`backup/moodle2/`) are skeletons, not implementations. The
calibration tax for Phase 5b will be similar in shape to Phase 5a's
Grade API tax: the API surfaces are well-trodden in Moodle core but
have plugin-internal-API quirks that surface only through hands-on
work.

### Best-case Phase 5b: 4–5 round-trips

If the Privacy API absorbs cleanly (privacy provider implementation,
context discovery, export user data, delete user data, delete users
in context, delete data for context) AND the backup nested-steps land
clean (backup_scorecard_stepslib.php capturing items, bands,
attempts, responses; restore mappings preserving snapshot fidelity)
AND the itemid metadata addition is a one-line schema declaration,
Phase 5b could close at 4–5 round-trips.

### Worst-case Phase 5b: 8–10 round-trips

The privacy and backup APIs have known plugin-internal-API quirks:

- Privacy provider's `delete_data_for_users()` semantics are subtle:
  delete attempts but preserve items/bands/scorecard activity; this
  is the "audit trail without re-attempt" semantic from SPEC §9.5.
- Privacy provider's `export_user_data()` for nested data structures
  (attempt → responses → items) requires careful relationship
  walking; getting this wrong silently exports incomplete data.
- Backup steplib annotations are strict: every nested element needs
  `annotate_ids` for foreign-key resolution, missing annotations
  cause silent data corruption on restore.
- Restore mapping subtleties: `apply_activity_instance` in Phase 1's
  scaffold was minimal; full restore needs item id mapping, band id
  mapping, attempt id mapping, and snapshot-preservation guards
  during the restore.

If these surface as Type B items (likely), each adds a round-trip's
cost. Worst-case Phase 5b extends to 8–10 round-trips, with 1-2
fix-forwards possible.

### Honest expectation: 5–7 round-trips

The Phase 5a calibration discovery (test-context vs production-context
divergence as load-bearing) suggests that Privacy API testing will
have its own divergence flavors — privacy contexts in tests may not
mirror production privacy contexts faithfully. Empirical privacy
verification (real export, real delete, with realistic test data) is
the likely gate discipline. Predict cautiously and recalibrate at
each gate.

### Pattern bank from Phase 5a ports forward

The methodology assets carry over:

- **Helper-establishment discipline**: privacy tests and backup tests
  will benefit from helpers established at first use rather than
  inlined per-test. Likely candidates: `assert_user_data_exported`,
  `assert_attempt_round_trip_preserved`, `simulate_v0.4.x_baseline`.
- **In-plugin reference test pattern**: access_test.php (Phase 1
  fix-forward) and grade_test.php (Phase 5a) both establish
  upgrade-path test patterns. Phase 5b's backup/restore tests can
  follow the same shape.
- **Evidence-grounded test assertions**: avoid the
  display-oriented-helper-vs-canonical-API trap by checking which
  helper provides the canonical introspection for the property
  being asserted.
- **Test-context vs production-context discipline**: the meta-pattern
  from section 4 applies directly to privacy provider work.
- **Empirical gate verification**: privacy provider work touches
  db/install.xml (itemid column on scorecard_responses) which means
  db/upgrade.php gets a savepoint, which means empirical upgrade
  application is required gate discipline.

### What's genuinely new in Phase 5b

- **GDPR / data-subject semantic correctness**. The privacy provider's
  job is not just code-level correctness but legal/regulatory
  correctness around data export and deletion. The SPEC §9.5
  specifies what fields to declare and how to walk relationships,
  but the operator-facing contract (what an LMS Light tenant admin
  can promise their data subjects) needs to be honored.
- **Restore-time snapshot fidelity**. Items, bands, and attempts have
  snapshot fields that must round-trip byte-for-byte through backup
  and restore. SPEC §11.2 stability rules apply.
- **Cross-tenant data sharing semantics**. CONTEXT.md notes that LMS
  Light has no cross-tenant data sharing. Privacy provider operates
  strictly within the per-tenant Moodle instance; this needs to be
  named explicitly in the privacy provider's docblock for future
  maintainers.

The discipline to apply at Phase 5b kickoff: predict cautiously per
the calibration-tax framing; predict the new gate-discipline
addition (likely empirical privacy-export-and-delete verification);
apply all accumulated gate disciplines from kickoff time; recalibrate
aggressively at each gate.

---

## 8. Memory bank state at Phase 5a close

Six Phase 5a-relevant Moodle-internal-API reflex memories are now
load-bearing across the plugin's methodology surface. Five were banked
during Phase 5a; the sixth (PHPUnit re-init) was banked during Phase
4.7 but applied throughout Phase 5a's five version bumps.

**New entries banked during Phase 5a (in chronological order):**

1. **`feedback_moodle_grade_item_fetch_for_introspection.md`** (banked
   at 5a.1). `grade_get_grades` is display-oriented; gradetype/grademax
   assertions need `grade_item::fetch`. 5/7 first-cut grade tests fixed
   immediately by switching the helper.

2. **`feedback_moodle_request_cache_priming.md`** (banked at 5a.2).
   New gate-check callers should use direct `$DB->count_records`,
   not the cached helper, to avoid priming the cache and breaking
   later callers in the same request.

3. **`feedback_moodle_cs_diverges_from_core.md`** (banked at 5a.4).
   Copying boilerplate from core (declare-statement spacing, blank
   line after class brace, lowercase inline-comment-opener) doesn't
   guarantee phpcs cleanliness; budget 1-3 small fixes when porting
   a core pattern.

4. **`feedback_moodle_upgrade_mode_residual_flag.md`** (banked at
   5a.5). After `xmldb_<plugin>_upgrade()` in PHPUnit, use
   `grade_item::fetch` + direct `$DB` queries on `grade_grades`;
   `grade_get_grades` triggers cache rebuild that's blocked.

5. **`feedback_moodle_grade_update_blocked_in_upgrade_context.md`**
   (banked at 5a.5-fix-forward). `grade_update` is structurally
   blocked during Moodle 5.x plugin upgrade context — never call it
   from xmldb_<plugin>_upgrade savepoints. PHPUnit doesn't replicate
   the guard. Defer to lifecycle hooks or cron-deferred adhoc tasks.

**Carried-forward entry from Phase 4 (load-bearing throughout Phase 5a):**

6. **`feedback_moodle_phpunit_reinit_after_version_bump.md`** (banked
   at Phase 4.7). After `$plugin->version` change, run
   `php public/admin/tool/phpunit/cli/init.php` before running tests.
   Applied 5 times in Phase 5a (5a.0/5a.1 indirectly; 5a.4 first
   schema change; 5a.5 stamp catch-up; 5a.6 release bump).

**Refinements to existing entries:**

- **`feedback_phase_calibration_risk.md`** updated implicitly by
  Phase 5a's outcome: the "calibration tax bites in early sub-steps,
  pattern bank absorbs by mid-phase" prediction held cleanly. The
  trajectory data (8 round-trips vs 5-10 prediction, 3 Type B items
  in 5a.1, decreasing through 5a.3, surge again at 5a.5) supports
  the original framing without revision.

- **`feedback_kickoff_evidence_ground.md`** continued to pay off.
  Phase 5a's six kickoffs all had evidence-grounded pre-flight
  reads; no kickoff drift incidents surfaced. The reflex is now
  fully internalized as kickoff-drafting discipline.

**Aggregate memory bank state:**

The Phase 4 retrospective listed 7 new Phase-4-banked memories and
refinements. Phase 5a adds 5 new banked memories plus the 1 carried
forward. Total active reflex catalogue at Phase 5a close
(approximate):

- ~8 build-session methodology reflexes (commit trailers, git
  preflight, kickoff approval, surface implementer improvements,
  etc.).
- ~12 Moodle-specific API/testing reflexes (CLI paths, version
  stamps, archetype names, access propagation, language cache purge,
  PHPUnit re-init, grade_item fetch, request cache priming,
  moodle-cs divergence, upgrade-mode residual flag, grade_update
  upgrade-block, limitedwidth body class).
- ~3 phase-prediction methodology reflexes (calibration risk,
  compound helper design, evidence-grounded kickoff drafting,
  parallel-surface comparison, write-time discipline pays
  audit-time, phpcs description-sentence reflex).

The catalogue is reaching the size where structural organization
matters. The MEMORY.md index is doing the right work — each entry's
hook line lets future-Claude scan the catalogue without reading
every memory file. As the catalogue grows past 30 entries, an
explicit topical organization (Moodle-API vs build-process vs
prediction-methodology) may become useful; for now, chronological
order with topical hook lines is sufficient.

**Aggregate insight.** Phase 5a was a memory-bank-rich phase, similar
in shape to Phase 4. Five new durable methodology entries banked,
each grounded in a specific Phase 5a incident with concrete evidence.
The `feedback_*` memory type continues to do its load-bearing work —
rules captured at their evidence point, with `**Why:**` and
`**How to apply:**` framing that makes the rule applicable to future
situations. Phase 5b should continue the discipline: when an
incident produces a generalizable rule, bank it immediately; when an
incident is single-context, capture in commit message or this kind
of retrospective and don't pollute the memory bank with
non-generalizable observations.

---

## Closing

v0.5.0 ships with the gradebook integration and completion surfaces
complete, the upgrade path resolved via lifecycle-hook fallback, six
durable Moodle-internal-API reflex memories banked, and the
gate-discipline checklist extended with empirical upgrade application
for db/upgrade.php-touching sub-steps. Phase 5a is closed.

The headline trajectory number — 8 round-trips against 5-10 predicted —
is real but should not be the headline lesson. The headline lessons
are:

1. **Test-context vs production-context divergence is load-bearing
   methodology** for any phase that touches Moodle subsystem APIs
   (Grade API, Privacy API, Completion API, backup/restore, etc.).
   The test environment doesn't replicate production's invariants
   faithfully; tests can be green for code that's broken in
   production.

2. **Gate-discipline checklists evolve over phases** as new failure
   modes are discovered. Phase 4.6.5 added parallel-surface
   comparison; Phase 5a.5-fix-forward added empirical upgrade
   application; Phase 5b will likely add another. The checklist is a
   phase-evolving artifact, and naming the discipline at kickoff
   time prevents fix-forwards driven by forgotten disciplines.

3. **The pattern bank from prior phases compounds positively** even
   when the new phase is calibration-tax-heavy. Helper-establishment,
   evidence-grounded testing, write-time conventions, in-plugin
   reference tests, and read-the-file kickoff discipline all carried
   over and made Phase 5a's per-sub-step pace match Phase 4's despite
   the genuinely-new conceptual surface.

None of these are Phase 5a-specific. All of them apply to Phase 5b
kickoff drafting immediately.

Phase 5b calibration-tax estimate stands at 5–7 round-trips honest,
4–10 range, with predictions explicitly assuming
calibration-risk inflation rather than extrapolating from Phase 5a's
pace. Six durable reflex memories carry forward as methodology
assets. The gate-discipline checklist is now a multi-phase
accumulation; Phase 5b kickoff should name all accumulated
disciplines explicitly.

Next operations after this retrospective lands and ships:
- Scheduled audit at 2026-05-11 (one-time agent
  `phase5a-grade-api-audit`, trigger ID
  `trig_01EvpeCDVFec5y48eZmheV7s`) re-grepping the LMS Light plugin
  tree for grade_update / grade_get_grades callsites with potential
  upgrade-context exposure. Catches the new reflex regressing in
  adjacent plugin work.
- Phase 5b kickoff drafting, with explicit calibration-risk framing,
  the read-the-file pre-flight discipline applied from kickoff
  drafting forward, all accumulated gate-disciplines named at
  kickoff time, and the test-context-vs-production-context divergence
  meta-pattern named as load-bearing for privacy provider work.

Phase 5a closed at v0.5.0. Pattern bank carries forward.
