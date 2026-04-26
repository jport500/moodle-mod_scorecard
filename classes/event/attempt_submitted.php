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
 * Fired after a scorecard attempt is committed to the database.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\event;

/**
 * Triggered by scorecard_handle_submission() after the transaction commits.
 *
 * The handler creates this event with the new attempt id as objectid and
 * triggers it strictly after $transaction->allow_commit() returns, so any
 * subscriber observing this event is guaranteed the attempt + response rows
 * are already persisted.
 */
class attempt_submitted extends \core\event\base {
    /**
     * Initialise the standard event metadata.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'scorecard_attempts';
    }

    /**
     * Human-readable event name shown in event lists and the events report.
     */
    public static function get_name(): string {
        return get_string('event:attempt_submitted', 'mod_scorecard');
    }

    /**
     * Free-form description rendered in the events report.
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' submitted scorecard attempt with id '"
            . "{$this->objectid}' for the scorecard with course module id "
            . "'{$this->contextinstanceid}'.";
    }

    /**
     * URL that contextualises the event in the UI (the activity view page).
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/scorecard/view.php', ['id' => $this->contextinstanceid]);
    }
}
