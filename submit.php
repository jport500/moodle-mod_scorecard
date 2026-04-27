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
 * Learner submission endpoint for mod_scorecard.
 *
 * Owns the HTTP boundary: authentication, sesskey, and capability. Validation,
 * scoring, and persistence live in scorecard_handle_submission() so PHPUnit
 * can exercise them without simulating an HTTP request.
 *
 * On 'submitted' or 'duplicate_attempt' the user is redirected to view.php,
 * which renders the result-placeholder branch (3.4 will replace it with the
 * real result page). On 'validation_failed' the form is re-rendered inline
 * with $preselected to retain selections and $errors for inline messaging.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/scorecard/locallib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/scorecard:submit', $context);

$viewurl = new moodle_url('/mod/scorecard/view.php', ['id' => $cm->id]);

$rawresponses = optional_param_array('response', [], PARAM_RAW);
$rawresponses = is_array($rawresponses) ? $rawresponses : [];

$result = scorecard_handle_submission($scorecard, $cm, (int)$USER->id, $rawresponses);

if ($result['status'] === 'submitted' || $result['status'] === 'duplicate_attempt') {
    redirect($viewurl);
}

// Status is 'validation_failed' -- re-render the form inline with errors and preselections.
$PAGE->set_url('/mod/scorecard/submit.php');
$PAGE->set_title(format_string($scorecard->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('limitedwidth');

/** @var \mod_scorecard\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_scorecard');
$visibleitems = scorecard_get_visible_items((int)$scorecard->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($scorecard->name));

if (isset($result['errors']['_form'])) {
    echo $OUTPUT->notification($result['errors']['_form'], \core\output\notification::NOTIFY_ERROR);
}

if (!empty($visibleitems)) {
    echo $renderer->render_learner_form(
        $scorecard,
        $visibleitems,
        (int)$cm->id,
        $result['preselected'],
        $result['errors']
    );
} else {
    echo $renderer->render_learner_no_items();
}

echo $OUTPUT->footer();
