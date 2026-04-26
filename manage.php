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
 * Manage page for mod_scorecard: tabbed authoring surface (Items | Bands | Reports).
 *
 * Phase 2.1 ships the scaffold: capability gate, server-side tab routing via
 * ?tab=items|bands|reports, and three placeholder tab bodies. Items and Bands
 * tab CRUD lands in sub-steps 2.2 and 2.3 respectively. The Reports tab is a
 * Phase 4 placeholder per spec §15.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'items', PARAM_ALPHA);

$cm = get_coursemodule_from_id('scorecard', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

if (!has_capability('mod/scorecard:manage', $context)) {
    redirect(
        new moodle_url('/mod/scorecard/view.php', ['id' => $cm->id]),
        get_string('manage:nomanagecapability', 'mod_scorecard'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$validtabs = ['items', 'bands', 'reports'];
if (!in_array($tab, $validtabs, true)) {
    $tab = 'items';
}

$pageurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id, 'tab' => $tab]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($scorecard->name) . ': ' . get_string('manage:heading', 'mod_scorecard'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage:heading', 'mod_scorecard'));

$tabs = [
    new tabobject(
        'items',
        new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id, 'tab' => 'items']),
        get_string('manage:tab:items', 'mod_scorecard')
    ),
    new tabobject(
        'bands',
        new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id, 'tab' => 'bands']),
        get_string('manage:tab:bands', 'mod_scorecard')
    ),
    new tabobject(
        'reports',
        new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id, 'tab' => 'reports']),
        get_string('manage:tab:reports', 'mod_scorecard')
    ),
];

echo $OUTPUT->tabtree($tabs, $tab);

switch ($tab) {
    case 'items':
        echo $OUTPUT->box(get_string('manage:items:empty', 'mod_scorecard'), 'generalbox');
        break;
    case 'bands':
        echo $OUTPUT->box(get_string('manage:bands:empty', 'mod_scorecard'), 'generalbox');
        break;
    case 'reports':
        echo $OUTPUT->notification(
            get_string('manage:reports:phase4placeholder', 'mod_scorecard'),
            \core\output\notification::NOTIFY_INFO
        );
        break;
}

echo $OUTPUT->footer();
