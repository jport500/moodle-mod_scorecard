# LMS Light — mod_scorecard Activity Plugin Specification

*Plugin component: mod_scorecard | Target Moodle: 5.1+ | Prepared for LMS Light*

**Spec version: 0.4.2** | Date: 2026-04-27 | Status: Phase 4 closed; Phase 5a grade-method clarification folded in

Purpose: define a reusable Moodle activity module that lets a learner answer scored prompts, calculates a total score, displays a result/interpretation, and optionally writes the score to the gradebook.

**Reading guide for v0.4:** this revision folds in corrections discovered during Phase 1 implementation. Substantive changes since v0.3:

- §4.1: dropped the redundant "Show previous attempt" setting (the behavior is implicit and unconditional — there is no use case for hiding an existing result when retakes are off).
- §4.1, §8.1, §11, §4.5: renamed schema columns `minvalue` → `scalemin` and `maxvalue` → `scalemax` because `MAXVALUE` is reserved in MySQL 8.x and `MINVALUE` is reserved in Moodle's XMLDB cross-DB compatibility list. User-facing form labels ("Minimum scale value", "Maximum scale value") are unchanged.
- §7.1: `requires = 2024100100` confirmed against Moodle 5.1.3 source during Phase 1.
- §8.5: clarified that single-column foreign keys auto-generate an index in Moodle XMLDB; explicit `<INDEX>` declarations are needed only for compound indexes.
- §9.5: added `itemid` to the privacy metadata field list for `scorecard_responses` (graph-traversal link required for export).
- New §12.1: build-time engineering note on hand-written XMLDB and reserved-word avoidance.

*"Decision (v0.2/v0.3):" callouts* in the body flag architectural choices that belong in docs/DECISIONS.md.

# 1. Executive Summary

Build a new Moodle activity plugin called Scorecard. The activity is intended for self-assessments, readiness checks, coaching tools, diagnostics, and similar professional training workflows where each response has a numeric value and the learner receives an immediate total score plus interpretation.

Initial use case: a five-question Career Fit Score where each answer is a numeric value from 1 to 10 and the total score ranges from 5 to 50.

Plugin type: activity module, component mod_scorecard.

MVP focus: clean authoring, clean learner experience, total score calculation, result bands, basic reporting, completion, and optional gradebook integration.

## 1.1 Why a new plugin (build vs. buy)

The following alternatives were evaluated against the scorecard use case (self-assessment with scored prompts, custom result interpretation, professional/coaching aesthetic) and rejected:

**mod_quiz (core).**

- Designed around correct/incorrect answers and per-question grading. The numeric Likert pattern requires building a custom question type, which still inherits quiz UX — review pages, navigation block, attempt summary — that frames the experience as a test rather than a self-assessment.
- No built-in mechanism for score-band → interpretation message at the activity level. The closest core feature, overall feedback, is keyed to grade percentage and not designed for the band/label/message pattern this plugin requires.

**mod_questionnaire (third-party, GPL).**

- Has a Rate (Likert) question type and a Personality Test / Feedback Sections feature that maps score percentages to feedback messages — functionally close to bands.
- Evaluated hands-on and rejected: the authoring UX is awkward for this use case, the learner-facing rendering reads as a survey rather than a scored self-assessment, and the configuration paths needed to assemble a Career-Fit-style instrument are buried under the survey-tooling abstractions. The result is unergonomic for both the teacher building the scorecard and the learner taking it.

**mod_feedback (core).**

- Survey-oriented with a weaker scoring model than mod_questionnaire. Evaluated and rejected for the same UX reasons plus the limited interpretation/feedback display.

**Question-type approach (e.g., a hypothetical qtype_scorecard).**

- Search of the Moodle plugins directory and broader Moodle ecosystem (April 2026) found no existing question type that implements scorecard semantics. Building one from scratch was considered and rejected because a question type still lives inside mod_quiz, inheriting the quiz UX framing this plugin is specifically trying to avoid (see mod_quiz above). A standalone activity module is the right surface area.

> ***Note:** This subsection is required reading for anyone proposing to extend or replace this plugin in the future. Write it once, defensively. The cost of revisiting build-vs-buy mid-implementation is a full rescoping conversation.*

## 1.2 Avoided dependencies

This plugin has no runtime dependency on mod_quiz, mod_questionnaire, mod_feedback, or any third-party plugin. Where Moodle core APIs are sufficient (forms, output, capabilities, gradebook, completion, backup/restore, privacy), they are used directly.

# 2. MVP Scope

| **Area**           | **MVP Requirement**                                                                                                                                  |
|--------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| Activity creation  | Teacher can create a Scorecard activity from the Moodle activity chooser and configure general settings.                                             |
| Items/prompts      | Teacher can add, edit, soft-delete, and reorder scored prompts. Hard delete only allowed before any attempt exists.                                  |
| Reorder UX         | Up/down arrow controls in the manage screen. Drag-and-drop deferred to v1.1.                                                                         |
| Scale              | A shared numeric scale applies to all prompts for MVP, default 1–10.                                                                                 |
| Learner submission | Learner must answer every visible item (all items required in MVP) and submit one attempt.                                                           |
| Score calculation  | Plugin calculates total score, max score (= count × scalemax), and matching result band. Percentage is calculated but hidden by default.             |
| Results screen     | Learner sees "Your score: X out of Y", result band label, interpretation message, and (collapsible) per-item response summary.                       |
| Reporting          | Teacher can view individual attempts, item responses, totals, and export CSV.                                                                        |
| Completion         | Activity completion can be based on submission.                                                                                                      |
| Gradebook          | Optional score passback to Moodle gradebook. Default off.                                                                                            |
| Privacy            | Implement Moodle privacy API because responses are user-specific personal data.                                                                      |
| Per-tenant theming | CSS custom properties (--scorecard-\*) so per-tenant Moodle themes can override colors without modifying the plugin. Pattern follows format_pathway. |

# 3. Non-Goals for MVP

- No branching logic.
- No AI-generated items or result summaries.
- No PDF result export.
- No complex per-question weighting.
- No category/subscore reporting.
- No anonymous survey mode.
- No optional items — all items required in MVP. The schema reserves a `required` field for v1.1 but it is not exposed in the MVP item form.
- No save-draft / resume-later. Learner completes in one sitting. Explicit non-goal because every Moodle user expects autosave and will be surprised by its absence; document this in the manual smoke walkthrough.
- No drag-and-drop reorder. Up/down arrows only.
- No dependency on mod_quiz, mod_questionnaire, mod_feedback, or any third-party plugin.

# 4. Functional Requirements

## 4.1 Activity Settings

| **Setting**                 | **Type**        | **Default**                    | **Notes**                                                                                                                                                                                                                                                                                                                                                                       |
|-----------------------------|-----------------|--------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Name                        | text            | —                              | Standard Moodle activity name.                                                                                                                                                                                                                                                                                                                                                  |
| Intro/description           | editor          | —                              | Standard intro editor with display-on-course-page option.                                                                                                                                                                                                                                                                                                                       |
| Minimum scale value         | integer         | 1                              | Form label only. Backed by `scalemin` column. Validate `scalemin < scalemax`. Negatives allowed (e.g., -5..+5 for risk-style scales) but document the implication for percentage.                                                                                                                                                                                               |
| Maximum scale value         | integer         | 10                             | Form label only. Backed by `scalemax` column. Recommended practical maximum: 20.                                                                                                                                                                                                                                                                                                |
| Scale display style         | select          | Radio buttons                  | MVP: radio buttons only. Schema accepts 'buttons', 'dropdown', 'slider' as reserved values for v1.1 but the setting is not exposed in mod_form.php. Decision: avoid shipping any of the alternatives until each can be done well — buttons need different mobile tap-target handling, dropdown breaks the per-item-anchor pattern, slider is a non-trivial accessibility build. |
| Low anchor label            | text            | Low                            | Optional global label for minimum end of scale. Item-level labels override.                                                                                                                                                                                                                                                                                                     |
| High anchor label           | text            | High                           | Optional global label for maximum end of scale. Item-level labels override.                                                                                                                                                                                                                                                                                                     |
| Allow retakes               | boolean         | No                             | If yes, learners can create multiple attempts. All attempts retained (see §11).                                                                                                                                                                                                                                                                                                 |
| Show result immediately     | boolean         | Yes                            | If no, submission confirmation only.                                                                                                                                                                                                                                                                                                                                            |
| Show percentage on result   | boolean         | No                             | Off by default. Percentage on bounded scales is misleading because the minimum possible score is rarely zero. See §11.                                                                                                                                                                                                                                                          |
| Show item summary on result | boolean         | Yes                            | Collapsible per-item response display under the band message.                                                                                                                                                                                                                                                                                                                   |
| Gradebook enabled           | boolean         | No                             | If enabled, write total score to gradebook.                                                                                                                                                                                                                                                                                                                                     |
| Grade max                   | integer/derived | Derived                        | Default = visible item count × max scale value, recomputed on save when items change before first attempt.                                                                                                                                                                                                                                                                      |
| Fallback message            | editor          | Pre-populated from lang string | Shown when no band matches the total score (gap in band coverage). Per-instance setting; see §4.3 for band coverage validation. The teacher form pre-populates this field with the English default at activity-creation time so the teacher sees and can edit it; the plugin does not silently fall back to the lang string at render time.                                     |

> **Removed in v0.4:** The "Show previous attempt" setting from v0.3 was dropped. The behavior — when retakes are off and an attempt exists, display the existing result — is implicit and unconditional. There is no use case for "retakes off, attempt exists, hide the existing result and prevent resubmission" (a black-hole UX). The schema correctly omits a `showpreviousattempt` column.

### 4.1.1 Default fallback message

The English default ships in lang/en/scorecard.php as the string `fallbackmessage_default`:

> "Your score is outside the configured result ranges."
>
> "Please contact your facilitator if you have questions about your result."

Word choice rationale: "facilitator" rather than "teacher" because most LMS Light scorecards run in coaching, training, or onboarding contexts rather than classrooms. Operators who prefer different wording override per-instance via the form field they are already given.

> **Decision (v0.2):** Pre-populate the form field at activity creation rather than falling back to the lang string at render time. Render-time fallback is invisible to the teacher during authoring; pre-population makes the default editable and visible from the first save.

## 4.2 Scorecard Items

| **Field**                 | **Type**    | **Required** | **Notes**                                                                                                                      |
|---------------------------|-------------|--------------|--------------------------------------------------------------------------------------------------------------------------------|
| scorecardid               | foreign key | Yes          | Parent activity instance.                                                                                                      |
| prompt                    | editor/text | Yes          | Main scored prompt. Stored with promptformat.                                                                                  |
| lowlabel                  | text        | No           | Item-specific minimum anchor. If set, overrides activity-level lowlabel for this item.                                         |
| highlabel                 | text        | No           | Item-specific maximum anchor. If set, overrides activity-level highlabel for this item.                                        |
| required                  | boolean     | Reserved     | Stored in schema for v1.1. Hidden from MVP item form; defaults to 1. All items effectively required in MVP.                    |
| sortorder                 | integer     | Yes          | Display order. Modified by up/down arrow controls.                                                                             |
| visible                   | boolean     | Yes          | Allows draft/hide of an item. Default 1.                                                                                       |
| deleted                   | boolean     | Yes          | Soft-delete flag. Set to 1 instead of removing the row when an attempt has referenced this item. See §4.5 for lifecycle rules. |
| timecreated, timemodified | bigint      | Yes          | Unix timestamps.                                                                                                               |

## 4.3 Result Bands

| **Field**   | **Type**    | **Required** | **Notes**                                                                                                           |
|-------------|-------------|--------------|---------------------------------------------------------------------------------------------------------------------|
| scorecardid | foreign key | Yes          | Parent activity instance.                                                                                           |
| minscore    | integer     | Yes          | Inclusive lower bound.                                                                                              |
| maxscore    | integer     | Yes          | Inclusive upper bound.                                                                                              |
| label       | text        | Yes          | Short result label (e.g., "Strong", "Red flag").                                                                    |
| message     | editor      | No           | Learner-facing interpretation. Stored with messageformat.                                                           |
| sortorder   | integer     | Yes          | Display/admin order.                                                                                                |
| deleted     | boolean     | Yes          | Soft-delete flag. Same lifecycle rule as items: set to 1 once any attempt has matched this band; never hard-delete. |

### Band coverage validation

On save of the band-management screen, the plugin computes the union of all band ranges and compares to the theoretical score range (count of items × scalemin) to (count of items × scalemax).

- Overlapping bands: hard error. Block save with a clear message naming the overlapping bands.
- Gaps in coverage: warning, not error. Display the uncovered ranges so the teacher can decide whether to extend a band or rely on the per-instance fallback message (§4.1).
- No bands defined: warning. Submission still works; learner sees the fallback message or — if the fallback is empty — "Your score: X out of Y" with no interpretation.

> **Decision (v0.2):** Band fallback is a per-instance setting (not a sitewide default and not a hardcoded language string). This lets each scorecard customize its own out-of-band copy without admin intervention.

## 4.4 Learner Flow

1.  Learner opens the Scorecard activity.
2.  Plugin checks capability mod/scorecard:submit and whether the user already has an attempt.
3.  If no attempt exists (or retakes are enabled), display all visible non-deleted items in sortorder.
4.  Learner selects a numeric response for each item.
5.  On submit, validate sesskey, course module visibility, capability, all-items-answered, and numeric bounds.
6.  Create attempt and response records in a single database transaction.
7.  Calculate total score, max score, percentage, and matching result band.
8.  Snapshot the matched band's label and message onto the attempt row.
9.  If gradebook is enabled, write totalscore as the raw grade.
10. Display the result screen (or submission confirmation if showresult is off).

If retakes are off and the user already has an attempt, the existing result is displayed (no re-submission allowed).

## 4.5 Teacher / Admin Flow

1.  Create Scorecard activity from the activity chooser.
2.  Configure general settings on mod_form.php.
3.  After saving, teacher lands on the Manage screen if no items exist (single tabbed page: Items | Bands | Reports).
4.  Teacher adds prompts and optional low/high anchors. Reorders with up/down arrows.
5.  Teacher defines result bands. Save validates coverage (§4.3).
6.  Teacher previews learner view (read-only render with no submission action).
7.  Teacher reviews submissions from the Reports tab and exports CSV.

### Item and band lifecycle after first attempt

Once any attempt has been submitted against a scorecard, the following constraints apply:

- Items: editing prompt text and anchor labels remains allowed (historical attempts retain their snapshot of which item was answered, by itemid). Deleting an item soft-deletes (sets deleted=1, hides from learner view, retains for historical reporting). Hard delete is blocked.
- Bands: editing label or message is allowed but does not affect historical attempts (which carry their own label/message snapshot). Deleting soft-deletes for the same reason.
- Changing scalemin or scalemax: blocked once any attempt exists, because re-scoring history is ambiguous. Teacher must duplicate the activity instead.
- Adding new items: allowed but flagged with a warning that historical attempts will not include the new item and their max score will look lower than current attempts'.

> **Decision (v0.2):** Soft-delete (rather than locking) was chosen so teachers can iterate on prompts without being penalized for early experimentation. The historical-fidelity cost is mitigated by attempt-side snapshots (see §8.4).

# 5. User Stories and Acceptance Criteria

| **User Story**                                                         | **Acceptance Criteria**                                                                                                                 |
|------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| As a teacher, I can create a Scorecard activity.                       | Activity appears in chooser, creates a course module, and stores settings correctly.                                                    |
| As a teacher, I can add five scored prompts.                           | Items are saved, ordered, editable, soft-deletable, and displayed in learner view in sortorder.                                         |
| As a teacher, I get a clear warning if my bands have gaps or overlaps. | Save is blocked on overlap with named offending bands. Save proceeds with a non-blocking warning on gaps, naming the uncovered ranges.  |
| As a learner, I can answer each item from 1 to 10.                     | Each item has one selected radio value; missing values block submission with errors anchored next to the offending items.               |
| As a learner, I see my total score after submission.                   | Result page displays "Your score: 37 out of 50." Percentage hidden unless explicitly enabled in settings.                               |
| As a learner, I see an interpretation.                                 | If a result band matches the total, the band label and message display. If no band matches, the per-instance fallback message displays. |
| As a learner, I can review my answers on the result page.              | Item summary is shown collapsed by default; expanding shows each prompt and the selected value with the relevant anchors.               |
| As a teacher, I can view attempts.                                     | Report lists learner, attempt number, time submitted, total score, max score, and band label (snapshot).                                |
| As a teacher, I can export results.                                    | CSV includes learner identifiers, total score, max score, percentage, band label snapshot, and per-item responses.                      |
| As a site admin, data privacy requirements are met.                    | Privacy provider identifies, exports, and deletes user attempt and response data for the activity context.                              |
| As a site admin, the activity respects per-tenant theme colors.        | Result band styling reads from --scorecard-\* CSS custom properties; no hardcoded brand colors in the renderer output.                  |

# 6. Reference Example: Career Fit Score

This example is the primary QA seed data for MVP testing.

| **#** | **Prompt**                                                                    | **Low Anchor**                               | **High Anchor**                            |
|-------|-------------------------------------------------------------------------------|----------------------------------------------|--------------------------------------------|
| 1     | How satisfied are you with the work you currently do day-to-day?              | I dread Mondays.                             | I would do this even if I won the lottery. |
| 2     | How well does your current role use your strengths?                           | I am using almost none of what I am good at. | I get to bring my best self every day.     |
| 3     | How fairly are you compensated for the value you create?                      | Significantly underpaid.                     | Compensated at or above my market value.   |
| 4     | How clear are you on what you want your career to look like 5 years from now? | No idea.                                     | Crystal clear vision.                      |
| 5     | How much control do you feel you have over the direction of your career?      | I am at the mercy of forces beyond me.       | I am in the driver seat.                   |

Bands:

| **Min** | **Max** | **Label**  | **Message**                                                    |
|---------|---------|------------|----------------------------------------------------------------|
| 5       | 15      | Red flag   | Your current career situation may be significantly misaligned. |
| 16      | 25      | Concerning | There are signs of friction or uncertainty worth examining.    |
| 26      | 35      | Mixed      | You have some strengths, but also meaningful gaps.             |
| 36      | 45      | Strong     | You appear to be in a strong career position.                  |
| 46      | 50      | Excellent  | You are highly aligned and in control.                         |

> ***Note:** These bands cover the full theoretical range 5–50 with no gaps or overlaps. Any band edits during MVP development should preserve this property to avoid confusing test results.*

**Recommended second test fixture (v0.2):** a 10-item readiness check on a 1–5 scale with three bands (range 10–50, bands 10–20 / 21–35 / 36–50). Stress-tests that the plugin is not accidentally hardcoded to the 5×10 case, and that band coverage validation works on a different shape.

# 7. Technical Architecture

## 7.1 Plugin Type and Component

- Plugin type: Moodle activity module.
- Component name: mod_scorecard.
- Directory in the LMS Light docroot: public/mod/scorecard/.
- Target Moodle version: 5.1 and above. `requires = 2024100100` (verified against Moodle 5.1.3 source during Phase 1).
- Use Moodle core APIs for forms, output rendering, capabilities, completion, gradebook, backup/restore, and privacy.

> ***Note:** Per LMS Light convention (CONTEXT.md): every referenced framework API — events, classes, capabilities, config keys — must be grep-verified against the target Moodle source before being relied upon in the spec or implementation.*

## 7.2 Suggested File Structure

```
public/mod/scorecard/
  version.php
  lib.php
  locallib.php
  mod_form.php
  view.php
  submit.php
  manage.php          # Items + Bands tabs
  report.php
  export.php
  index.php
  styles.css          # Defines --scorecard-* CSS custom properties
  db/
    access.php
    install.xml
    upgrade.php
  classes/
    form/item_form.php
    form/band_form.php
    output/renderer.php
    output/view_page.php
    output/result_page.php
    output/report_page.php
    privacy/provider.php
    external.php       # Optional/future web services
  lang/en/scorecard.php
  backup/moodle2/
    backup_scorecard_activity_task.class.php
    backup_scorecard_stepslib.php
    restore_scorecard_activity_task.class.php
    restore_scorecard_stepslib.php
  pix/icon.svg
  tests/
    lib_test.php
    db_install_test.php
    scoring_test.php
    band_matching_test.php
    privacy_provider_test.php
```

# 8. Database Schema

Table names use Moodle conventions with prefix `{scorecard...}`. Exact XMLDB definitions belong in db/install.xml.

| **Table**           | **Purpose**                                                                       |
|---------------------|-----------------------------------------------------------------------------------|
| scorecard           | Main activity instance settings.                                                  |
| scorecard_items     | Scored prompts/questions.                                                         |
| scorecard_bands     | Score interpretation bands.                                                       |
| scorecard_attempts  | One row per learner submission attempt, with snapshots of band label and message. |
| scorecard_responses | One row per item response within an attempt.                                      |

## 8.1 {scorecard}

| **Column**            | **Type**     | **Notes**                                                                                                          |
|-----------------------|--------------|--------------------------------------------------------------------------------------------------------------------|
| id                    | bigint PK    |                                                                                                                    |
| course                | bigint       | Course id.                                                                                                         |
| name                  | varchar(255) | Activity name.                                                                                                     |
| intro                 | text         | Standard module intro.                                                                                             |
| introformat           | int          | Moodle format constant.                                                                                            |
| scalemin              | int          | Default 1. Renamed from `minvalue` in v0.4 because `MINVALUE` is on Moodle's XMLDB cross-DB reserved-word list.    |
| scalemax              | int          | Default 10. Renamed from `maxvalue` in v0.4 because `MAXVALUE` is reserved in MySQL 8.x (PARTITION syntax).        |
| displaystyle          | varchar(32)  | MVP: 'radio'. Reserved values: 'buttons', 'dropdown', 'slider'.                                                    |
| lowlabel              | varchar(255) | Global low anchor.                                                                                                 |
| highlabel             | varchar(255) | Global high anchor.                                                                                                |
| allowretakes          | tinyint      | 0/1.                                                                                                               |
| showresult            | tinyint      | 0/1.                                                                                                               |
| showpercentage        | tinyint      | 0/1. Default 0 — see §11.                                                                                          |
| showitemsummary       | tinyint      | 0/1. Default 1.                                                                                                    |
| fallbackmessage       | text         | Shown when no band matches.                                                                                        |
| fallbackmessageformat | int          | Moodle format constant.                                                                                            |
| gradeenabled          | tinyint      | 0/1.                                                                                                               |
| grade                 | int          | Max grade when gradebook enabled.                                                                                  |
| timecreated           | bigint       | Unix timestamp.                                                                                                    |
| timemodified          | bigint       | Unix timestamp.                                                                                                    |

## 8.2 {scorecard_items}

| **Column**   | **Type**     | **Notes**                                              |
|--------------|--------------|--------------------------------------------------------|
| id           | bigint PK    |                                                        |
| scorecardid  | bigint FK    | References scorecard.id.                               |
| prompt       | text         | Item prompt.                                           |
| promptformat | int          | Moodle format constant.                                |
| lowlabel     | varchar(255) | Optional item low anchor.                              |
| highlabel    | varchar(255) | Optional item high anchor.                             |
| required     | tinyint      | Reserved for v1.1. Default 1; not exposed in MVP form. |
| visible      | tinyint      | Default 1. Allows draft/hide.                          |
| deleted      | tinyint      | Default 0. Soft-delete flag (see §4.5).                |
| sortorder    | int          | Display order.                                         |
| timecreated  | bigint       |                                                        |
| timemodified | bigint       |                                                        |

**Index:** (scorecardid, sortorder) — compound, for the manage and learner-view queries.

## 8.3 {scorecard_bands}

| **Column**    | **Type**     | **Notes**                               |
|---------------|--------------|-----------------------------------------|
| id            | bigint PK    |                                         |
| scorecardid   | bigint FK    | References scorecard.id.                |
| minscore      | int          | Inclusive lower bound.                  |
| maxscore      | int          | Inclusive upper bound.                  |
| label         | varchar(255) | Band label.                             |
| message       | text         | Result interpretation.                  |
| messageformat | int          | Moodle format constant.                 |
| sortorder     | int          | Display/admin order.                    |
| deleted       | tinyint      | Default 0. Soft-delete flag (see §4.5). |
| timecreated   | bigint       |                                         |
| timemodified  | bigint       |                                         |

**Index:** (scorecardid, minscore) — compound, for band-matching queries.

## 8.4 {scorecard_attempts}

| **Column**                | **Type**              | **Notes**                                                                                                                                       |
|---------------------------|-----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| id                        | bigint PK             |                                                                                                                                                 |
| scorecardid               | bigint FK             | References scorecard.id.                                                                                                                        |
| userid                    | bigint FK             | User who submitted.                                                                                                                             |
| attemptnumber             | int                   | Starts at 1, increments per user. All attempts retained even when retakes are later disabled.                                                   |
| totalscore                | int                   | Sum of responses for this attempt.                                                                                                              |
| maxscore                  | int                   | Snapshot: count of visible non-deleted items × scalemax at submission time. Use this for the result-screen denominator, not a recomputed value. |
| percentage                | decimal(5,2)          | Stored at submission. Display gated by scorecard.showpercentage.                                                                                |
| bandid                    | bigint FK nullable    | Soft reference to the matched band. May point to a soft-deleted band.                                                                           |
| bandlabelsnapshot         | varchar(255) nullable | Band label at the time of submission. Display this on historical result screens, not the live band's current label.                             |
| bandmessagesnapshot       | text nullable         | Band message at the time of submission.                                                                                                         |
| bandmessageformatsnapshot | int nullable          | Format constant for the snapshotted message.                                                                                                    |
| timecreated               | bigint                | Submission time.                                                                                                                                |
| timemodified              | bigint                |                                                                                                                                                 |

**Indices:** (scorecardid, userid) for the "does this user have an attempt?" lookup, and (scorecardid, timecreated) for the report sort. Both compound.

> **Decision (v0.2):** Band label and message are snapshotted onto the attempt row to keep historical results stable when teachers edit or soft-delete bands. The bandid foreign key is retained as a soft reference for reporting joins (e.g., "group attempts by band") but never relied upon for display.

## 8.5 {scorecard_responses}

| **Column**    | **Type**  | **Notes**                                                              |
|---------------|-----------|------------------------------------------------------------------------|
| id            | bigint PK |                                                                        |
| attemptid     | bigint FK | References scorecard_attempts.id.                                      |
| itemid        | bigint FK | References scorecard_items.id. May point to a soft-deleted item.       |
| responsevalue | int       | Selected numeric response.                                             |
| timecreated   | bigint    | Unix timestamp. Responses are immutable once written; no timemodified. |

**Indexing note (v0.4):** Single-column foreign keys auto-generate an index in Moodle XMLDB; no explicit `<INDEX>` element is needed for them. Both `attemptid` and `itemid` are queryable via their FK-derived indexes. Explicit `<INDEX>` declarations should only be added when a different access pattern needs to be optimized — typically a compound index covering multiple columns. (See §12.1 for the build-time discovery that produced this clarification.)

# 9. Moodle Integration Requirements

## 9.1 Capabilities

| **Capability**            | **Default Roles**                      | **Purpose**               |
|---------------------------|----------------------------------------|---------------------------|
| mod/scorecard:addinstance | manager, editingteacher                | Create activity instance. |
| mod/scorecard:view        | student, teacher, editingteacher, manager | View activity.         |
| mod/scorecard:submit      | student                                | Submit response.          |
| mod/scorecard:manage      | editingteacher, manager                | Manage items and bands.   |
| mod/scorecard:viewreports | teacher, editingteacher, manager       | View reports.             |
| mod/scorecard:export      | teacher, editingteacher, manager       | Export CSV.               |

> **Decision (v0.4.1):** Two corrections to this table reflecting Moodle role-capability mechanics rather than natural-English assumptions:
>
> 1. The `:view` row originally listed `teacher` only, intending the natural-English meaning ("anyone teacherly"). Moodle's archetype taxonomy uses `teacher` for the *non-editing* teacher role only; the default "Teacher" role uses the `editingteacher` archetype. Without `editingteacher` on `:view`, the default Teacher role could not see the activity card in the course outline despite holding `:manage`, `:viewreports`, and `:export` — a circular state where the role had every authoring capability except the foundational one. Corrected the row to include `editingteacher`.
>
> 2. The `:addinstance` row originally listed `coursecreator` alongside `manager` and `editingteacher`. Moodle's `clonepermissionsfrom` pattern (used for activity-module `:addinstance` caps; canonical in mod_quiz, mod_assign, mod_forum) drives actual propagation from `moodle/course:manageactivities`, which the `coursecreator` role does not hold by default. The archetypes list is documentation-only when `clonepermissionsfrom` is set. Coursecreator was therefore never actually granted `:addinstance` in any deployment. Removed from the row to match reality and canonical convention.
>
> Both corrections match the pattern every other authoring capability in this table already follows.

## 9.2 Gradebook

- Implement grade_update logic in lib.php.
- Default gradebook integration is disabled because most scorecards are self-assessments rather than graded tests.
- When enabled, grade max defaults to visible item count × scalemax, recalculated on item add/remove only while no attempts exist.
- On attempt submission, write totalscore as the raw grade (not percentage).
- Grade method: each submission overwrites the gradebook value with the attempt's totalscore (highest/first/average grade methods deferred to v1.1+).

> **Decision (v0.4.2):** Phase 5a kickoff surfaced that §9.2 was silent on grade method. Latest-attempt-overwrites is the implicit reading of the existing "On attempt submission, write totalscore" directive; making it explicit eliminates the ambiguity for future maintainers and removes the need for a grade-method dropdown in mod_form. Highest/first/average grade methods remain v1.1+ scope.

## 9.3 Completion

- Support Moodle activity completion.
- MVP completion rule: complete when learner submits at least one attempt.
- Future completion rule (v1.1+): complete when score is at or above a threshold.

## 9.4 Backup and Restore

- Backup activity settings, items (including soft-deleted ones, to preserve historical reporting), and result bands (likewise).
- Backup user attempts and responses when user data is included.
- Restore mappings for item ids and band ids; preserve attempt-side snapshots verbatim (do not regenerate from current bands).

## 9.5 Privacy API

- Implement classes/privacy/provider.php with metadata declaring user attempts and responses.
- Metadata field declarations:
  - `scorecard_attempts`: `userid`, `totalscore`, `maxscore`, `percentage`, `bandlabelsnapshot`, `bandmessagesnapshot`, `timecreated`. (Note: `bandid` is a soft FK to a non-personal table and is not declared. `bandmessageformatsnapshot` is a format constant, not user-derived.)
  - `scorecard_responses`: `attemptid`, `itemid`, `responsevalue`, `timecreated`. **Both `attemptid` and `itemid` are graph-traversal links required for export — without `itemid`, an exported response value is meaningless because the user cannot tell which prompt it answered.** (Corrected in v0.4: `itemid` was missing from v0.3.)
- Support export of user data for a given context: include the prompt text from the linked item (current value, with a note if the item is soft-deleted), the response value, and the attempt's snapshotted band label and message.
- Support deletion of user data for a context and for selected users: delete attempts and responses; do not delete items, bands, or the scorecard activity itself.
- Edge case: a user re-attempts after their prior data was deleted. The plugin treats this as a fresh attempt sequence (attemptnumber resets to 1).
- Cross-tenant note: per LMS Light architecture (CONTEXT.md), no cross-tenant data sharing exists or is anticipated. The privacy provider operates strictly within the per-tenant Moodle instance.

## 9.6 Templates (JSON export and import)

Phase 6 promoted "Template import/export" from §14 v1.1 roadmap to current scope. This section canonizes the format and import semantics introduced at v0.7.0.

- **Scope:** templates capture authoring structure only — items, bands, and activity settings. User data (attempts and responses) is out of scope; backup/restore (§9.4) is the path for that.
- **Import target at v0.7.0:** create-new only. Importing a template instantiates a new scorecard activity in the destination course; the import flow never modifies an existing scorecard. Overwrite (replace items + bands of an existing scorecard) and append (add items + bands alongside existing) are deferred to v0.8+ if operator demand surfaces.
- **Soft-delete exclusion:** rows with `deleted=1` (items or bands) are excluded from export. Templates represent the operator's current intended authoring structure, not historical state. This is the structural distinction from §9.4 backup/restore semantics, which preserve soft-deletes so historical attempts can resolve their original prompt and label text post-restore — templates ship no attempts and so do not require historical resolvability.
- **Format:** JSON, UTF-8 encoded. Top-level envelope with six fields:
  - `schema_version` — string. `"1.0"` at v0.7.0. Producers stamp; consumers validate and refuse on unrecognized version.
  - `plugin` — object: `{ "name": "mod_scorecard", "version": "<plugin release string>" }`. Forensic provenance. Validation may warn on cross-version mismatch but does not block at v1.0.
  - `exported_at` — ISO 8601 UTC string (e.g., `"2026-04-28T14:30:00Z"`). Operator-facing audit metadata; the JSON envelope is the boundary at which timestamps shift from Moodle internal Unix integers to a human-readable representation.
  - `scorecard` — settings object. Whitelisted fields from §8.1 excluding `id`, `course`, `timecreated`, `timemodified`. Includes `intro` + `introformat`, `displaystyle` (locked to `radio` per §4.1; round-trip preserved for forward-compat with v1.1 alternates), and `completionsubmit`.
  - `items` — array of item objects. Whitelisted fields from §8.2 excluding `id`, `scorecardid`, `timecreated`, `timemodified`. Soft-deleted rows excluded; `sortorder` preserved so authoring order round-trips.
  - `bands` — array of band objects. Whitelisted fields from §8.3 with the same exclusions. Soft-deleted rows excluded.
- **Intro field round-trip:** imported templates round-trip the `intro` field from the source scorecard. Operators may edit the intro post-import to course-customize content; no import-time UI for intro suppression at v0.7.0 (defer to v0.8+ if operator demand surfaces).
- **Filename convention** (export download): `<scorecard-name-slugified>-template.json` via `clean_filename(format_string($scorecard->name))`. Operator-meaningful filename matching the convention §10.4 already uses for the report CSV export.
- **Capabilities:** export gated on `mod/scorecard:manage` (template authoring is an author-side affordance, paralleling the items/bands manage screen). Import capability disposition lands at sub-step 6.5; not specified at this directive.

> **Decision (v0.5):** Templates ship as create-new-only at v0.7.0 with JSON envelope versioning via top-level `schema_version`, producer fingerprint via nested `plugin` object, and ISO 8601 `exported_at`. Soft-deleted authoring rows excluded from export — distinct from §9.4 backup/restore semantics. Intro round-trip-by-default; operator customizes post-import. Overwrite and append import modes, intro-suppression UI, and a database-backed in-platform template library are deferred to v0.8+ pending operator-demand evidence. The §14 v1.1 roadmap row "Template import/export" was removed at this bump (feature shipped).

# 10. User Interface Requirements

## 10.1 Learner View

- Display activity title and intro.
- Display prompts in a clean vertical layout, one per fieldset.
- Each item: `<fieldset>` with the prompt as `<legend>`, low anchor visually associated with the leftmost radio, high anchor with the rightmost radio. Per-item anchors override activity-level anchors when set.
- Numeric scale rendered as radio buttons from scalemin to scalemax. WCAG 2.1 AA conformance is the target; consult the Moodle accessibility guidelines.
- On mobile, anchors stack above and below the radio row rather than left-right.
- Show validation errors anchored next to the offending fieldset, not summarized at the top.
- Submit button text: "Submit scorecard".

## 10.2 Result View

- Headline: "Your score: X out of Y."
- Optional percentage line, hidden by default; shown only when scorecard.showpercentage = 1.
- Result band label and message (from attempt snapshot, not live band).
- Item summary: collapsed by default, controlled by scorecard.showitemsummary. Each row shows the prompt, the selected value, and the relevant anchors.
- Band visual treatment uses --scorecard-band-\* CSS custom properties so per-tenant themes can color-code bands without modifying plugin templates.
- If retakes are enabled, show a "Start new attempt" button.
- If retakes are disabled and an attempt exists, show the existing result and prevent duplicate submission.

## 10.3 Teacher Manage View

Single page, three tabs: Items | Bands | Reports. All admin pages register under one category in the admin tree (no sibling top-level entries — see LESSONS.md on admin nav consolidation).

- Items tab: list with prompt, anchors, sortorder, visible state (read-only display in the list), edit/delete (soft) actions, up/down reorder arrows, and an "Add item" button. Visibility is toggled inside the item form, not via a quick-edit icon in the list — quick-edit deferred to v1.1 (it requires AJAX endpoint, CSRF flow, optimistic UI, and screen-reader announcement patterns that are not justified by MVP usage frequency).
- Bands tab: list with min, max, label, message preview, sortorder, edit/delete (soft) actions, and an "Add band" button. Save action validates coverage (§4.3).
- Reports tab: described in §10.4.
- Preview learner view: link/button at top of the Items tab; opens the learner render in read-only mode (submission disabled).

## 10.4 Teacher Report View

- Table columns: learner name, email/username (subject to identity policy), attempt number, submitted date, total score, max score, percentage (always shown in reports regardless of scorecard.showpercentage), band label snapshot.
- Expandable detail row showing per-item responses (prompts and values), with a "(deleted item)" marker for soft-deleted items.
- CSV export button at the top of the table. Identity columns follow Moodle's standard pattern: respect `$CFG->showuseridentity` for the optional identity fields (email, idnumber, department, institution, custom profile fields), and always include userid and username regardless of site policy because downstream operators need them as join keys. Implementation: use `\core_user\fields::for_identity($context)` to get the field list, iterate per row.
- Respect Moodle group mode where applicable: when the course has groups enabled, render the standard Moodle group selector at the top of the report and filter the existing report query by the selected group. Use `groups_get_activity_group()` and `groups_get_activity_allowed_groups()` with the standard mod/scorecard:viewreports + accessallgroups capability checks for cross-group visibility. Out of scope for MVP: per-group score aggregation, group-level CSV exports, or any analytical comparison across groups — these are pure filter behaviors only.

### Per-tenant theming hook

styles.css defines a set of CSS custom properties consumed throughout the plugin templates. Tenant themes override these without modifying mod_scorecard:

- `--scorecard-band-bg`, `--scorecard-band-fg`, `--scorecard-band-accent` — base band card colors.
- `--scorecard-radio-active`, `--scorecard-radio-inactive` — selected/unselected radio styling.
- `--scorecard-anchor-fg` — anchor label color.
- `--scorecard-result-headline-fg` — result-screen score headline color.

### CSS variable defaults

Every `--scorecard-*` variable ships with a hard default in styles.css designed to render reasonably on Moodle Boost out of the box. The defaults do not reference Boost SCSS variables, for two reasons: (a) Boost variable names shift between Moodle versions and chasing them is unjustified maintenance overhead; (b) tenants that inherit from non-Boost themes would silently break if defaults pointed at Boost-specific variables.

Palette guidance for the defaults: a neutral grey base for cards and text, a single accent color for the result headline, and a small set of band-tier colors that avoid traffic-light semantics (no red/yellow/green) since bands may be neutrally labeled. Document the variable names, intended use, and override mechanism in README.md so a tenant theming engineer can find them without reading the plugin's stylesheet.

> **Decision (v0.2):** Hard defaults in styles.css rather than @extend / inheritance from Boost. Tenants that want brand alignment override the --scorecard-\* values directly in their theme's SCSS — the documented and only extension point. Pattern follows format_pathway.

# 11. Scoring Logic

Pseudocode:

```
visibleitems = items where scorecardid = X and visible = 1 and deleted = 0
totalscore   = sum(responsevalue for each visibleitem at the time of attempt)
maxscore     = count(visibleitems) × scorecard.scalemax
minpossible  = count(visibleitems) × scorecard.scalemin
percentage   = totalscore / maxscore × 100   # stored, hidden by default
band         = first non-deleted band where minscore ≤ totalscore ≤ maxscore
if band is null: snapshot fallbackmessage onto attempt; bandid stays null
else: snapshot band.label + band.message + band.messageformat onto attempt
```

## 11.1 Percentage convention (decision)

> **Decision (v0.2):** Percentage is computed as totalscore / maxscore × 100 and stored on the attempt, but hidden from the learner result screen by default. Reports always show it. Rationale: on a bounded scale (e.g., 1–10), the minimum possible score is rarely zero; a learner who answers all 1s on a 5-item 1–10 scorecard scores 5/50 = 10%, which reads as a near-failure when in fact they hit the floor of the scale. Showing "37 out of 50" avoids the misinterpretation. A scorecard author who genuinely wants percentage shown can opt in via scorecard.showpercentage.

Implication for the data model: percentage is an upper-bound metric (totalscore / maxscore), not a normalized 0–100 value. If a future feature wants a normalized version, add a separate normalizedpercentage column rather than overloading the existing one.

## 11.2 Historical attempt scoring is frozen

When displaying or exporting a historical attempt, use the attempt row's stored totalscore, maxscore, and snapshotted band — never recompute from current items or bands. The scoring engine recomputes only at submission time.

## 11.3 Future scoring features (not in MVP)

Reverse scoring, per-item weighting, categories/subscores, and N/A responses are deferred. The MVP schema is forward-compatible: per-item weight could be added as scorecard_items.weight (default 1.0) without migration of existing data, and reverse scoring as scorecard_items.reverse (default 0).

# 12. Validation and Security

- Require login and course module access checks on all pages.
- Use require_sesskey() on submit and management actions.
- Use capability checks for submit, manage, viewreports, and export pages.
- Validate response values are integers within [scalemin, scalemax].
- Validate band ranges on save: minscore ≤ maxscore, no overlaps with sibling bands.
- Validate scale on save: scalemin < scalemax; once attempts exist, block changes to scalemin and scalemax.
- Prevent duplicate attempts when allowretakes is false (server-side check, not just UI).
- Use Moodle formslib and PARAM_\* cleaning for all input.
- Use format_text() and format_string() for all rendered teacher- and learner-supplied content.
- Use a database transaction when creating attempt + responses + snapshotting band.
- Per LMS Light credential-handling rule: no plugin code echoes config values matching \*pass\*, \*secret\*, \*token\*, \*key\*, \*credential\*. (Not directly applicable to this plugin's runtime, but applies to any debug/CLI tooling written during development.)

## 12.1 Hand-written XMLDB and reserved-word avoidance (build-time engineering note)

This section captures a build-time engineering lesson, not a runtime behavior.

When defining a new schema in `db/install.xml`, validate all column names against SQL reserved-word lists for **every supported database engine** before first install attempt — not just the local development engine. Reserved words that are easy to hit unintentionally include `MAXVALUE` (MySQL 8.x PARTITION syntax), `MINVALUE` (Moodle's cross-DB compatibility list), `GROUP`, `ORDER`, `KEY`, `RANK`, `LEVEL`, `SIZE`, `STATE`.

Two ways to validate:

1. **Recommended:** Use Moodle's XMLDB editor (`admin/tool/xmldb`) to define the schema interactively. The editor auto-validates against the cross-DB reserved-word list and surfaces conflicts before save.
2. **Hand-written:** If editing `install.xml` directly (faster for greenfield work), grep the candidate column names against `lib/xmldb/xmldb_reserved_words.txt` (or the equivalent per-version path) before first install. Cross-check against the MySQL and PostgreSQL reserved-word lists for the target engine versions.

A single reserved-word collision causes install to fail with a SQL syntax error at the first table that contains the offending column. The fix is straightforward (rename the column) but the cost is a schema migration after the first failed install — cheaper to avoid up front.

This plugin's `scalemin` / `scalemax` columns were renamed from `minvalue` / `maxvalue` during Phase 1 build for exactly this reason.

# 13. QA / Test Plan

Per LMS Light convention (CONTEXT.md): phpcs clean, PHPUnit green, and CLI smoke (where present) at every phase boundary. Behat is desirable but not required for MVP.

| **Test**                                        | **Expected Result**                                                                                                                       |
|-------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| Install plugin on Moodle 5.1+                   | No XMLDB or upgrade errors; activity appears in chooser.                                                                                  |
| Create Career Fit Score example                 | Teacher can create activity, five items, and five result bands; band coverage validation reports clean.                                   |
| Submit 8,7,6,9,7                                | Result shows 37 out of 50 and matching "Strong" (36–45) band. Percentage hidden by default; visible in reports.                           |
| Submit with missing item                        | Submission blocked with errors anchored at each missing item.                                                                             |
| Attempt duplicate with retakes off              | Learner sees existing result; cannot submit again; server-side check rejects forged submission.                                           |
| Attempt duplicate with retakes on               | Second attempt is saved with attemptnumber 2; first attempt retained and visible in reports.                                              |
| Edit a band label after attempts exist          | New attempts use the new label; historical attempts continue to display the snapshotted label.                                            |
| Soft-delete an item after attempts exist        | Item disappears from learner view; historical detail rows show the prompt with a "(deleted item)" marker.                                 |
| Band coverage with overlap                      | Save blocked with named offending bands.                                                                                                  |
| Band coverage with gap                          | Save proceeds with non-blocking warning naming uncovered ranges.                                                                          |
| Submit a score that falls in an uncovered range | Result shows the per-instance fallback message (not a generic system message).                                                            |
| Gradebook off                                   | No grade item created; no grade update.                                                                                                   |
| Gradebook on                                    | Grade item receives raw total score (not percentage).                                                                                     |
| CSV export                                      | CSV includes expected columns and item response values.                                                                                   |
| Backup and restore                              | Activity restores with items (incl. soft-deleted), bands (incl. soft-deleted), and user data when selected. Snapshots preserved verbatim. |
| Privacy export                                  | User attempts, responses, and snapshotted band data exported in human-readable form.                                                      |
| Privacy delete                                  | Attempts and responses deleted; items, bands, and activity intact.                                                                        |
| Manual smoke (operator)                         | End-to-end teacher + learner walkthrough using the Career Fit Score fixture and the recommended second fixture (10×5 readiness check).    |
| Per-tenant theming                              | Override --scorecard-band-bg in a test tenant theme; verify band rendering picks up the override without plugin changes.                  |

# 14. Version 1.1 Roadmap

| **Feature**                            | **Rationale**                                                                                                                                 |
|----------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| Optional items (expose required field) | Surface the schema field already reserved in MVP. Update scoring logic to handle skipped optional items.                                      |
| Per-item weighting                     | Allows some prompts to count more than others. Forward-compatible schema (add weight column with default 1.0).                                |
| Reverse scoring                        | Useful for risk assessments and negatively worded items. Forward-compatible schema.                                                           |
| Categories/subscores                   | Supports richer diagnostics (e.g., satisfaction / compensation / clarity / control sub-scores in a Career Fit-style scorecard).               |
| Drag-and-drop reorder                  | MVP ships with up/down arrows; drag-drop is a UX upgrade once the basics are in production.                                                   |
| Per-question comments                  | Allows learner reflection and coaching context.                                                                                               |
| Save-draft / resume-later              | Highly requested by every Moodle user familiar with Quiz autosave.                                                                            |
| Charts (bar / radar)                   | Visual result presentation; depends on categories/subscores feature.                                                                          |
| PDF result download                    | Useful for coaches, consultants, and professional development plans.                                                                          |
| Email result to learner                | Useful for follow-up; integrate via Moodle's messaging API, not direct mail.                                                                  |
| Anonymous mode                         | Useful for organizational pulse surveys.                                                                                                      |
| AI-assisted item generation            | Per LMS Light AI integration standard, must use Moodle's AI Providers subsystem (Groq is the current provider). Differentiator for LMS Light. |

# 15. Build Plan

**Reshaped in v0.2:** tests are a per-phase quality gate, not a Phase 5 nice-to-have. Phase 5 split into 5a (completion + gradebook) and 5b (backup/restore + privacy). Each phase ends with a pause-verify-commit per LMS Light supervised-agentic convention.

| **Phase**                                                | **Deliverables**                                                                                                                                                                                                                                                                                           | **Status**                  |
|----------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------|
| Phase 1 — Skeleton                                       | Installable mod_scorecard with activity creation, db schema (all five tables incl. snapshot columns and soft-delete flags), capabilities, icon, basic view page. Phase gate: phpcs clean, PHPUnit skeleton tests green, manual install-and-create smoke.                                                   | **Complete (v0.1.0 alpha)** |
| Phase 2 — Authoring                                      | Manage screen with Items and Bands tabs; add/edit/soft-delete/reorder. Band coverage validation. Phase gate: phpcs clean, item and band CRUD tests green, manual authoring smoke including band overlap and gap cases.                                                                                     | Pending                     |
| Phase 3 — Learner Submission                             | Learner form, validation, attempt + response save in transaction, scoring engine, band matching with snapshotting, result display (with showpercentage + showitemsummary toggles). Phase gate: phpcs clean, scoring/band-matching tests green, manual end-to-end smoke with the Career Fit Score fixture.  | Pending                     |
| Phase 4 — Reporting                                      | Teacher Reports tab, expandable detail with deleted-item handling, CSV export with snapshot columns, group mode awareness. Phase gate: phpcs clean, report-data tests green, manual report and export smoke.                                                                                               | Pending                     |
| Phase 5a — Moodle integration (completion + gradebook)   | Activity completion (submission rule), gradebook integration, per-tenant theming hooks (CSS custom properties + styles.css). Phase gate: phpcs clean, completion + gradebook tests green, manual smoke with both off and on.                                                                               | Pending                     |
| Phase 5b — Moodle integration (backup/restore + privacy) | Backup/restore with snapshot fidelity, privacy provider with metadata + export + delete, plus the recommended second test fixture (10-item readiness check) added to QA. Phase gate: phpcs clean, privacy provider tests green, full backup/restore round-trip smoke, real-data privacy export inspection. | Pending                     |

# 16. Developer Deliverables

- Installable Moodle activity plugin package: mod_scorecard.
- Source code in github.com/jport500/moodle-mod_scorecard with README.
- XMLDB install and upgrade scripts.
- English language strings.
- PHPUnit test coverage of: scoring logic, band matching, band coverage validation, snapshotting, soft-delete behavior, privacy provider.
- Manual QA notes (MANUAL_SMOKE.md) showing the Career Fit Score fixture and the recommended second fixture tested end-to-end.
- docs/DECISIONS.md capturing the architectural choices recorded in this spec (see appendix A).
- docs/LESSONS.md may be deferred to v1.1 per LMS Light documentation convention.
- No paid third-party plugin dependencies.

# Appendix A — Decisions to Record in docs/DECISIONS.md

Once the plugin repository exists, capture the following decisions in docs/DECISIONS.md with rationale, alternatives considered, and a "would revisit if" trigger.

| **Decision**                                                                                                                                                         | **Trigger to revisit**                                                                                                                                                                                                                     |
|----------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Build a new activity module instead of using mod_quiz, mod_questionnaire, mod_feedback, or a custom question type.                                                   | If a future Moodle release lands native support for scored Likert with bands at the activity level.                                                                                                                                        |
| Soft-delete (not lock) items and bands after first attempt.                                                                                                          | If teachers report data hygiene problems with accumulated soft-deleted records.                                                                                                                                                            |
| Snapshot band label and message onto each attempt at submission time.                                                                                                | If snapshot storage cost becomes meaningful (millions of attempts) or if a use case requires retroactively updating historical interpretations.                                                                                            |
| Percentage hidden on learner result screen by default.                                                                                                               | If user research shows learners actively want percentage as the primary metric.                                                                                                                                                            |
| maxscore on the attempt = count × scalemax (upper-bound metric, not normalized).                                                                                     | If a feature requires a true 0–100 normalized value (add a separate column rather than overloading).                                                                                                                                       |
| All items required in MVP (`required` field reserved for v1.1).                                                                                                      | When v1.1 design surfaces a real use case for optional items.                                                                                                                                                                              |
| Up/down arrows for item reorder, drag-and-drop deferred.                                                                                                             | When a user complains about reorder UX or v1.1 budget allows the upgrade.                                                                                                                                                                  |
| No save-draft / resume-later in MVP.                                                                                                                                 | If completion-rate metrics show learners abandoning long scorecards mid-fill.                                                                                                                                                              |
| Per-tenant theming via CSS custom properties (--scorecard-\*).                                                                                                       | If a tenant requires more invasive customization than CSS can provide.                                                                                                                                                                     |
| Fallback message is per-instance (not sitewide or hardcoded).                                                                                                        | If multiple scorecards across a tenant repeatedly use the same fallback wording — consider a sitewide default at that point.                                                                                                               |
| Display style: radio-only in MVP. Schema reserves 'buttons' / 'dropdown' / 'slider' but the setting is not exposed in the form.                                      | When a tenant or operator presents a concrete UX case for one of the alternatives that justifies the per-style implementation cost (mobile tap targets for buttons, anchor pattern revision for dropdown, accessibility build for slider). |
| No quick-edit (eye icon) for item visibility in the manage list. Visibility lives only in the item form.                                                             | When operator usage shows visibility toggling is frequent enough that the form round-trip is real friction.                                                                                                                                |
| Group mode in reports: filter only, no per-group aggregation or group-level exports.                                                                                 | When customer requests for cross-group analytics surface a real reporting need (likely v1.1+ alongside categories/subscores).                                                                                                              |
| CSV export identity policy: respect `$CFG->showuseridentity`, always include userid + username.                                                                      | Only if site policy semantics change in a future Moodle release.                                                                                                                                                                           |
| Default fallback message ships in lang/en/scorecard.php and is pre-populated into the teacher form at activity creation. No render-time fallback to the lang string. | If the default copy proves systematically wrong for a customer segment — but per-instance override already covers single-scorecard cases.                                                                                                  |
| CSS variable defaults are hard values in styles.css designed to render reasonably on Boost. No reference to Boost SCSS variables.                                    | If Moodle core stabilizes a sitewide design-token system that makes referencing it the lower-maintenance path.                                                                                                                             |
| Schema column names `scalemin` / `scalemax` (not `minvalue` / `maxvalue`) to avoid SQL reserved-word collisions across MySQL, PostgreSQL, and Moodle XMLDB.          | Only if a future Moodle XMLDB version changes its reserved-word handling such that the original names become safe again — unlikely.                                                                                                        |
| `requires = 2024100100` (Moodle 4.5 LTS floor) for greenfield plugins, despite the auth_magiclink / local_welcomeemail convention of `2024040100`.                   | When a customer demands deployment on a Moodle older than 4.5 — at which point the floor would need lowering and any 5.x-only API usage would need backporting or feature-gating.                                                          |
| "Show previous attempt" is implicit (not a setting). When retakes are off and an attempt exists, the existing result is always displayed.                            | If a customer surfaces a use case for hiding the previous result while preventing resubmission — currently no such case exists.                                                                                                            |
| Privacy metadata for `scorecard_responses` declares both `attemptid` and `itemid` as graph-traversal links, not just `attemptid`.                                    | Only if Moodle's privacy export infrastructure changes such that FK traversal happens implicitly (currently it does not).                                                                                                                  |

*End of specification — Spec v0.5 — 2026-04-28 — Phase 6 JSON template directive folded in*
