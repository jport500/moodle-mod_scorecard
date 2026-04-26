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
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'items', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$itemid = optional_param('itemid', 0, PARAM_INT);
$confirmed = (bool)optional_param('confirm', 0, PARAM_BOOL);

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

$tabbaseurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id, 'tab' => $tab]);

// Items-tab side effects (move, confirmed delete, form submit) execute BEFORE any
// header output so redirect() works without "headers already sent" errors.
if ($tab === 'items') {
    if (($action === 'moveup' || $action === 'movedown') && $itemid > 0) {
        require_sesskey();
        scorecard_move_item($itemid, $action === 'moveup' ? 'up' : 'down');
        redirect($tabbaseurl);
    }

    if ($action === 'delete' && $confirmed && $itemid > 0) {
        require_sesskey();
        scorecard_delete_item($itemid);
        redirect(
            $tabbaseurl,
            get_string('item:notify:deleted', 'mod_scorecard'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'add' || $action === 'edit') {
        require_once(__DIR__ . '/classes/form/item_form.php');
        $formurl = new moodle_url('/mod/scorecard/manage.php', [
            'id' => $cm->id,
            'tab' => 'items',
            'action' => $action,
        ]);
        if ($action === 'edit' && $itemid > 0) {
            $formurl->param('itemid', $itemid);
        }
        $itemform = new \mod_scorecard\form\item_form($formurl);

        if ($itemform->is_cancelled()) {
            redirect($tabbaseurl);
        }
        if ($data = $itemform->get_data()) {
            $data->prompt = (string)($data->prompt_editor['text'] ?? '');
            $data->promptformat = (int)($data->prompt_editor['format'] ?? FORMAT_HTML);
            unset($data->prompt_editor);
            $data->lowlabel = (string)($data->lowlabel ?? '');
            $data->highlabel = (string)($data->highlabel ?? '');
            $data->visible = !empty($data->visible) ? 1 : 0;

            if ($action === 'add') {
                $data->scorecardid = $scorecard->id;
                scorecard_add_item($data);
                $msg = get_string('item:notify:added', 'mod_scorecard');
            } else {
                $data->id = $itemid;
                scorecard_update_item($data);
                $msg = get_string('item:notify:updated', 'mod_scorecard');
            }
            redirect($tabbaseurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

$pageurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id, 'tab' => $tab]);
if ($action !== '') {
    $pageurl->param('action', $action);
}
if ($itemid > 0) {
    $pageurl->param('itemid', $itemid);
}
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
        if ($action === 'add' || $action === 'edit') {
            $heading = $action === 'add'
                ? get_string('item:heading:add', 'mod_scorecard')
                : get_string('item:heading:edit', 'mod_scorecard');
            echo $OUTPUT->heading($heading, 3);

            if ($action === 'edit' && $itemid > 0) {
                $existing = $DB->get_record('scorecard_items', ['id' => $itemid], '*', MUST_EXIST);
                $itemform->set_data([
                    'prompt_editor' => [
                        'text' => $existing->prompt,
                        'format' => (int)$existing->promptformat,
                    ],
                    'lowlabel' => $existing->lowlabel,
                    'highlabel' => $existing->highlabel,
                    'visible' => (int)$existing->visible,
                ]);
            } else {
                $itemform->set_data(['visible' => 1]);
            }
            $itemform->display();
            break;
        }

        if ($action === 'delete' && $itemid > 0) {
            $stringkey = scorecard_count_attempts((int)$scorecard->id) === 0
                ? 'item:confirm:harddelete'
                : 'item:confirm:softdelete';
            $confirmurl = new moodle_url('/mod/scorecard/manage.php', [
                'id' => $cm->id,
                'tab' => 'items',
                'action' => 'delete',
                'itemid' => $itemid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
            echo $OUTPUT->confirm(
                get_string($stringkey, 'mod_scorecard'),
                $confirmurl,
                $tabbaseurl
            );
            break;
        }

        $renderer = $PAGE->get_renderer('mod_scorecard');
        $items = $DB->get_records(
            'scorecard_items',
            ['scorecardid' => $scorecard->id],
            'sortorder ASC, id ASC'
        );
        echo $renderer->render_items_list($items, $tabbaseurl);
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
