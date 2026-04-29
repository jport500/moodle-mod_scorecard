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
 * Branches on capability:
 * - :submit (learner) → form / result depending on attempt existence and
 *   retakes setting (3.1 scaffolds the form path; result page lands in 3.4).
 * - :manage (teacher) without :submit → manage-link placeholder (Phase 1).
 * - other → learner not-ready placeholder (Phase 1).
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

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
$PAGE->add_body_class('limitedwidth');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($scorecard->name));

if (!empty($scorecard->intro)) {
    echo $OUTPUT->box(format_module_intro('scorecard', $scorecard, $cm->id), 'generalbox', 'intro');
}

/** @var \mod_scorecard\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_scorecard');

// Persistent manage affordance, hoisted ABOVE the :submit-vs-:manage branch
// split so both populations inherit it: site admins and editing-teachers
// who satisfy :submit reach the submit-capable leaves below; default
// Teacher / Manager roles without :submit reach the :manage-only branch.
// Either way they're authors who need a path to manage.php from view.php.
if (has_capability('mod/scorecard:manage', $context)) {
    echo $renderer->render_manage_affordance((int)$cm->id);
}

if (has_capability('mod/scorecard:submit', $context)) {
    $items = scorecard_get_visible_items((int)$scorecard->id);

    if (!$items) {
        // Empty state keeps its directive inline button on top of the persistent
        // affordance above -- a fresh activity benefits from "Add items and
        // result bands" as a directive callout, not just the generic "Manage".
        echo $renderer->render_learner_no_items(
            has_capability('mod/scorecard:manage', $context),
            (int)$cm->id
        );
    } else if (scorecard_user_has_attempt((int)$scorecard->id, (int)$USER->id)) {
        if (empty($scorecard->allowretakes)) {
            if (empty($scorecard->showresult)) {
                echo $OUTPUT->box(get_string('result:hidden', 'mod_scorecard'), 'generalbox');
            } else {
                $attempt = scorecard_get_latest_user_attempt((int)$scorecard->id, (int)$USER->id);
                $responserows = $DB->get_records(
                    'scorecard_responses',
                    ['attemptid' => (int)$attempt->id]
                );
                $responsemap = [];
                $itemids = [];
                foreach ($responserows as $r) {
                    $responsemap[(int)$r->itemid] = (int)$r->responsevalue;
                    $itemids[] = (int)$r->itemid;
                }
                $summaryitems = $itemids
                    ? $DB->get_records_list('scorecard_items', 'id', $itemids)
                    : [];
                echo $renderer->render_result_page($scorecard, $attempt, $summaryitems, $responsemap);
            }
        } else {
            // Note: callout intentionally shows score+band even when showresult=0.
            // showresult gates the post-submit results page, not all references to
            // past performance. Operators wanting total result-blackout should also
            // disable allowretakes.
            $previous = scorecard_get_latest_user_attempt((int)$scorecard->id, (int)$USER->id);
            if ($previous) {
                echo $renderer->render_previous_attempt_callout($previous);
            }
            echo $renderer->render_learner_form($scorecard, $items, (int)$cm->id);
        }
    } else {
        echo $renderer->render_learner_form($scorecard, $items, (int)$cm->id);
    }
} else if (has_capability('mod/scorecard:manage', $context)) {
    echo $renderer->render_manager_no_items((int)$cm->id);
} else {
    echo $OUTPUT->box(get_string('view:noitems_learner', 'mod_scorecard'), 'generalbox');
}

echo $OUTPUT->footer();
