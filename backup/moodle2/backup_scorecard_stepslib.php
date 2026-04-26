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
 * Backup steps for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete scorecard structure for backup.
 *
 * Phase 1.4: only the {scorecard} row is captured (settings round-trip).
 * Items, bands, attempts, and responses are NOT yet backed up — those
 * nested elements land in Phase 5b.
 *
 * Phase 5b prerequisite: add nested backup_nested_element children for
 * scorecard_items, scorecard_bands, scorecard_attempts (with snapshot
 * columns) and scorecard_responses; set their sources; preserve the
 * attempt-side band snapshot verbatim on restore.
 */
class backup_scorecard_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the activity backup structure.
     *
     * @return backup_nested_element The root activity element.
     */
    protected function define_structure() {
        // Root element: the scorecard row. Excludes id (handled by backup
        // framework) and course (replaced with new courseid by restore).
        $scorecard = new backup_nested_element('scorecard', ['id'], [
            'name', 'intro', 'introformat',
            'scalemin', 'scalemax', 'displaystyle',
            'lowlabel', 'highlabel',
            'allowretakes', 'showresult', 'showpercentage', 'showitemsummary',
            'fallbackmessage', 'fallbackmessageformat',
            'gradeenabled', 'grade',
            'timecreated', 'timemodified',
        ]);

        // Source: the {scorecard} row keyed by activity id.
        $scorecard->set_source_table('scorecard', ['id' => backup::VAR_ACTIVITYID]);

        // Annotate file areas. Phase 1.4 has no file areas in fallbackmessage
        // (editor has maxfiles=0); kept here for completeness with a no-op call.

        return $this->prepare_activity_structure($scorecard);
    }
}
