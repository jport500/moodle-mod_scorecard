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
 * Privacy provider for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for mod_scorecard.
 *
 * Phase 1.3 declares plugin metadata only. The data-subject methods
 * (export, delete, get_contexts_for_userid, get_users_in_context) ship as
 * type-correct stubs returning empty results; Phase 5b replaces each stub
 * body with its real implementation.
 *
 * Phase 5b prerequisite: replace stub bodies in this file; verify no
 * `Phase 5b: implement` comments remain before tagging.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declare the personal data this plugin stores.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('scorecard_attempts', [
            'userid' => 'privacy:metadata:scorecard_attempts:userid',
            'totalscore' => 'privacy:metadata:scorecard_attempts:totalscore',
            'maxscore' => 'privacy:metadata:scorecard_attempts:maxscore',
            'percentage' => 'privacy:metadata:scorecard_attempts:percentage',
            'bandlabelsnapshot' => 'privacy:metadata:scorecard_attempts:bandlabelsnapshot',
            'bandmessagesnapshot' => 'privacy:metadata:scorecard_attempts:bandmessagesnapshot',
            'timecreated' => 'privacy:metadata:scorecard_attempts:timecreated',
        ], 'privacy:metadata:scorecard_attempts');

        $collection->add_database_table('scorecard_responses', [
            'attemptid' => 'privacy:metadata:scorecard_responses:attemptid',
            'responsevalue' => 'privacy:metadata:scorecard_responses:responsevalue',
            'timecreated' => 'privacy:metadata:scorecard_responses:timecreated',
        ], 'privacy:metadata:scorecard_responses');

        return $collection;
    }

    /**
     * Get all contexts that contain personal data for the specified user.
     *
     * @param int $userid The user to look for.
     * @return contextlist Contexts where this user has scorecard data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
        // Phase 5b: implement. Resolve module contexts where this user has
        // any rows in scorecard_attempts (and via attempts, scorecard_responses).
    }

    /**
     * Get all users with personal data in the specified context.
     *
     * @param userlist $userlist The userlist to add user IDs into.
     */
    public static function get_users_in_context(userlist $userlist): void {
        // Phase 5b: implement. Add userids of users with attempts in the
        // module context attached to this scorecard instance.
    }

    /**
     * Export all personal data for the user in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        // Phase 5b: implement. Export attempts (with snapshotted band label
        // and message) and per-item responses, scoped to the approved contexts.
    }

    /**
     * Delete all personal data for all users in the specified context.
     *
     * @param context $context The context to delete in.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        // Phase 5b: implement. Delete attempts + responses for all users in
        // this scorecard instance; preserve the scorecard, items, and bands.
    }

    /**
     * Delete personal data for the specified set of users.
     *
     * @param approved_userlist $userlist The approved userlist to delete data for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        // Phase 5b: implement. Delete attempts + responses for the listed
        // users in the userlist's context; preserve the scorecard configuration.
    }

    /**
     * Delete all personal data for the specified user across the listed contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        // Phase 5b: implement. Delete attempts + responses for the user
        // across the approved contexts; preserve the scorecard configuration.
    }
}
