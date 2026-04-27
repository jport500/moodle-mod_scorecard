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
 * Tests for mod_scorecard's Phase 4.4 CSV export helpers.
 *
 * Targets the data-shape helpers (scorecard_get_export_item_set +
 * scorecard_build_export_data) directly. csv_export_writer's streaming layer
 * is trusted as core Moodle infrastructure -- standard pattern in mod_quiz,
 * mod_feedback. Capability-gate behavior is structurally protected by
 * tests/access_test.php's SPEC §9.1 matrix from Commit A.
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard;

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scorecard/lib.php');
require_once($CFG->dirroot . '/mod/scorecard/locallib.php');

/**
 * Phase 4.4 export-helper tests.
 */
#[CoversNothing]
final class export_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture and return (scorecard, cm, context).
     */
    private function create_scorecard(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('scorecard', [
            'course' => $course->id,
            'name' => 'Export fixture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'scalemin' => 1,
            'scalemax' => 10,
            'displaystyle' => 'radio',
            'lowlabel' => '',
            'highlabel' => '',
            'allowretakes' => 1,
            'showresult' => 1,
            'showpercentage' => 0,
            'showitemsummary' => 1,
            'fallbackmessage_editor' => ['text' => 'Fallback.', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ]);
        $scorecard = $DB->get_record('scorecard', ['id' => $module->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('scorecard', $scorecard->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        return [$scorecard, $cm, $context];
    }

    /**
     * Insert an attempt row directly. Mirrors report_test's helper.
     */
    private function insert_attempt(
        int $scorecardid,
        int $userid,
        int $attemptnumber,
        int $totalscore,
        int $maxscore,
        float $percentage,
        ?string $bandlabel,
        ?int $timecreated = null
    ): int {
        global $DB;
        $now = $timecreated ?? time();
        return (int)$DB->insert_record('scorecard_attempts', (object)[
            'scorecardid' => $scorecardid,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
            'totalscore' => $totalscore,
            'maxscore' => $maxscore,
            'percentage' => $percentage,
            'bandid' => $bandlabel !== null ? 0 : null,
            'bandlabelsnapshot' => $bandlabel,
            'bandmessagesnapshot' => $bandlabel !== null ? 'Body.' : 'Fallback.',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a response row directly.
     */
    private function insert_response(int $attemptid, int $itemid, int $value): int {
        global $DB;
        return (int)$DB->insert_record('scorecard_responses', (object)[
            'attemptid' => $attemptid,
            'itemid' => $itemid,
            'responsevalue' => $value,
            'timecreated' => time(),
        ]);
    }

    /**
     * Item set ordering: live items first by sortorder ASC, then deleted items
     * at the end by sortorder ASC. Pinned-position assertions catch any future
     * reorder regression.
     */
    public function test_get_export_item_set_orders_live_then_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $itema = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Alpha (live)',
            'promptformat' => FORMAT_HTML,
        ]);
        $itemb = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Beta (deleted)',
            'promptformat' => FORMAT_HTML,
        ]);
        $itemc = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Gamma (live)',
            'promptformat' => FORMAT_HTML,
        ]);
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemb]);

        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 18, 30, 60.0, 'Strong');
        $this->insert_response($attemptid, $itema, 7);
        $this->insert_response($attemptid, $itemb, 5);
        $this->insert_response($attemptid, $itemc, 6);

        $responsesbyattempt = scorecard_get_attempt_responses([$attemptid]);
        $itemset = scorecard_get_export_item_set($responsesbyattempt);

        $this->assertCount(3, $itemset);
        // Live items (alpha sortorder=1, gamma sortorder=3) come first by sortorder.
        $this->assertSame((int)$itema, (int)$itemset[0]->id);
        $this->assertSame(0, (int)$itemset[0]->deleted);
        $this->assertSame((int)$itemc, (int)$itemset[1]->id);
        $this->assertSame(0, (int)$itemset[1]->deleted);
        // Deleted item (beta sortorder=2) goes last.
        $this->assertSame((int)$itemb, (int)$itemset[2]->id);
        $this->assertSame(1, (int)$itemset[2]->deleted);
    }

    /**
     * Empty input returns empty array. Defensive against report.php's
     * "no attempts" branch passing an empty map.
     */
    public function test_get_export_item_set_empty_input_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], scorecard_get_export_item_set([]));
    }

    /**
     * Headers cover: identity (3 always-shown) + identity-policy fields +
     * 6 attempt-metadata + N per-item. Order assertions pin the SPEC §10.4
     * column shape.
     */
    public function test_build_export_data_emits_required_headers(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Pace',
            'promptformat' => FORMAT_HTML,
        ]);
        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 7, 10, 70.0, 'Strong');
        $this->insert_response($attemptid, $itemid, 7);

        $attempts = scorecard_get_attempts($context, (int)$scorecard->id);
        $responses = scorecard_get_attempt_responses([$attemptid]);
        $itemset = scorecard_get_export_item_set($responses);
        $data = scorecard_build_export_data($scorecard, $attempts, $responses, $itemset, []);

        $this->assertArrayHasKey('headers', $data);
        $expectedheaders = [
            get_string('report:col:fullname', 'mod_scorecard'),
            get_string('report:col:userid', 'mod_scorecard'),
            get_string('report:col:username', 'mod_scorecard'),
            get_string('report:col:attemptnumber', 'mod_scorecard'),
            get_string('report:col:submitted', 'mod_scorecard'),
            get_string('report:col:totalscore', 'mod_scorecard'),
            get_string('report:col:maxscore', 'mod_scorecard'),
            get_string('report:col:percentage', 'mod_scorecard'),
            get_string('report:col:band', 'mod_scorecard'),
            'Pace',
        ];
        $this->assertSame($expectedheaders, $data['headers']);
    }

    /**
     * Soft-deleted item header gets the [deleted] prefix; live item header
     * stays plain. Per Phase 4 kickoff Q7c disposition: "latest snapshot"
     * collapses to "current prompt" because soft-delete preserves the row.
     */
    public function test_build_export_data_deleted_item_header_has_prefix(): void {
        global $DB;
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $itemlive = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Live prompt',
            'promptformat' => FORMAT_HTML,
        ]);
        $itemdel = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Removed prompt',
            'promptformat' => FORMAT_HTML,
        ]);

        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 12, 20, 60.0, null);
        $this->insert_response($attemptid, $itemlive, 7);
        $this->insert_response($attemptid, $itemdel, 5);
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemdel]);

        $attempts = scorecard_get_attempts($context, (int)$scorecard->id);
        $responses = scorecard_get_attempt_responses([$attemptid]);
        $itemset = scorecard_get_export_item_set($responses);
        $data = scorecard_build_export_data($scorecard, $attempts, $responses, $itemset, []);

        $deletedprefix = get_string('report:detail:deletedprefix', 'mod_scorecard');
        // Live item header is plain.
        $this->assertContains('Live prompt', $data['headers']);
        // Deleted item header carries the prefix.
        $this->assertContains($deletedprefix . 'Removed prompt', $data['headers']);
        // Order: live first, deleted last (within the per-item suffix block).
        $livepos = array_search('Live prompt', $data['headers'], true);
        $delpos = array_search($deletedprefix . 'Removed prompt', $data['headers'], true);
        $this->assertNotFalse($livepos);
        $this->assertNotFalse($delpos);
        $this->assertLessThan($delpos, $livepos);
    }

    /**
     * Row count matches attempt count (one row per attempt).
     */
    public function test_build_export_data_row_count_matches_attempts(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Q1',
            'promptformat' => FORMAT_HTML,
        ]);

        $a1 = $this->insert_attempt((int)$scorecard->id, (int)$user1->id, 1, 5, 10, 50.0, 'Mid');
        $a2 = $this->insert_attempt((int)$scorecard->id, (int)$user2->id, 1, 8, 10, 80.0, 'Strong');
        $a3 = $this->insert_attempt((int)$scorecard->id, (int)$user1->id, 2, 9, 10, 90.0, 'Strong');
        foreach ([$a1, $a2, $a3] as $aid) {
            $this->insert_response($aid, $itemid, 5);
        }

        $attempts = scorecard_get_attempts($context, (int)$scorecard->id);
        $responses = scorecard_get_attempt_responses([$a1, $a2, $a3]);
        $itemset = scorecard_get_export_item_set($responses);
        $data = scorecard_build_export_data($scorecard, $attempts, $responses, $itemset, []);

        $this->assertCount(3, $data['rows']);
    }

    /**
     * Cell value for an attempt with a response is the responsevalue cast to
     * string; cell for an attempt with NO response to a still-alive item is
     * the empty string. Per Phase 4 kickoff Q7d disposition: blank cell for
     * the audit-honest "this attempt did not answer this item" case.
     */
    public function test_build_export_data_cells_value_or_blank(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $item1 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Q1',
            'promptformat' => FORMAT_HTML,
        ]);
        $item2 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Q2',
            'promptformat' => FORMAT_HTML,
        ]);

        // User A answers both; User B answers only item 1.
        $aida = $this->insert_attempt((int)$scorecard->id, (int)$usera->id, 1, 12, 20, 60.0, 'Strong');
        $aidb = $this->insert_attempt((int)$scorecard->id, (int)$userb->id, 1, 7, 20, 35.0, null);
        $this->insert_response($aida, $item1, 7);
        $this->insert_response($aida, $item2, 5);
        $this->insert_response($aidb, $item1, 7);

        $attempts = scorecard_get_attempts($context, (int)$scorecard->id);
        $responses = scorecard_get_attempt_responses([$aida, $aidb]);
        $itemset = scorecard_get_export_item_set($responses);
        $data = scorecard_build_export_data($scorecard, $attempts, $responses, $itemset, []);

        // Identify the per-item column positions by header lookup.
        $q1pos = array_search('Q1', $data['headers'], true);
        $q2pos = array_search('Q2', $data['headers'], true);
        $this->assertNotFalse($q1pos);
        $this->assertNotFalse($q2pos);

        // Find each row by userid (row order is userid ASC, so lower-id row first).
        $rowfora = null;
        $rowforb = null;
        foreach ($data['rows'] as $row) {
            if ((int)$row[1] === (int)$usera->id) {
                $rowfora = $row;
            } else if ((int)$row[1] === (int)$userb->id) {
                $rowforb = $row;
            }
        }
        $this->assertNotNull($rowfora);
        $this->assertNotNull($rowforb);

        // User A: both cells populated.
        $this->assertSame('7', $rowfora[$q1pos]);
        $this->assertSame('5', $rowfora[$q2pos]);
        // User B: Q1 populated, Q2 blank.
        $this->assertSame('7', $rowforb[$q1pos]);
        $this->assertSame('', $rowforb[$q2pos]);
    }

    /**
     * Band cell: snapshotted label when present; the no-band placeholder
     * when bandlabelsnapshot is null.
     */
    public function test_build_export_data_band_cell_handles_snapshot_and_fallback(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Q',
            'promptformat' => FORMAT_HTML,
        ]);

        $aida = $this->insert_attempt((int)$scorecard->id, (int)$usera->id, 1, 8, 10, 80.0, 'Strong');
        $aidb = $this->insert_attempt((int)$scorecard->id, (int)$userb->id, 1, 2, 10, 20.0, null);
        $this->insert_response($aida, $itemid, 8);
        $this->insert_response($aidb, $itemid, 2);

        $attempts = scorecard_get_attempts($context, (int)$scorecard->id);
        $responses = scorecard_get_attempt_responses([$aida, $aidb]);
        $itemset = scorecard_get_export_item_set($responses);
        $data = scorecard_build_export_data($scorecard, $attempts, $responses, $itemset, []);

        $bandpos = array_search(get_string('report:col:band', 'mod_scorecard'), $data['headers'], true);
        $this->assertNotFalse($bandpos);

        $bandcells = array_map(fn($row) => $row[$bandpos], $data['rows']);
        $this->assertContains('Strong', $bandcells);
        $this->assertContains(get_string('report:col:noband', 'mod_scorecard'), $bandcells);
    }

    /**
     * Group filter integration: when an active group filter restricts $attempts
     * to one user's row, the export contains exactly that user's row -- the
     * helper renders what it's given, so feeding it the group-filtered fetch
     * naturally produces the group-filtered CSV. End-to-end composition check
     * (Phase 4 kickoff Q3 disposition: WYSIWYG export).
     */
    public function test_build_export_data_with_group_filter_returns_filtered_rows(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $course = (object)['id' => (int)$scorecard->course];

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'B']);
        $usera = $this->getDataGenerator()->create_user(['username' => 'alpha']);
        $userb = $this->getDataGenerator()->create_user(['username' => 'beta']);

        $this->getDataGenerator()->enrol_user($usera->id, $course->id);
        $this->getDataGenerator()->enrol_user($userb->id, $course->id);
        $this->getDataGenerator()->create_group_member([
            'groupid' => (int)$groupa->id,
            'userid' => $usera->id,
        ]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => (int)$groupb->id,
            'userid' => $userb->id,
        ]);

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Q',
            'promptformat' => FORMAT_HTML,
        ]);
        $aida = $this->insert_attempt((int)$scorecard->id, (int)$usera->id, 1, 8, 10, 80.0, 'Strong');
        $aidb = $this->insert_attempt((int)$scorecard->id, (int)$userb->id, 1, 6, 10, 60.0, 'Mid');
        $this->insert_response($aida, $itemid, 8);
        $this->insert_response($aidb, $itemid, 6);

        // Filter to group A only.
        $attempts = scorecard_get_attempts($context, (int)$scorecard->id, (int)$groupa->id);
        $responses = scorecard_get_attempt_responses(
            array_map(fn($a) => (int)$a->attemptid, $attempts)
        );
        $itemset = scorecard_get_export_item_set($responses);
        $data = scorecard_build_export_data($scorecard, $attempts, $responses, $itemset, []);

        $this->assertCount(1, $data['rows']);
        $this->assertSame('alpha', $data['rows'][0][2]);
    }
}
