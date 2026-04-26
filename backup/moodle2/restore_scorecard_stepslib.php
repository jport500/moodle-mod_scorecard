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
 * Phase 1.4: only the {scorecard} row is restored. apply_activity_instance()
 * wires the new row to the new course module so the activity is visible
 * in the restored course. Phase 5b adds processing for items, bands,
 * attempts, and responses.
 */
class restore_scorecard_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the path elements expected in scorecard.xml.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('scorecard', '/activity/scorecard');

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
     * Process file areas after structure replay.
     *
     * Phase 1.4: nothing to process (fallbackmessage uses maxfiles=0; intro
     * uses the standard mod_intro file area handled by the parent class).
     * Phase 5b: process items, bands, attempts, responses here.
     */
    protected function after_execute() {
        // Standard intro file area is handled by the parent. No additional
        // file areas in Phase 1.4.
    }
}
