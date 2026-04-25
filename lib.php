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
 * Activity-module API for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare which Moodle activity-module features mod_scorecard supports.
 *
 * Phase 1 declares only features actually implemented in this skeleton.
 * Backup, completion, and gradebook flags are explicitly false; they flip
 * to true in Phases 1.4, 5a, 5b respectively as their implementations land.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True/false/feature constant; null for unknown features.
 */
function scorecard_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_GROUPS => true,
        FEATURE_GROUPINGS => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ASSESSMENT,
        FEATURE_BACKUP_MOODLE2 => false,
        FEATURE_COMPLETION_TRACKS_VIEWS => false,
        FEATURE_COMPLETION_HAS_RULES => false,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        default => null,
    };
}

/**
 * Add a scorecard activity instance.
 *
 * @param stdClass $data Form data from mod_form.
 * @param mod_scorecard_mod_form|null $mform Form instance (unused in Phase 1).
 * @return int New scorecard.id.
 */
function scorecard_add_instance($data, $mform = null) {
    global $DB;

    $now = time();
    $data->timecreated = $now;
    $data->timemodified = $now;

    scorecard_flatten_editor_fields($data);

    return (int)$DB->insert_record('scorecard', $data);
}

/**
 * Update a scorecard activity instance.
 *
 * @param stdClass $data Form data from mod_form; $data->instance is the scorecard.id.
 * @param mod_scorecard_mod_form|null $mform Form instance (unused in Phase 1).
 * @return bool
 */
function scorecard_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    scorecard_flatten_editor_fields($data);

    return $DB->update_record('scorecard', $data);
}

/**
 * Delete a scorecard activity instance and all its dependent rows.
 *
 * Cascade order matters: Moodle's XMLDB declares foreign keys but does not
 * enforce them at the DBMS level. The parent plugin must therefore delete
 * dependent rows explicitly, in dependent-most-first order:
 *
 *   1. responses (depend on attempts and items)
 *   2. attempts  (depend on scorecard; reference bands by snapshot FK)
 *   3. bands     (depend on scorecard)
 *   4. items     (depend on scorecard)
 *   5. scorecard (the parent row)
 *
 * Reversing any pair would orphan rows because Moodle XMLDB FKs are
 * documentation, not DBMS-enforced constraints.
 *
 * Phase 1: items / bands / attempts / responses cannot exist yet (no UI
 * to create them), but the cascade is written correctly now so Phases 2
 * and 3 inherit the right behavior.
 *
 * @param int $id scorecard.id.
 * @return bool
 */
function scorecard_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('scorecard', ['id' => $id])) {
        return false;
    }

    $DB->delete_records_select(
        'scorecard_responses',
        'attemptid IN (SELECT id FROM {scorecard_attempts} WHERE scorecardid = :sid)',
        ['sid' => $id]
    );
    $DB->delete_records('scorecard_attempts', ['scorecardid' => $id]);
    $DB->delete_records('scorecard_bands', ['scorecardid' => $id]);
    $DB->delete_records('scorecard_items', ['scorecardid' => $id]);
    $DB->delete_records('scorecard', ['id' => $id]);

    return true;
}

/**
 * Flatten editor-element fields back to scalar columns before save.
 *
 * Moodle's `editor` form element produces an array {text, format, ...},
 * which {@see moodle_database::insert_record()} cannot store directly.
 * Convert known editor fields to their backing scalar columns and remove
 * the array from the data object.
 *
 * @param stdClass $data Form data; mutated in place.
 */
function scorecard_flatten_editor_fields(stdClass $data): void {
    if (isset($data->fallbackmessage_editor) && is_array($data->fallbackmessage_editor)) {
        $data->fallbackmessage = $data->fallbackmessage_editor['text'] ?? '';
        $data->fallbackmessageformat = $data->fallbackmessage_editor['format'] ?? FORMAT_HTML;
        unset($data->fallbackmessage_editor);
    }
}

/**
 * Gradebook callback: create or update the grade item for this scorecard.
 *
 * @param stdClass $scorecard Activity record.
 * @param mixed $grades Grades to write, or null.
 * @return int 0 on success.
 */
function scorecard_grade_item_update($scorecard, $grades = null) {
    // Phase 5a: implement.
    return 0;
}

/**
 * Gradebook callback: write final grades for one or all users.
 *
 * @param stdClass $scorecard Activity record.
 * @param int $userid Specific user id, or 0 for all users.
 * @param bool $nullifnone If true, insert null grade where none exists.
 * @return void
 */
function scorecard_update_grades($scorecard, $userid = 0, $nullifnone = true) {
    // Phase 5a: implement.
}
