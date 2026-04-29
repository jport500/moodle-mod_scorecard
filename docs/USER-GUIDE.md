# mod_scorecard — Operator and Instructor Guide

A practical guide to installing, configuring, authoring, and managing scorecard
activities in Moodle. Written for the people who create scorecards and review
learner submissions; not a developer reference and not learner-facing copy
(learner copy lives in the scorecard's own intro field, item prompts, and band
messages).

This guide assumes the plugin is already installed. See [README.md](../README.md) for installation and upgrade instructions.

This guide covers mod_scorecard at **v0.7.0** (Phase 6 — JSON templates).
Features deferred to future releases are not documented here.

---

## What is a scorecard?

A scorecard is a Moodle activity where learners answer a series of prompts on a
numeric scale, and Moodle scores their responses against operator-defined
bands to produce qualitative feedback ("strong fit", "developing", etc).

Typical use cases:

- Self-assessment exercises where the qualitative band matters more than the
  raw numeric score
- Career-fit or readiness inventories
- Reflective surveys where the operator wants snapshot-stable results that
  don't shift if items or bands are later edited
- Cohort-level reporting where per-learner detail and aggregate patterns both
  need to be visible to instructors

A scorecard is **not** a quiz — there are no right or wrong answers; bands
classify the response pattern rather than grading correctness.

---

## Creating a scorecard

### Add the activity

In the destination course:

1. Turn editing on.
2. In the section where the scorecard should appear, click **Add an activity or resource**.
3. Choose **Scorecard** from the activity picker.
4. The standard Moodle activity-creation form opens.

### Activity settings

The activity-creation form covers the scorecard's overall behavior. The settings break into five groups: General, Scoring, Result display, Common module settings, and the standard Moodle blocks (Restrict access, Activity completion, Tags, Competencies).

#### General

- **Name** — How the scorecard appears in the course and gradebook. Required.
- **Description** — Optional intro shown above the form. Use this to explain the scorecard's purpose to learners ("This self-assessment helps you identify which of our certificate tracks fits your current strengths").
- **Display description on course page** — Standard Moodle setting; renders the description on the course page rather than only on the activity itself.

#### Scoring

- **Scale minimum** and **Scale maximum** — The numeric range learners choose from for each item. Common patterns are 1–5 (Likert-style), 1–7 (extended Likert), or 0–10 (rating scale). The scale applies to all items in this scorecard; you cannot mix scales per item.
- **Default low anchor** and **Default high anchor** — Optional text labels for the lowest and highest scale values, applied to every item that doesn't override them. For a 1–5 scale, common patterns are "Strongly disagree" / "Strongly agree", or "Never" / "Always". Per-item anchors (set when authoring individual items) override these defaults.
- **Display style** — Currently only "Radio buttons" is supported.

#### Result display

These settings control what learners see immediately after submitting their attempt.

- **Show percentage** — When enabled, the result page shows the learner's score as a percentage alongside the raw "X out of Y" headline.
- **Show item summary** — When enabled, the result page includes a collapsible summary of each item with the learner's response value. Useful for self-reflection; consider disabling for high-stakes assessments where you don't want learners reviewing their answer pattern.
- **Fallback message** — Text shown if the learner's score doesn't fall into any defined band. Keep it brief and reassuring; if learners frequently see this, your bands probably have gaps that need filling.

#### Gradebook integration

Scorecard integrates with the Moodle gradebook using Moodle's standard grading API.

- The scorecard appears in the gradebook automatically.
- The grade is the learner's `totalscore` from their latest attempt.
- The maximum grade is automatically calculated as `scalemax × number of visible items`.
- If the learner takes the scorecard multiple times, the latest attempt overwrites the gradebook entry.

You don't need to configure anything specific for gradebook integration — it's automatic when the activity is created.

#### Activity completion

Scorecard supports the standard Moodle completion conditions plus one custom condition:

- **Submit a scorecard attempt** — Learner is marked complete when they submit any attempt. This is the primary completion signal; most operators will enable this.
- Plus the standard view-based and grade-based conditions Moodle provides for all activities.

Configure these in the **Activity completion** section of the activity-creation form.

### Save and continue

After saving, you'll land on the scorecard's activity page (the learner-facing view). You'll see the empty-state because no items or bands exist yet.

To author items and bands, navigate to the **Manage** page via the secondary navigation on the activity, or use the manage affordance shown on the empty learner view.

---

## Authoring items

Items are the prompts learners respond to. Each item has a prompt (the question or statement), optional per-item anchor labels, a visibility flag, and a sort order.

### Add an item

From the manage page's **Items** tab:

1. Click **Add an item**.
2. Fill in the form:
   - **Prompt** — The question or statement learners respond to. Supports rich-text formatting (HTML, Atto editor, Markdown depending on your site's editor configuration). Keep prompts concrete and behavior-anchored ("I can explain my methodology to a non-technical colleague" works better than "I am good at communication").
   - **Low anchor** — Optional; overrides the scorecard's default low anchor for this item only. Use when one item needs different anchor text ("Never" instead of the default "Strongly disagree", for example).
   - **High anchor** — Optional; overrides the scorecard's default high anchor.
   - **Visible** — Unchecking hides the item from learners without deleting it. Useful for staging new items before release, or temporarily removing items without losing their authoring history. Hidden items don't contribute to the score or appear in the gradebook calculation.
3. Click **Save**.

### Edit, reorder, hide, delete

The items list shows all items (visible and hidden), each with action icons:

- **Up/down arrows** — Reorder items. Sort order determines the order learners encounter prompts.
- **Edit** — Open the item form to modify any field.
- **Delete** — Soft-delete the item. Soft-deleted items disappear from the items list and from the learner view, but their authoring history is preserved (so historical attempts can still resolve the item's prompt text on the report's expanded detail). Soft-deleted items can be restored only by direct database edit; there's no operator UI to restore at v0.7.0.

Hidden vs deleted: **hide** when you might want the item back; **delete** when you're sure it's gone. Both preserve historical attempt data.

### Authoring guidance

A few patterns from observed scorecard use:

- **5–15 items is the sweet spot.** Fewer than 5 produces low-resolution score patterns; more than 15 produces survey fatigue.
- **Anchor your items behaviorally.** Concrete behavior beats abstract trait. "I run weekly retrospectives with my team" is more discriminating than "I am a good leader."
- **Keep prompts positive-direction.** All items should have "high score = high indicator." Mixing reverse-scored items confuses both learners and band design.
- **Pilot with a small group first.** Run the scorecard with 5–10 trusted learners before opening to a cohort. Use the report tab to see whether responses cluster usefully or whether everyone scores in the middle.

---

## Authoring bands

Bands convert numeric scores to qualitative feedback. Each band defines a score range (min to max), a label (the headline learner sees), and a message (the body copy).

### Add a band

From the manage page's **Bands** tab:

1. Click **Add a band**.
2. Fill in the form:
   - **Label** — Short headline shown as the band's heading on the result page. Examples: "Ready to apply", "Building strengths", "Foundational practice".
   - **Minimum score** and **Maximum score** — The score range this band covers. Both inclusive. The first band typically starts at 0 (or your effective minimum); the last band typically ends at the scorecard's maximum possible score.
   - **Message** — The body copy shown below the label on the result page. Supports rich text. This is where you give learners actionable next-step guidance.
3. Click **Save**.

### Calculating band ranges

The scorecard's maximum possible score is `scalemax × number of visible items`. For a 1–5 scale with 10 items, max possible is 50.

A typical 3-band layout for that scorecard:

- "Foundational practice" — 0–25 (bottom half)
- "Building strengths" — 26–37 (middle)
- "Ready to apply" — 38–50 (top quarter)

A 5-band layout:

- "Just starting" — 0–15
- "Building basics" — 16–25
- "Developing capability" — 26–35
- "Strong practice" — 36–43
- "Exemplary" — 44–50

There's no canonical right answer for band count or thresholds; it depends on what discrimination you want and how learners will use the feedback. If you're not sure, pilot with 3 bands and add more if learners cluster too tightly into one.

### Band coverage and gaps

A few things to watch:

- **Cover the full possible score range.** If the maximum possible score is 50, your highest band's max should be 50 (or higher — over-range is harmless). Gaps between bands cause the fallback message to appear, which is rarely what you want.
- **No overlapping ranges.** If two bands both include score 30, the lower-min-score band wins by sort order, but this is fragile — fix the overlap.
- **Order bands low-to-high.** The bands list sorts by minimum score automatically; you don't need to manually reorder.

### Edit and delete bands

Same affordances as items: edit modifies any field; delete soft-deletes (preserved for historical attempt resolution but hidden from new attempts and the bands list).

### A note on score snapshots

When a learner submits an attempt, their band label and message are **snapshotted** into the attempt record. This means:

- Editing a band's label or message after submission does NOT change what past learners see when they revisit their result.
- Deleting a band after submission does NOT remove the band's label/message from past learners' results.
- A learner who retakes the scorecard sees the current band definitions; a learner viewing their previous attempt sees the snapshotted version from when they submitted.

This snapshot behavior is intentional — it means your authoring decisions don't retroactively alter learner records. Edit and delete freely; the historical record stays stable.

---

## JSON templates (export and import)

New at v0.7.0: scorecards can export their authoring structure (items + bands + settings) as a JSON template file, and operators can populate freshly-created empty scorecards from a JSON template.

### What templates capture

Templates capture **authoring structure only**:

- Scorecard settings (scale bounds, anchors, result display flags, fallback message, etc)
- Non-deleted items (prompts, anchors, visibility flag, sort order)
- Non-deleted bands (range, label, message, sort order)

Templates **do not** capture:

- Learner attempts or responses (use Moodle's backup/restore for that)
- Soft-deleted items or bands (templates represent current intended structure, not history)
- Gradebook entries (regenerated from attempts on import)
- Course-specific context (the scorecard's section, completion configuration, etc — those reset to defaults on import)

### Export workflow

From any populated scorecard's manage page (one with at least one item or band):

1. Click **Export template** above the tab tree.
2. The browser downloads a JSON file named `<scorecard-name-slugified>-template.json`.
3. Distribute the file via email, file share, version control, or any channel you prefer.

Templates are operator-readable JSON — you can open them in a text editor to inspect or hand-edit. Validation catches malformations on import, so manual editing is safe; just don't expect Moodle to repair structurally broken templates.

### Import workflow

Import populates a freshly-created **empty** scorecard. The flow:

1. In the destination course, use **Add an activity or resource** > **Scorecard** to create a new scorecard. Save with default settings (the template's settings will fill in the rest; nothing you set here will conflict because items and bands are empty).
2. Land on the new scorecard's manage page. You'll see an **Import template** button above the tab tree (it appears only when items and bands are both empty).
3. Click **Import template** > upload your JSON file > submit.
4. On success, you'll redirect to the populated manage page with a notification: "Template imported. N items and M bands added."

If the JSON validates with errors (missing fields, wrong types, invalid scale, etc), the form re-renders with a red error block listing the specific paths that failed. Fix the source JSON and re-upload.

If the JSON validates with warnings (plugin version mismatch, unknown fields that will be ignored), you'll see a yellow warnings block alongside a "Yes, import anyway" button. Warnings are non-blocking — click to acknowledge and proceed; the upload is preserved across the round-trip so you don't need to re-upload.

### When the import button doesn't appear

The Import template affordance is suppressed on populated scorecards. If you don't see it:

- Check the items tab — if any items exist (visible OR hidden, but not soft-deleted), the affordance is suppressed.
- Check the bands tab — same logic.
- The export affordance shows in its place.

To use a template on an existing scorecard, you have two options:

- Delete all items and bands first (returning to empty state), then import. Note that this loses any existing learner attempts associated with those items.
- Create a fresh empty scorecard, import the template into the new one, then migrate learners as needed.

Overwrite and append import modes are deferred to a future release.

### Versioning notes

Templates carry a `plugin.version` stamp recording which version of mod_scorecard exported them. On import:

- Same version → no warning
- Older version source, newer version destination → non-blocking warning ("plugin version mismatch")
- Newer version source, older version destination → non-blocking warning

The `schema_version` field (currently always `"1.0"`) is the format-stability contract. Cross-schema-version imports may not be possible in future releases; cross-plugin-version imports within the same schema version are always supported (with the informational warning).

---

## Reviewing learner submissions

The Reports tab on the manage page shows aggregate and per-learner submission data.

### The reports table

Columns:

- **Learner name** (clickable to expand the per-attempt detail)
- **Group** (when group mode is enabled on the activity or course)
- **Submitted** (timestamp of latest attempt)
- **Score** (raw `totalscore` / `maxscore`)
- **Band** (the snapshotted band label from the latest attempt)

The table respects Moodle's group mode:

- **No groups** — Operators see all learners.
- **Visible groups** — Operators can filter by group; learners see results scoped to their own group (not directly applicable to scorecard's report; the operator's view is what matters).
- **Separate groups** — Non-administrator operators see only the groups they're members of; site administrators always see everything.

Click a learner's name to expand a detail block showing their per-item responses, with snapshotted item prompts (so renamed/deleted items still display the original prompt text from when the attempt was submitted).

Soft-deleted items in the detail are marked with a "(deleted)" prefix; out-of-range responses (responses outside the current scale, which can happen if the scale was edited after submission) are flagged with the original range.

### CSV export

Click **Export to CSV** above the report table. The download includes one row per learner with their score, band, group, and submission timestamp. Use this for cohort-level analysis in spreadsheet software or for archiving submission records.

The CSV does not include per-item responses — for that, expand individual learners in the table.

### Empty state

When no learners have submitted yet, you'll see a "No attempts yet" notice. If you have a group filter active and no learners in the filtered groups have submitted, you'll see a slightly different "No attempts in this filter" notice (the underlying data may exist, just outside your current filter).

---

## Backup, restore, and privacy

### Backup

Scorecard activities back up via Moodle's standard course backup wizard. From the course administration:

1. **Backup** > select the scorecard (or "Include all activities") > continue.
2. Choose whether to include user data ("Include user data" checkbox).
3. Confirm and download the backup file.

Behavior:

- **Without user data**: items, bands, and scorecard settings are backed up. No attempts, no responses. Useful for distributing a template-shaped backup that recipients restore into their own course.
- **With user data**: items, bands, settings, attempts, and responses (including all snapshotted fields) are backed up. Restoring this elsewhere preserves the full submission history.

The snapshot fields on attempts (band label, band message, response item prompts) survive backup/restore byte-identically. Historical attempts remain intact; learners revisiting their results after a restore see the same content they saw originally.

### Restore

Standard Moodle restore wizard:

1. **Restore** in the destination course > upload backup file > follow the wizard.
2. Choose whether to merge into the existing course or restore as a new course.
3. Choose whether to include user data (must have been included in the backup).

Restored scorecards carry their original configuration. If user data was included, attempts and responses appear in the gradebook automatically.

### Privacy

mod_scorecard implements Moodle's privacy provider API. Learners (and admins acting on their behalf) can:

- **Export** their scorecard data via Moodle's standard data export tooling. The export includes their attempts, responses, and the snapshotted item prompts and band messages they saw.
- **Delete** their scorecard data via Moodle's standard data deletion tooling. Deletion removes their attempts and responses; it does not affect items or bands (which are course-level, not user-level).

Both operations follow Moodle's standard privacy workflows; you don't need to do anything scorecard-specific.

---

## Troubleshooting

### Operator-facing UI text shows as `[[<key>]]`

The lang string cache is stale. Run `php admin/cli/purge_caches.php` (or **Site administration > Development > Purge all caches**). This is most common immediately after upgrading to a new plugin version that added language strings.

### Import affordance doesn't appear on a freshly-created scorecard

The scorecard has items or bands already. The empty-state affordance is suppressed when any content exists. Check both the items tab and bands tab; either being non-empty suppresses the import affordance. Delete content (returning to empty state), or create a fresh scorecard.

### Import fails with "schema_version" error

The JSON template has a `schema_version` field that's not `"1.0"`. v0.7.0 supports only schema version 1.0. If you're trying to import a template from a future version of mod_scorecard, you'll need to wait for an upgrade that supports the newer schema, or downgrade the source template manually.

### Import fails with "plugin.name" error

The JSON template was exported from a different plugin (not mod_scorecard) but happens to share the JSON envelope shape. Check the template file — the `plugin.name` field should be `"mod_scorecard"`. If it's something else, this isn't a mod_scorecard template.

### Learners see different band labels than the bands tab shows

This is intentional. Band labels and messages are snapshotted into each attempt at submission time. If you've edited bands since the learner submitted, they'll see the snapshotted (older) version; if they retake, they'll see the current bands.

To verify the snapshot vs current state, expand the learner in the report — the per-attempt detail shows the snapshotted item prompts.

### Gradebook score doesn't match the report's score

The gradebook uses the latest attempt's `totalscore`. The report shows the latest attempt by default. They should match. If they don't:

- Check whether the gradebook entry was manually overridden (Moodle gradebook overrides take precedence over plugin-calculated grades).
- Check whether items have been added since the last attempt (the maximum possible score updates automatically, but old attempts retain their original `maxscore` snapshot).
- Run **Site administration > Grades > Recalculate grades** to refresh derived values.

### Soft-deleted items still appear in CSV export

The CSV export includes snapshotted item prompts from the attempt records, including for items that were later soft-deleted. This is intentional — it preserves the historical record of what each learner actually saw when they submitted.

### Hidden items count toward the maximum possible score

They shouldn't. Hidden items are excluded from the `scalemax × visible-item-count` calculation. If you're seeing unexpected maximum scores, verify the items you think are hidden actually have the visibility flag unchecked (not just an obscure or terse prompt).

---

## What's next

mod_scorecard is at MATURITY_ALPHA at v0.7.0. The plugin is feature-complete for its current scope but hasn't accumulated enough production-usage signal to bump to BETA.

If you're piloting mod_scorecard:

- File issues at the project's issue tracker for bugs, unclear documentation, or workflow friction.
- Watch the project's CHANGES.md for release notes when new versions ship.
- Backup/restore and templates both ship at production-ready quality at v0.7.0; pilot with confidence on those surfaces.

Out-of-scope features for v0.7.0 that may ship in future releases (no committed timelines):

- Database-backed template library (browse and select templates from in-platform rather than swapping JSON files)
- Overwrite and append import modes (populate templates into already-populated scorecards)
- Alternative grade methods (highest score, first attempt, average instead of latest-attempt overwrites)
- Per-tenant theming hooks
- Cross-schema-version template compatibility

For deferred-feature questions or pilot deployment guidance, contact the project maintainers via the project repository.
