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
 * JSON template import endpoint for mod_scorecard (populate-existing path).
 *
 * Phase 6.5b — operator already created an empty scorecard via standard
 * "Add an activity" workflow; this endpoint populates items + bands from a
 * JSON template file. Discovered via the manage.php empty-state affordance.
 *
 * Composition:
 *  1. Capability gate: require_capability('mod/scorecard:manage') at MODULE
 *     context (Q-rework-4). Operator already used :addinstance to create the
 *     scorecard; populating it is "manage this scorecard" semantically.
 *  2. Empty-state precheck: redirect to manage.php with a notification when
 *     the scorecard already has items or bands (defends against direct-URL
 *     access to a populated scorecard).
 *  3. Two submission paths:
 *     a. Main upload form: filepicker → save_file → read JSON → orchestrate.
 *     b. Confirmation form (visible on warnings state): hidden pendingjson
 *        (base64) + confirmwarnings=1 → orchestrate with confirmed=true.
 *  4. Success: redirect to manage.php with a Moodle notification (Q-rework-3).
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/classes/form/template_import_form.php');

$cmid = required_param('cmid', PARAM_INT);

$cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scorecard:manage', $context);

$pageurl = new moodle_url('/mod/scorecard/template_import.php', ['cmid' => $cm->id]);
$manageurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cm->id]);

// Empty-state precheck: redirect to manage if the scorecard already has
// content. Defensive — manage.php should suppress the affordance for
// populated scorecards, but direct-URL navigation could still land here.
$itemcount = (int)$DB->count_records('scorecard_items', ['scorecardid' => (int)$scorecard->id]);
$bandcount = (int)$DB->count_records('scorecard_bands', ['scorecardid' => (int)$scorecard->id]);
if ($itemcount > 0 || $bandcount > 0) {
    redirect(
        $manageurl,
        get_string('template:import:error:notempty', 'mod_scorecard'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$form = new \mod_scorecard\form\template_import_form($pageurl);

if ($form->is_cancelled()) {
    redirect($manageurl);
}

$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('template:import:heading', 'mod_scorecard'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('limitedwidth');

/** @var \mod_scorecard\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_scorecard');

// Confirmation form path: operator clicked "Yes, import anyway" on the
// warnings re-render. Reads pendingjson from POST (base64-encoded; preserved
// across the warnings round-trip without re-upload), invokes the helper with
// confirmed=true. require_sesskey defends the confirmation surface from CSRF.
$pendingjson = optional_param('pendingjson', '', PARAM_RAW);
$confirmwarnings = optional_param('confirmwarnings', 0, PARAM_INT);
if ($pendingjson !== '' && $confirmwarnings === 1) {
    require_sesskey();
    $rawjson = base64_decode($pendingjson, true);
    if ($rawjson === false) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));
        echo $OUTPUT->notification(
            get_string('template:import:jsondecode:error', 'mod_scorecard'),
            \core\output\notification::NOTIFY_ERROR
        );
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    try {
        $result = scorecard_template_import_handle($cm->id, $rawjson, true);
    } catch (\moodle_exception $e) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));
        echo $OUTPUT->notification(
            get_string($e->errorcode, 'mod_scorecard'),
            \core\output\notification::NOTIFY_ERROR
        );
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    if ($result['state'] === 'success') {
        $a = (object)[
            'itemcount' => count(json_decode($rawjson, true)['items'] ?? []),
            'bandcount' => count(json_decode($rawjson, true)['bands'] ?? []),
        ];
        redirect(
            $manageurl,
            get_string('template:import:notify:redirected', 'mod_scorecard', $a),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Errors after confirmation (rare — validation should be deterministic).
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));
    echo $renderer->render_template_validation_errors($result['errors']);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// Main upload form path.
if ($formdata = $form->get_data()) {
    $tmpdir = make_request_directory();
    $tmppath = $tmpdir . '/' . $form->get_new_filename('templatefile');
    $saved = $form->save_file('templatefile', $tmppath, true);

    if (!$saved) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));
        echo $OUTPUT->notification(
            get_string('template:import:fileread:error', 'mod_scorecard'),
            \core\output\notification::NOTIFY_ERROR
        );
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    $rawjson = (string)file_get_contents($tmppath);

    try {
        $result = scorecard_template_import_handle($cm->id, $rawjson, false);
    } catch (\moodle_exception $e) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));
        echo $OUTPUT->notification(
            get_string($e->errorcode, 'mod_scorecard'),
            \core\output\notification::NOTIFY_ERROR
        );
        $form->display();
        echo $OUTPUT->footer();
        exit;
    }

    if ($result['state'] === 'success') {
        $decoded = json_decode($rawjson, true);
        $a = (object)[
            'itemcount' => count($decoded['items'] ?? []),
            'bandcount' => count($decoded['bands'] ?? []),
        ];
        redirect(
            $manageurl,
            get_string('template:import:notify:redirected', 'mod_scorecard', $a),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));

    if ($result['state'] === 'errors') {
        echo $renderer->render_template_validation_errors($result['errors']);
        $form->display();
    } else {
        // Warnings state: render warnings + a separate confirmation form
        // carrying base64-encoded JSON across the round-trip per Q-rework-5.
        echo $renderer->render_template_validation_warnings($result['warnings'], true);
        echo $renderer->render_template_warnings_confirmation_form($cm->id, $rawjson);
    }

    echo $OUTPUT->footer();
    exit;
}

// First-load form display.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('template:import:heading', 'mod_scorecard'));
$form->display();
echo $OUTPUT->footer();
