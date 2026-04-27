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
 * Phase-honest declarations: features flip to true as their implementations
 * land. Phase 1.4 enables FEATURE_BACKUP_MOODLE2 (settings-only backup;
 * nested item/band/attempt/response capture lands in Phase 5b). Phase 5a.1
 * enables FEATURE_GRADE_HAS_GRADE (gradebook integration). Phase 5a.4
 * enables FEATURE_COMPLETION_TRACKS_VIEWS and FEATURE_COMPLETION_HAS_RULES
 * (the completionsubmit custom rule per SPEC §9.3). FEATURE_GRADE_OUTCOMES
 * is v1.1+ scope.
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
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_GRADE_HAS_GRADE => true,
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
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $now = time();
    $data->timecreated = $now;
    $data->timemodified = $now;

    scorecard_flatten_editor_fields($data);

    $data->id = $DB->insert_record('scorecard', $data);

    // Phase 5a.1: register the grade item for this scorecard. The call is
    // user-visible only when gradeenabled=1; with gradeenabled=0 (the
    // default), the grade item exists with gradetype=NONE and the
    // gradebook hides the column.
    scorecard_grade_item_update($data);

    return (int)$data->id;
}

/**
 * Update a scorecard activity instance.
 *
 * @param stdClass $data Form data from mod_form; $data->instance is the scorecard.id.
 * @param mod_scorecard_mod_form|null $mform Form instance (unused in Phase 1).
 * @return bool
 */
function scorecard_update_instance($data, $mform = null) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $data->id = $data->instance;
    $data->timemodified = time();

    scorecard_flatten_editor_fields($data);

    $result = $DB->update_record('scorecard', $data);

    // Phase 5a.1: push grade item changes (gradeenabled toggle, grademax
    // edit) and re-evaluate user grades so any operator-side change is
    // reflected in the gradebook.
    scorecard_update_grades($data);

    return $result;
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
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $scorecard = $DB->get_record('scorecard', ['id' => $id]);
    if (!$scorecard) {
        return false;
    }

    // Phase 5a.1: delete the grade item BEFORE the parent row so the
    // grade item delete call has access to scorecard->course / id.
    scorecard_grade_item_delete($scorecard);

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
 * Called by Moodle's grade-update lifecycle and by the activity lifecycle
 * hooks in scorecard_add_instance / scorecard_update_instance / submit-time
 * persistence (Phase 5a.3).
 *
 * gradetype gating per SPEC §9.2: when $scorecard->gradeenabled is 0 (the
 * default), gradetype is GRADE_TYPE_NONE — the grade item exists but
 * does not show a grade column in the gradebook. When gradeenabled is 1,
 * gradetype is GRADE_TYPE_VALUE with grademax read from $scorecard->grade
 * (or auto-computed via scorecard_compute_auto_grademax when grade=0).
 *
 * Toggle behavior (gradeenabled=1 → 0 → 1): grade history is preserved
 * across the toggle. On re-enable, grademax uses the current
 * $scorecard->grade value read at call time, not snapshotted at the time
 * of original enable. The operator's most recent intent for grademax
 * wins; this is the correct behavior for a fields-edit-and-save workflow.
 *
 * @param stdClass $scorecard Activity record (with $scorecard->course set).
 * @param mixed $grades Optional grade(s) to write; the literal string
 *                      'reset' resets all gradebook entries.
 * @return int 0 on success, error code otherwise.
 */
function scorecard_grade_item_update($scorecard, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => $scorecard->name];
    if (property_exists($scorecard, 'cmidnumber')) {
        $params['idnumber'] = $scorecard->cmidnumber;
    }

    if (empty($scorecard->gradeenabled)) {
        $params['gradetype'] = GRADE_TYPE_NONE;
    } else {
        $grademax = (int)$scorecard->grade;
        if ($grademax === 0) {
            $grademax = scorecard_compute_auto_grademax($scorecard);
        }
        if ($grademax > 0) {
            $params['gradetype'] = GRADE_TYPE_VALUE;
            $params['grademax'] = $grademax;
            $params['grademin'] = 0;
        } else {
            // No items yet but gradeenabled=1; grade item exists with
            // gradetype=NONE until items are added. Phase 5a.2's items
            // lifecycle hook recomputes when the first item lands.
            $params['gradetype'] = GRADE_TYPE_NONE;
        }
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/scorecard',
        (int)$scorecard->course,
        'mod',
        'scorecard',
        (int)$scorecard->id,
        0,
        $grades,
        $params
    );
}

/**
 * Gradebook callback: delete the grade item for this scorecard.
 *
 * Called from scorecard_delete_instance before the parent scorecard row
 * is deleted, so the grade item delete has access to scorecard->course
 * and scorecard->id.
 *
 * @param stdClass $scorecard Activity record.
 * @return int 0 on success, error code otherwise.
 */
function scorecard_grade_item_delete($scorecard) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        'mod/scorecard',
        (int)$scorecard->course,
        'mod',
        'scorecard',
        (int)$scorecard->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Gradebook callback: fetch latest-attempt grades per user for this scorecard.
 *
 * Per SPEC §9.2 (Decision v0.4.2), the grade method is
 * latest-attempt-overwrites. The latest attempt is identified by MAX(id)
 * on scorecard_attempts — id is auto-incrementing and submissions are
 * time-ordered, so id-monotonic equals time-monotonic. If a future feature
 * can reorder attempts (e.g., admin tools that reset attempt sequences),
 * this assumption breaks; switch to ORDER BY timecreated DESC LIMIT 1
 * per user at that point.
 *
 * Returns the gradebook-canonical shape: map of userid => grade_object
 * with rawgrade, dategraded, datesubmitted. rawgrade is attempt.totalscore
 * directly (not percentage; per SPEC §9.2's "raw grade" directive).
 *
 * @param stdClass $scorecard Activity record.
 * @param int $userid Specific user id, or 0 for all users.
 * @return array Map of userid => grade_object; empty array when no attempts.
 */
function scorecard_get_user_grades($scorecard, $userid = 0) {
    global $DB;

    $params = [
        'scorecardid' => (int)$scorecard->id,
        'scorecardid2' => (int)$scorecard->id,
    ];
    $userfilter = '';
    if ($userid > 0) {
        $userfilter = ' AND a.userid = :userid';
        $params['userid'] = (int)$userid;
    }

    $sql = "SELECT a.userid,
                   a.totalscore AS rawgrade,
                   a.timecreated AS dategraded,
                   a.timecreated AS datesubmitted
              FROM {scorecard_attempts} a
             WHERE a.scorecardid = :scorecardid
               AND a.id IN (
                   SELECT MAX(id)
                     FROM {scorecard_attempts}
                    WHERE scorecardid = :scorecardid2
                    GROUP BY userid
               ){$userfilter}";

    $rows = $DB->get_records_sql($sql, $params);
    $grades = [];
    foreach ($rows as $row) {
        $grades[(int)$row->userid] = (object)[
            'userid' => (int)$row->userid,
            'rawgrade' => (float)$row->rawgrade,
            'dategraded' => (int)$row->dategraded,
            'datesubmitted' => (int)$row->datesubmitted,
        ];
    }
    return $grades;
}

/**
 * Gradebook callback: write final grades for one or all users.
 *
 * Wrapper around scorecard_grade_item_update + scorecard_get_user_grades,
 * mirroring mod_assign convention. When gradeenabled is 0, just refreshes
 * the grade item (gradetype=NONE; no grades to write). When gradeenabled
 * is 1 and attempts exist, writes the latest-per-user grades. When
 * gradeenabled is 1 but no attempts exist yet, refreshes the grade item
 * shell.
 *
 * @param stdClass $scorecard Activity record.
 * @param int $userid Specific user id, or 0 for all users.
 * @param bool $nullifnone If true, insert null grade where none exists
 *                          (currently unused: absence of attempt means
 *                          no gradebook entry, which is the correct
 *                          semantic for self-assessments).
 * @return void
 */
function scorecard_update_grades($scorecard, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (empty($scorecard->gradeenabled)) {
        scorecard_grade_item_update($scorecard);
    } else if ($grades = scorecard_get_user_grades($scorecard, $userid)) {
        scorecard_grade_item_update($scorecard, $grades);
    } else {
        scorecard_grade_item_update($scorecard);
    }
}
