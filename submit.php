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
 * 3.1 stub: validates the request shape (sesskey, cmid, capability) and
 * renders a placeholder explaining that submission handling lands in 3.3.
 * Confirms the form's POST target is wired correctly without writing any
 * data. The full submit handler — validation, scoring engine call,
 * single-transaction attempt + responses + snapshot write, redirect to
 * view.php — replaces this body in 3.3.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

$cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/scorecard:submit', $context);

$PAGE->set_url('/mod/scorecard/submit.php');
$PAGE->set_title(format_string($scorecard->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($scorecard->name));
echo $OUTPUT->box(
    get_string('submit:placeholder', 'mod_scorecard'),
    'generalbox alert alert-info'
);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/scorecard/view.php', ['id' => $cm->id]),
        get_string('submit:back', 'mod_scorecard')
    ),
    'mt-3'
);
echo $OUTPUT->footer();
