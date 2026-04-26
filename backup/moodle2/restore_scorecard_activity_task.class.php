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
 * Restore task for mod_scorecard.
 *
 * Phase 1.4: settings-only restore. Re-creates the {scorecard} row from
 * scorecard.xml and wires it to the new course module via
 * apply_activity_instance(). Items, bands, attempts, and responses are
 * NOT yet restored — those land in Phase 5b alongside the matching
 * backup steps.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/scorecard/backup/moodle2/restore_scorecard_stepslib.php');

/**
 * Restore task: provides the steps to perform one complete restore of a
 * scorecard activity instance.
 */
class restore_scorecard_activity_task extends restore_activity_task {
    /**
     * No specific settings for this activity at Phase 1.4.
     */
    protected function define_my_settings() {
    }

    /**
     * Define the structure step that reads scorecard.xml.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_scorecard_activity_structure_step('scorecard_structure', 'scorecard.xml'));
    }

    /**
     * Define the contents whose links must be decoded on restore.
     *
     * @return array
     */
    public static function define_decode_contents() {
        $contents = [];

        // The intro and fallbackmessage fields may contain @@PLUGINFILE@@
        // references and inter-activity links that need decoding on restore.
        $contents[] = new restore_decode_content('scorecard', ['intro', 'fallbackmessage'], 'scorecard');

        return $contents;
    }

    /**
     * Define decoding rules for inter-activity links targeting this module.
     *
     * @return array
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('SCORECARDINDEX', '/mod/scorecard/index.php?id=$1', 'course');
        $rules[] = new restore_decode_rule('SCORECARDVIEWBYID', '/mod/scorecard/view.php?id=$1', 'course_module');

        return $rules;
    }

    /**
     * Define restore log rules. None for Phase 1.4 (no scorecard log events
     * are emitted yet; Phase 3 adds learner submission logging).
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
