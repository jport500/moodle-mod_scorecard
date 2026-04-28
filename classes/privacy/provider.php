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
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

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
            'itemid' => 'privacy:metadata:scorecard_responses:itemid',
            'responsevalue' => 'privacy:metadata:scorecard_responses:responsevalue',
            'timecreated' => 'privacy:metadata:scorecard_responses:timecreated',
        ], 'privacy:metadata:scorecard_responses');

        return $collection;
    }

    /**
     * Get all contexts that contain personal data for the specified user.
     *
     * Resolves to module contexts where the user has rows in
     * scorecard_attempts. scorecard_responses follow attempts via attemptid
     * — joining attempts is sufficient to capture the full per-user data
     * graph for context discovery.
     *
     * @param int $userid The user to look for.
     * @return contextlist Contexts where this user has scorecard data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON cm.module = m.id AND m.name = :modulename
                  JOIN {scorecard} s ON cm.instance = s.id
                  JOIN {context} ctx ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {scorecard_attempts} sa ON s.id = sa.scorecardid
                 WHERE sa.userid = :userid";

        $params = [
            'modulename' => 'scorecard',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Get all users with personal data in the specified context.
     *
     * @param userlist $userlist The userlist to add user IDs into.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $sql = "SELECT sa.userid
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {scorecard} s ON s.id = cm.instance
                  JOIN {scorecard_attempts} sa ON s.id = sa.scorecardid
                 WHERE ctx.id = :contextid AND ctx.contextlevel = :contextlevel";

        $params = [
            'modulename' => 'scorecard',
            'contextlevel' => CONTEXT_MODULE,
            'contextid' => $context->id,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all personal data for the user in the specified contexts.
     *
     * Per SPEC §9.5: per-context per-attempt export with snapshotted band
     * label/message AND current item prompt text (with [deleted] prefix
     * for soft-deleted items). Per-attempt subcontext lets users navigate
     * the export package by attempt for retakes-on scorecards.
     *
     * Response fetch uses LEFT JOIN on scorecard_items rather than INNER
     * JOIN: SPEC §4.5 + the lifecycle gate block hard-delete of items
     * once attempts exist, so the item row should always be present, but
     * defensive LEFT JOIN protects against direct DB tampering, backup/
     * restore mismatch, or any future invariant violation. Responses to
     * items missing entirely render with [deleted] prefix and empty
     * prompt (graceful degradation rather than silent omission, which
     * would be a privacy violation).
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $userid = (int)$user->id;
        $deletedprefix = get_string('report:detail:deletedprefix', 'mod_scorecard');
        $attemptslabel = get_string('privacy:export:attempts', 'mod_scorecard');

        $responsesql = "SELECT r.id, r.itemid, r.responsevalue, r.timecreated,
                               i.prompt, i.promptformat, i.deleted AS itemdeleted
                          FROM {scorecard_responses} r
                     LEFT JOIN {scorecard_items} i ON i.id = r.itemid
                         WHERE r.attemptid = :attemptid
                      ORDER BY r.id";

        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('scorecard', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            $attempts = $DB->get_records('scorecard_attempts', [
                'scorecardid' => (int)$cm->instance,
                'userid' => $userid,
            ], 'attemptnumber ASC');

            if (empty($attempts)) {
                continue;
            }

            foreach ($attempts as $attempt) {
                $responses = $DB->get_records_sql($responsesql, [
                    'attemptid' => (int)$attempt->id,
                ]);

                $responsesdata = [];
                foreach ($responses as $r) {
                    $promptdisplay = '';
                    if ($r->prompt !== null) {
                        $promptdisplay = format_text(
                            (string)$r->prompt,
                            (int)($r->promptformat ?? FORMAT_HTML),
                            ['context' => $context]
                        );
                    }
                    if (!empty($r->itemdeleted) || $r->prompt === null) {
                        $promptdisplay = $deletedprefix . $promptdisplay;
                    }
                    $responsesdata[] = (object)[
                        'prompt' => $promptdisplay,
                        'response' => (int)$r->responsevalue,
                        'timecreated' => transform::datetime((int)$r->timecreated),
                    ];
                }

                $attemptdata = (object)[
                    'attemptnumber' => (int)$attempt->attemptnumber,
                    'totalscore' => (int)$attempt->totalscore,
                    'maxscore' => (int)$attempt->maxscore,
                    'percentage' => (float)$attempt->percentage,
                    'bandlabelsnapshot' => (string)($attempt->bandlabelsnapshot ?? ''),
                    'bandmessagesnapshot' => (string)($attempt->bandmessagesnapshot ?? ''),
                    'timecreated' => transform::datetime((int)$attempt->timecreated),
                    'responses' => $responsesdata,
                ];

                $subcontext = [
                    $attemptslabel,
                    "Attempt {$attempt->attemptnumber}",
                ];
                writer::with_context($context)->export_data($subcontext, $attemptdata);
            }
        }
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
