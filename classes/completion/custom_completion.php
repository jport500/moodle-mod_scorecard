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

declare(strict_types=1);

namespace mod_scorecard\completion;

use core_completion\activity_custom_completion;

/**
 * Activity custom completion subclass for mod_scorecard.
 *
 * SPEC §9.3 + Phase 5a.4: the only custom rule is completionsubmit —
 * the activity is marked complete when the learner has at least one
 * scorecard_attempts row. Retakes do not change the completion state
 * once it has been set (any submission, ever, satisfies the rule).
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetch the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        // Only completionsubmit is exposed as a custom rule. The user is
        // "complete" iff at least one attempt exists for them on this
        // scorecard. Soft-deleted items, band edits, etc. do not
        // un-complete a prior submission — completion is a one-way latch.
        $status = $DB->record_exists('scorecard_attempts', [
            'scorecardid' => $this->cm->instance,
            'userid' => $this->userid,
        ]);

        return $status ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionsubmit',
        ];
    }

    /**
     * Returns an associative array of custom completion rule descriptions.
     *
     * @return array Map of rule name => description string.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionsubmit' => get_string('completiondetail:submit', 'mod_scorecard'),
        ];
    }

    /**
     * Returns an array of all completion rules in display order.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionsubmit',
        ];
    }
}
