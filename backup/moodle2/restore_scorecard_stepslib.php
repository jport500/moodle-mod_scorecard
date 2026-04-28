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
 * Restore steps for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete scorecard restore structure.
 *
 * Phase 1.4 shipped the root scorecard row processor only. Phase 5b.5
 * extends with processors for items, bands, attempts, and responses —
 * inverting the backup-side serialization from 5b.3 (items + bands)
 * and 5b.4 (attempts + responses, userinfo-gated).
 *
 * Per SPEC §9.4: "Restore mappings for item ids and band ids; preserve
 * attempt-side snapshots verbatim." The set_mapping/get_mappingid
 * framework operationalizes the id-mapping half (process_scorecard_item
 * + process_scorecard_band call set_mapping; process_scorecard_attempt
 * + process_scorecard_response call get_mappingid). Snapshot fields are
 * inserted directly from $data — verbatim preservation per SPEC §11.2.
 *
 * In-plugin cross-references (attempt.bandid → scorecard_bands;
 * response.itemid → scorecard_items) are NOT annotated at backup-time
 * (per mod_assign canonical convention; see backup_scorecard_stepslib).
 * They round-trip via raw ID serialization at backup + set_mapping at
 * restore (here) + get_mappingid at restore (here).
 */
class restore_scorecard_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the path elements expected in scorecard.xml.
     *
     * Items + bands are part of authoring structure (always restored).
     * Attempts + responses are user data — gated by the userinfo
     * restore setting per SPEC §9.4.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('scorecard', '/activity/scorecard');
        $paths[] = new restore_path_element('scorecard_item', '/activity/scorecard/items/item');
        $paths[] = new restore_path_element('scorecard_band', '/activity/scorecard/bands/band');

        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element(
                'scorecard_attempt',
                '/activity/scorecard/attempts/attempt'
            );
            $paths[] = new restore_path_element(
                'scorecard_response',
                '/activity/scorecard/attempts/attempt/responses/response'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process a {scorecard} element from the backup XML.
     *
     * @param array|stdClass $data
     */
    protected function process_scorecard($data) {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();

        // The id from the source backup is not used; insert_record assigns
        // a new id. apply_activity_instance() then maps that id to the
        // restored course module so the activity is visible in the course.
        $newid = $DB->insert_record('scorecard', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Process a {scorecard_items} element. Sets the in-plugin mapping so
     * process_scorecard_response can resolve response.itemid to the new
     * itemid.
     *
     * @param array|stdClass $data
     */
    protected function process_scorecard_item($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->scorecardid = $this->get_new_parentid('scorecard');

        $newid = $DB->insert_record('scorecard_items', $data);
        $this->set_mapping('scorecard_item', $oldid, $newid);
    }

    /**
     * Process a {scorecard_bands} element. Sets the in-plugin mapping so
     * process_scorecard_attempt can resolve attempt.bandid to the new
     * bandid.
     *
     * @param array|stdClass $data
     */
    protected function process_scorecard_band($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->scorecardid = $this->get_new_parentid('scorecard');

        $newid = $DB->insert_record('scorecard_bands', $data);
        $this->set_mapping('scorecard_band', $oldid, $newid);
    }

    /**
     * Process a {scorecard_attempts} element.
     *
     * Snapshot fields (bandlabelsnapshot, bandmessagesnapshot,
     * bandmessageformatsnapshot, totalscore, maxscore, percentage) are
     * inserted directly from $data — verbatim preservation per SPEC
     * §11.2 (no recomputation from current band state). bandid is
     * remapped through the in-plugin mapping set by
     * process_scorecard_band; null when no band matched at submit time
     * (preserved as null).
     *
     * @param array|stdClass $data
     */
    protected function process_scorecard_attempt($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->scorecardid = $this->get_new_parentid('scorecard');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!empty($data->bandid)) {
            $data->bandid = $this->get_mappingid('scorecard_band', $data->bandid);
        }

        $newid = $DB->insert_record('scorecard_attempts', $data);
        // Mapping needed so process_scorecard_response can resolve
        // response.attemptid via get_new_parentid('scorecard_attempt').
        $this->set_mapping('scorecard_attempt', $oldid, $newid);
    }

    /**
     * Process a {scorecard_responses} element. attemptid is the parent
     * (set via the path declaration; resolved through the mapping set
     * by process_scorecard_attempt). itemid is remapped through the
     * in-plugin mapping set by process_scorecard_item.
     *
     * @param array|stdClass $data
     */
    protected function process_scorecard_response($data) {
        global $DB;

        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('scorecard_attempt');
        $data->itemid = $this->get_mappingid('scorecard_item', $data->itemid);

        $DB->insert_record('scorecard_responses', $data);
    }

    /**
     * Process file areas after structure replay.
     *
     * Phase 5b.5: no additional file areas. items.prompt, bands.message,
     * fallbackmessage, and the snapshot fields all use maxfiles=0 in
     * their authoring editors (mod_form.php / item_form.php /
     * band_form.php), so no @@PLUGINFILE@@ tokens reach the database
     * for these fields and no file areas are registered against
     * scorecard_items, scorecard_bands, scorecard_attempts, or
     * scorecard_responses. Standard intro file area handled by parent.
     */
    protected function after_execute() {
        // Standard intro file area is handled by the parent. No
        // additional file areas (see class docblock for the maxfiles=0
        // architectural reason).
    }
}
