<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Manager-facing report page for mod_scorecard (SPEC §10.4).
 *
 * Top-level page rather than a manage.php tab body per Phase 4 Q1: separates
 * authoring (manage.php) from reporting (report.php), matches SPEC §7.2 file
 * structure, and lets manage.php's Reports tab redirect here so the Phase 1
 * three-tab nav UX is preserved.
 *
 * Phase 4 builds the report in sub-steps:
 * - 4.1 (this file's first cut): capability gate, attempt fetch, table render,
 *   empty-state branch. Group-mode integration is stubbed to null per pre-flag #3.
 * - 4.2 adds the per-attempt expandable detail row (audit-honest itemid lookup).
 * - 4.3 wires group selector + accessallgroups awareness into scorecard_get_attempts.
 * - 4.4 adds the CSV export button (this page) + export.php (the streaming endpoint).
 * - 4.5 swaps the inline render for flexible_table-backed pagination.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('scorecard', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Capability gate BEFORE data fetch (Phase 4.1 pre-flag #3). Phase 4.3
// added the group filter; moodle/site:accessallgroups handling is delegated
// to groups_get_activity_group() below per Phase 4.3 pre-flag #2.
if (!has_capability('mod/scorecard:viewreports', $context)) {
    redirect(
        new moodle_url('/mod/scorecard/view.php', ['id' => $cm->id]),
        get_string('report:nocapability', 'mod_scorecard'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$pageurl = new moodle_url('/mod/scorecard/report.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($scorecard->name) . ': ' . get_string('report:heading', 'mod_scorecard'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Phase 4.3 group filter. groups_get_activity_group($cm, true) reads/persists
// the active group via session AND consults moodle/site:accessallgroups
// internally -- users without the cap see only their own groups in the
// selector. Returns 0 ("All groups" sentinel) when no group is selected,
// or false when the activity has no group mode configured. We treat both
// 0 and false as "no filter," and only the truthy >0 value triggers the
// SQL JOIN inside scorecard_get_attempts.
$activegroup = groups_get_activity_group($cm, true);
$groupid = ($activegroup && $activegroup > 0) ? (int)$activegroup : null;
$groupfilteractive = ($groupid !== null);

$attempts = scorecard_get_attempts($context, (int)$scorecard->id, $groupid);
$identityfields = \core_user\fields::get_identity_fields($context, true);

// Phase 4.2: batch-fetch per-attempt responses in a single SQL round-trip so
// the renderer's per-row detail block doesn't N+1-query.
$responsesbyattempt = $attempts
    ? scorecard_get_attempt_responses(array_map(fn($a) => (int)$a->attemptid, $attempts))
    : [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report:heading', 'mod_scorecard'));

// Group selector emitted unconditionally above the table/empty branch.
// groups_print_activity_menu returns the empty string when the activity has
// no group mode configured, so this is a no-op in that case (Phase 4.3 Q1
// disposition: standard Moodle position, present consistently).
echo groups_print_activity_menu($cm, $pageurl, true);

$renderer = $PAGE->get_renderer('mod_scorecard');
if (empty($attempts)) {
    echo $renderer->render_report_empty_state($groupfilteractive);
} else {
    echo $renderer->render_report_table($scorecard, $attempts, $identityfields, $responsesbyattempt);
}

echo $OUTPUT->footer();
