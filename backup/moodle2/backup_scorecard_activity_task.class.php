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
 * Backup task for mod_scorecard.
 *
 * Phase 1.4: settings-only backup. The activity row in {scorecard} is
 * captured in scorecard.xml. Items, bands, attempts, and responses are
 * NOT yet captured — those nested steps land in Phase 5b.
 *
 * Phase 5b prerequisite: implement nested backup steps for
 * scorecard_items, scorecard_bands, scorecard_attempts, scorecard_responses.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/scorecard/backup/moodle2/backup_scorecard_stepslib.php');

/**
 * Backup task: provides the steps to perform one complete backup of a
 * scorecard activity instance.
 */
class backup_scorecard_activity_task extends backup_activity_task {
    /**
     * No specific settings for this activity at Phase 1.4.
     */
    protected function define_my_settings() {
    }

    /**
     * Define the structure step that writes the scorecard.xml file.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_scorecard_activity_structure_step('scorecard_structure', 'scorecard.xml'));
    }

    /**
     * Encode URLs to the index and view scripts so they restore portably.
     *
     * @param string $content HTML content that may contain activity URLs.
     * @return string Content with URLs encoded.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Scorecard activity index URL: /mod/scorecard/index.php?id=COURSEID.
        $search = '/(' . $base . '\/mod\/scorecard\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@SCORECARDINDEX*$2@$', $content);

        // Scorecard activity view URL: /mod/scorecard/view.php?id=CMID.
        $search = '/(' . $base . '\/mod\/scorecard\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@SCORECARDVIEWBYID*$2@$', $content);

        return $content;
    }
}
