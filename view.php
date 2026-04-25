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
 * View page for mod_scorecard.
 *
 * Phase 1: renders activity title, intro, and a role-branched placeholder.
 * Phases 2 and 3 replace the placeholder with the manage UI and learner
 * submission form respectively.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);
$s = optional_param('s', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('scorecard', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($s) {
    $scorecard = $DB->get_record('scorecard', ['id' => $s], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $scorecard->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('scorecard', $scorecard->id, $course->id, false, MUST_EXIST);
} else {
    throw new moodle_exception('missingparameter');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scorecard:view', $context);

$PAGE->set_url('/mod/scorecard/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($scorecard->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($scorecard->name));

if (!empty($scorecard->intro)) {
    echo $OUTPUT->box(format_module_intro('scorecard', $scorecard, $cm->id), 'generalbox', 'intro');
}

if (has_capability('mod/scorecard:manage', $context)) {
    $manageurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id]);
    $body = get_string('view:noitems_manager', 'mod_scorecard') . html_writer::empty_tag('br') .
        html_writer::link($manageurl, get_string('view:manageitemslink', 'mod_scorecard'));
    echo $OUTPUT->box($body, 'generalbox');
} else {
    echo $OUTPUT->box(get_string('view:noitems_learner', 'mod_scorecard'), 'generalbox');
}

echo $OUTPUT->footer();
