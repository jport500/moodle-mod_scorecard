# Renderer refactor retrospective — mod_scorecard

Internal archaeological record of the renderer Mustache migration (commits
`3001a83` + `6be1dbf`) and the Part 3 cleanup cycle (commits `b813b70` +
`5a5e51a` + this commit) that restored discipline after the migration shipped
without it. Written immediately after the cleanup cycle landed (2026-04-29) so
the lessons are captured while still warm. Not a release note, not
customer-facing — this is for future Claude Code sessions reading project
history to understand what disciplined refactor work looks like and what
undisciplined work costs.

`CHANGES.md` says what shipped. This document says how it shipped, including
the parts that shipped without the discipline mod_scorecard's first six phases
established. The phase retrospectives (`PHASE-{4,5A,5B,6}-RETROSPECTIVE.md`)
are the canonical methodology archive for phase-shaped work; this retrospective
is the methodology archive for an out-of-band refactor that bypassed phase
shape and the audit-trail consequences of that bypass.

Smaller surface than the phase retrospectives — one conceptual sub-step
(renderer Mustache migration) plus its fix-forward and cleanup, not 6
sub-steps of phase work. Methodology insight density matches; archaeology
depth is bounded.

---

## 1. What shipped

The renderer refactor across two commits, plus the cleanup cycle.

**Commit `3001a83` — Refactor: move renderer markup into Mustache templates.**
22 files changed, 962 insertions, 772 deletions. `classes/output/renderer.php`
markup moved from inline `html_writer` chains to Mustache templates under
`templates/`. 20 new template files. `view.php`'s manager-only no-items branch
and `report.php`'s export-button block moved behind new `render_manager_no_items`
and `render_report_export_button` methods so no inline `html_writer` chains
remain in page scripts. Public renderer API unchanged.

**Commit `6be1dbf` — Fix: phpcs violations from 3001a83.** 1 file, 2
insertions, 3 deletions. Two phpcs violations (line 41 blank-line-after-brace
ERROR; line 697 inline-comment-capitalization WARNING) introduced by `3001a83`
and missed at original commit time because phpcs was not run pre-commit.

**The pix_icon carve-out.** Action-link clusters on item and band rows wrap
`pix_icon()` calls — a Moodle output API. Rather than templating the wrapped
output, the cluster pre-renders to HTML in PHP and passes into the row
template as a single `actions` context string. The carve-out emerged during
refactor execution rather than from pre-planned design; the principle was
generalized post-hoc and banked at `docs/MOODLE-TEMPLATING-CONVENTIONS.md`
during Part 3a (commit `b813b70`).

The cleanup cycle — `b813b70` (templating convention), `5a5e51a` (CHANGES.md
Unreleased section), this commit (retrospective) — restored the discipline
that was bypassed at `3001a83`'s ship time.

---

## 2. The methodology divergence

The load-bearing section. mod_scorecard's first six phases established a
discipline that catches what assumption misses. Yesterday's refactor work
bypassed it.

What was missing from the disciplined process:

- **No phase prefix in commit title.** Every refactor-shaped commit since
  Phase 1 has carried a `Phase X.Y:` prefix grounding it in phase progression.
  `Refactor:` with no phase grounding is out-of-band.
- **No version bump.** 962 insertions / 772 deletions across 22 files is
  substantial enough to have warranted a v0.7.1 patch bump under the same
  methodology that bumped `2026042704 → 2026042705` for Phase 6.6's pure-docs
  commit.
- **No CHANGES.md entry at ship time.** Operators upgrading to current main
  got substantial code change with no operator-facing disclosure. Part 3b
  (`5a5e51a`) retroactively addressed this via the `## Unreleased` section.
- **No retrospective at ship time.** This document retroactively addresses it.
- **No kickoff prompt.** The work happened via the `/moodle-refactor` slash
  command rather than scoped-kickoff-with-Q-dispositions discipline. No
  pre-flight, no Q dispositions, no round-trip prediction, no scope
  decomposition.
- **No empirical-bootstrap-state-verification at gate.** No PHPUnit run
  pre-commit. No phpcs run pre-commit. No walkthrough. The commit body
  asserted "existing tests still hold" — that was an unverified claim at
  ship time.
- **The unverified claim was wrong on phpcs.** Two violations shipped to
  origin and were caught only when this session re-ran the gates yesterday's
  session skipped.

What was sound about the work itself:

- **The architectural call on the pix_icon carve-out was right.** The
  principle (templatize author markup; keep Moodle-output-API calls in PHP)
  is genuinely useful and now banked at `docs/MOODLE-TEMPLATING-CONVENTIONS.md`.
- **Template extraction was careful.** 20 templates organized cleanly under
  `templates/`; context-array shape consistent across renderer methods; public
  API preserved end-to-end.
- **Co-Authored-By trailer present.** The session followed the project's
  commit-trailer convention even while bypassing the broader methodology
  discipline.

The asymmetry matters. **The work was sound execution of an undisciplined
process.** Bypassing the discipline didn't produce bad code — it produced
good code that shipped with two missed phpcs violations, no operator
disclosure, no methodology archaeology, and no audit trail beyond the commit
itself. The discipline catches what assumption misses; the bypass let
assumption be tested on origin instead of at gate.

---

## 3. What discipline would have looked like

The original B-output survey from the prior conversation scoped renderer
Mustache migration as a 5-sub-step phase (R0 → R5). The disciplined version
of yesterday's work would have been:

- **R0 — Pattern establishment.** 1 sub-step, ~1 round-trip. Migrate one
  trivial method (recommended: `render_template_export_affordance`, 14 lines,
  no test coverage to migrate, no shared dependencies). Establish directory
  conventions, renderable docblock voice, template variable naming,
  test-architecture pattern.
- **R1 — Affordance trio.** 1 sub-step, ~1 round-trip. Migrate
  `render_template_import_affordance` and `render_manage_affordance` using
  the R0 pattern. After R1, the entire affordance surface is template-driven.
- **R2 — Validation alerts.** 1 sub-step, ~1 round-trip. Migrate
  `render_template_validation_errors` + `render_template_validation_warnings`.
  Banks the "shared template partial" pattern.
- **R3 — Empty states.** 1 sub-step, ~1 round-trip. Migrate three small
  low-complexity methods. First test-architecture migration.
- **R4 — Manage rows + lists.** 2 sub-steps. Heaviest single migration;
  manage-page walkthrough load-bearing. Action-link clusters would have
  surfaced the pix_icon carve-out at R4a as a Q disposition rather than as
  post-hoc discovery.
- **R5 — Result page.** 1 sub-step, ~1 round-trip. Migrate `render_result_page`;
  `result_render_test.php` migrates to renderable-data assertions.

Total prediction: 6-8 round-trips for 6 sub-steps, gate verification at each,
walkthrough at R4 and R5, retrospective at the end.

The actual cost: 1 undisciplined commit + 1 fix-forward commit + 3
retrospective-cycle commits (3a + 3b + 3c) = 5 commits, no test/walkthrough
verification at the original commit, methodology insight discovered post-hoc
rather than at gate.

The disciplined path's cost (6-8 round-trips spread across multiple sessions)
was higher in calendar time but lower in audit-trail cost and missed-violation
risk. The undisciplined path was faster at ship time and slower in cleanup.
The work itself shipped sound; the methodology shipped lossy.

---

## 4. What to do differently next time

Three concrete commitments forward.

**The `/moodle-refactor` skill as currently structured is incompatible with the
project's methodology discipline.** The skill produces single-shot
transformations; the project requires kickoff → Q dispositions → pre-flight →
gate → walkthrough → commit. Either the skill gets restructured to produce
survey-only output (which feeds into properly-scoped multi-sub-step phases
later) or the skill gets explicitly bypassed in favor of manual scoping for
any refactor work. Phase 7+ candidate — backlog item to restructure the skill
so survey output goes through the phase-shaped scoping pipeline rather than
producing direct transformations.

**Refactor-shaped work that touches more than ~100 lines or more than ~3 files
warrants phase-shaped scoping.** Yesterday's refactor was 22 files, 1734 lines
combined diff — clearly above the threshold. Phase-shaped scoping means:
kickoff prompt with Q dispositions, pre-flight verification, multi-sub-step
decomposition where applicable, gate verification at each sub-step, walkthrough
at operator-facing surfaces, retrospective at phase close. The threshold is
judgment-call, not strict — the principle is "if you're tempted to do it in
one commit and it's bigger than a fix-forward, pause and scope first."

**Commit-time gate verification is non-negotiable for code-touching commits.**
PHPUnit + phpcs at minimum, before every commit that changes PHP. The
fix-forward at `6be1dbf` exists because gate verification was skipped at
`3001a83`; the cost of skipping was paid in cleanup work plus the methodology
insight that's now this retrospective. Future code-touching commits run gates
pre-commit, full stop. Worth considering automation via pre-commit hook so
gate-bypass-by-omission becomes structurally impossible.

---

## 5. Banked methodology insights

Two insights crystallized from the renderer refactor cycle. Both worth
preserving for Phase 7+ reference at full per-insight depth, similar to the
Phase 6 retrospective Section 6 catalog format.

### Insight 1 — Empirical demonstration: bypassing gate discipline produces quality regression on origin, not just procedural divergence

**Surfaced at**: yesterday's `/moodle-refactor` work (commit `3001a83`)
shipped with two phpcs violations because phpcs was not run pre-commit. The
violations were caught only when this session's verification re-ran the gates
yesterday's session skipped.

**The insight**: gate-bypass costs are empirical, not theoretical. Yesterday's
work shipped quality regression to origin — operators pulling main between
`3001a83` push and `6be1dbf` push had a working tree with two phpcs violations.
The cost was small (one fix-forward commit) but real, and the
discipline-bypass also produced the no-disclosure / no-archaeology /
no-audit-trail consequences that took three more commits (3a + 3b + 3c) to
address.

**Methodology family**: same as Phase 6 retrospective insight 8
(operator-workflow-mismatch reversal). Discipline catches what assumption
misses, at within-commit grain rather than within-sub-step grain. Both
insights are evidence that the discipline isn't ceremony — it's the mechanism
by which good work stays good across the audit trail.

**Future application**: any code-touching work where there's pressure to skip
gate verification "for speed." The speed savings are illusory once
fix-forward + retrospective costs are accounted for. Run the gates.

### Insight 2 — Forward references between commits in a locked-sequence session are acceptable; forward references across sessions or non-guaranteed sequences are not

**Surfaced at**: Part 3b drafting, where the CHANGES.md Unreleased section's
renderer-refactor entry pointed to `docs/RENDERER-REFACTOR-RETROSPECTIVE.md`
(this document) before this document existed. The brief dangling window
between Part 3b push and Part 3c push was acceptable because the locked Part
3 sequence (3a → 3b → 3c, each its own commit, all in same session)
guaranteed the reference target ships shortly.

**The insight**: forward references are about resolution timeline, not about
reference syntax. A reference that resolves in minutes within a guaranteed
sequence is operationally indistinguishable from a non-forward reference. A
reference whose target ships indefinitely later (or may never ship) is a
dangling reference whether or not the syntax is identical.

**Decision rule**: when commits in a session reference artifacts that ship
later in the same session under a locked sequence, the forward reference is
safe. When commits reference artifacts that may ship across sessions or in
non-guaranteed order, structure the commit to avoid the forward reference
(drop the file path; use generic "documented elsewhere" framing; defer the
cross-reference until the target ships).

**Future application**: any session where retrospective/methodology artifacts
reference each other. Verify the locked-sequence condition before allowing
forward references. Absent the lock, structure to avoid the dangling-reference
window.

---

## 6. Closing

The renderer refactor was good work that shipped with bypassed discipline.
The fix-forward and the Part 3 cleanup cycle restored discipline; the
methodology insights captured here prevent the bypass from becoming silent
default. mod_scorecard at this commit's HEAD carries the renderer refactor,
the fix-forward, the user guide, the templating convention, the CHANGES.md
`## Unreleased` section, and this retrospective.

Phase 7+ inherits the methodology archive at four phase retrospectives plus
this renderer-refactor retrospective, plus the templating-conventions
document, plus the `## Unreleased` CHANGES section pattern. The next phase of
work — whether `/moodle-refactor` skill restructuring, `METHODOLOGY.md`
synthesis, observation-based assessment customer discovery, or Moodle 5.2
compatibility review — has direct precedent for what disciplined work looks
like and what undisciplined work costs.

The discipline isn't ceremony; it's how good work stays good across
audit-trail time.
