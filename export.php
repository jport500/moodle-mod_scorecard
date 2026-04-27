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
 * CSV export endpoint for mod_scorecard's report.
 *
 * Streaming-only -- no page chrome. Operator clicks the "Export CSV" button on
 * report.php and lands here; the browser receives the CSV download and the
 * report.php tab stays put.
 *
 * Composition (Phase 4.4):
 *  1. Capability gate: require_capability('mod/scorecard:export') BEFORE any
 *     data fetch. Separate from :viewreports per SPEC §9.1 -- some operators
 *     may grant on-screen viewing without download (audit context).
 *  2. Group filter inheritance: groups_get_activity_group($cm, true) reads the
 *     same session-persisted group selection that report.php's selector
 *     drives. No URL groupid parameter -- per Phase 4.3 Q3 disposition.
 *  3. Data fetch: scorecard_get_attempts() (group-filtered) +
 *     scorecard_get_attempt_responses() (batch by attemptid).
 *  4. Item set: scorecard_get_export_item_set() derives the union of item ids
 *     from the responses batch -- pure-PHP, reuses the data structure 4.2's
 *     helper already returns. Live items first by sortorder, then deleted
 *     items at the end.
 *  5. Headers + rows: scorecard_build_export_data() returns the data shape;
 *     this endpoint walks it and feeds csv_export_writer for streaming.
 *
 * Defensive empty-attempts redirect: the export button is hidden on report.php
 * when no attempts match the active filter (Phase 4 kickoff Q5 / Q6
 * dispositions), so the only way to land here without data is direct URL
 * navigation. Redirect back to report.php with a notification rather than
 * download an empty CSV.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/csvlib.class.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('scorecard', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scorecard:export', $context);

$reporturl = new moodle_url('/mod/scorecard/report.php', ['id' => $cm->id]);

// Group filter via session (Phase 4.3 Q3 disposition). 0 / false / null all
// mean "no filter" to scorecard_get_attempts.
$activegroup = groups_get_activity_group($cm, true);
$groupid = ($activegroup && $activegroup > 0) ? (int)$activegroup : null;

$attempts = scorecard_get_attempts($context, (int)$scorecard->id, $groupid);

if (empty($attempts)) {
    redirect(
        $reporturl,
        get_string('report:export:noattempts', 'mod_scorecard'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$identityfields = \core_user\fields::get_identity_fields($context, true);
$responsesbyattempt = scorecard_get_attempt_responses(
    array_map(fn($a) => (int)$a->attemptid, $attempts)
);
$itemset = scorecard_get_export_item_set($responsesbyattempt);
$exportdata = scorecard_build_export_data(
    $scorecard,
    $attempts,
    $responsesbyattempt,
    $itemset,
    $identityfields
);

// Filename: scorecard-{shortname}-attempts-{YYYYMMDD-HHMMSS}. csv_export_writer's
// set_filename appends the .csv extension itself.
$shortname = clean_param($scorecard->name, PARAM_FILE);
$filename = "scorecard-{$shortname}-attempts-" . date('Ymd-His');

$writer = new csv_export_writer();
$writer->set_filename($filename);
$writer->add_data($exportdata['headers']);
foreach ($exportdata['rows'] as $row) {
    $writer->add_data($row);
}
$writer->download_file();
