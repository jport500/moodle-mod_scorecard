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
 * Shared base for mod_scorecard backup + restore PHPUnit tests.
 *
 * Phase 5b.5: extracted from tests/backup/backup_test.php to share
 * fixture + backup-pipeline helpers across backup_test.php and
 * restore_test.php. Both subclasses inherit make_backup_fixture
 * (course + scorecard + items + bands + optional attempts +
 * responses) and backup_to_mbz (invoke backup_controller, return
 * the resulting .mbz stored_file). Each subclass adds its own
 * specialization on top:
 *  - backup_test extracts + parses scorecard.xml
 *  - restore_test feeds the .mbz to restore_controller
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Abstract base for backup + restore tests; provides shared fixture
 * builder and backup-pipeline helper.
 */
abstract class backup_testcase extends \advanced_testcase {
    /**
     * Build a course + scorecard + items + bands fixture, optionally
     * with attempts + responses for the supplied userids.
     *
     * Fixture layout: 1 visible item + 1 soft-deleted item + 1 visible
     * band + 1 soft-deleted band so SPEC §9.4 "soft-deleted included"
     * directive can be empirically pinned.
     *
     * When $userids is non-empty, creates one attempt per user with
     * distinctive snapshot field values (so SPEC §11.2 round-trip can
     * be verified verbatim) plus one response per item per attempt
     * (covering both the visible and the soft-deleted item, so the
     * response-to-soft-deleted-item case is exercised too).
     *
     * @param int[] $userids Optional userids to create attempts for.
     * @return array{cm: \stdClass, scorecard: \stdClass, items: array, bands: array, attempts: array, responses: array}
     */
    protected function make_backup_fixture(array $userids = []): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $scorecard = $this->getDataGenerator()->create_module('scorecard', (object)[
            'course' => $course->id,
            'name' => 'Backup test scorecard',
            'gradeenabled' => 1,
            'grade' => 20,
            'completionsubmit' => 1,
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $cm = \get_coursemodule_from_instance('scorecard', $scorecard->id, $course->id, false, MUST_EXIST);

        $now = time();

        // Visible item.
        $itemvisible = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Visible item prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Soft-deleted item.
        $itemdeleted = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Soft-deleted item prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 1,
            'sortorder' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Visible band.
        $bandvisible = (int)$DB->insert_record('scorecard_bands', (object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Visible band',
            'message' => 'Visible band message',
            'messageformat' => FORMAT_HTML,
            'sortorder' => 1,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Soft-deleted band.
        $banddeleted = (int)$DB->insert_record('scorecard_bands', (object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 11,
            'maxscore' => 20,
            'label' => 'Soft-deleted band',
            'message' => 'Soft-deleted band message',
            'messageformat' => FORMAT_HTML,
            'sortorder' => 2,
            'deleted' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Phase 5b.4: optional attempts + responses per userid. Snapshot
        // values are deliberately distinctive (and deliberately differ
        // from the visible band's current label/message) so the SPEC
        // §11.2 round-trip pin can verify exact preservation rather than
        // a re-render from current band state. One response per item
        // per attempt — including the soft-deleted item, since users may
        // have submitted before the item was soft-deleted.
        $attempts = [];
        $responses = [];
        foreach (array_values($userids) as $i => $userid) {
            $attemptid = (int)$DB->insert_record('scorecard_attempts', (object)[
                'scorecardid' => $scorecard->id,
                'userid' => $userid,
                'attemptnumber' => 1,
                'totalscore' => 8 + $i,
                'maxscore' => 10,
                'percentage' => 80.00 + $i,
                'bandid' => $bandvisible,
                'bandlabelsnapshot' => 'Frozen snapshot label ' . $i,
                'bandmessagesnapshot' => '<p>Frozen snapshot message ' . $i . '</p>',
                'bandmessageformatsnapshot' => FORMAT_HTML,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $attempts[$userid] = $attemptid;
            $responses[$userid] = [];
            foreach ([$itemvisible, $itemdeleted] as $itemid) {
                $responseid = (int)$DB->insert_record('scorecard_responses', (object)[
                    'attemptid' => $attemptid,
                    'itemid' => $itemid,
                    'responsevalue' => 8,
                    'timecreated' => $now,
                ]);
                $responses[$userid][$itemid] = $responseid;
            }
        }

        return [
            'cm' => $cm,
            'scorecard' => $scorecard,
            'items' => ['visible' => $itemvisible, 'deleted' => $itemdeleted],
            'bands' => ['visible' => $bandvisible, 'deleted' => $banddeleted],
            'attempts' => $attempts,
            'responses' => $responses,
        ];
    }

    /**
     * Run the backup pipeline on the given cm and return the resulting
     * .mbz as a stored_file. Caller decides whether to extract+parse
     * (backup_test) or feed to restore_controller (restore_test).
     *
     * Toggling $userinfo drives the root-level 'users' setting before
     * plan execution; that setting propagates to the activity-level
     * userinfo derived setting that gates attempts + responses sources.
     *
     * @param int $cmid Course module id.
     * @param bool $userinfo Whether to include user data in the backup.
     * @return \stored_file The .mbz backup_destination from the controller.
     */
    protected function backup_to_mbz(int $cmid, bool $userinfo = true): \stored_file {
        global $CFG, $USER;

        // The backup_controller requires a valid user; PHPUnit does not
        // authenticate one by default. setAdminUser populates $USER.
        $this->setAdminUser();

        // Avoid file-logger contention during the backup.
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($userinfo);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        return $file;
    }
}
