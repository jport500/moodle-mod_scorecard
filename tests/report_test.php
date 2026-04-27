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
 * Tests for mod_scorecard's Phase 4.1 report data layer + renderer smoke.
 *
 * Covers scorecard_get_attempts() ordering, identity-field join, group-filter
 * no-op contract, and cross-scorecard isolation. Plus renderer smoke for
 * render_report_empty_state and render_report_table -- the table tests assert
 * the SPEC §10.4 always-show-percentage rule (different gate from the result
 * page) and the no-band fallback label.
 *
 * 4.2 will add expandable-detail render tests; 4.3 will add group-filter
 * application tests; 4.4 will add CSV row construction tests.
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
 * Phase 4.1 report-layer tests.
 */
#[CoversNothing]
final class report_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture and return (scorecard, cm, context).
     *
     * Uses create_module so coursemodule_from_instance resolves -- mirroring the
     * production code path the report page relies on.
     */
    private function create_scorecard(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('scorecard', [
            'course' => $course->id,
            'name' => 'Report fixture',
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
     * Insert an attempt row with explicit user, attemptnumber, score, band snapshot.
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
     * Resolve the renderer using a configured page context.
     */
    private function renderer(): \mod_scorecard\output\renderer {
        global $PAGE;
        $PAGE->set_url('/mod/scorecard/report.php');
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('mod_scorecard');
    }

    /**
     * Empty scorecard returns an empty array (no attempts have been submitted).
     */
    public function test_returns_empty_array_when_no_attempts(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $this->assertSame([], $rows);
    }

    /**
     * A single attempt round-trips with the joined user identity columns populated.
     */
    public function test_returns_attempt_with_user_identity_fields(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user([
            'username' => 'alice',
            'firstname' => 'Alice',
            'lastname' => 'Anders',
        ]);
        $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            1,
            18,
            30,
            60.00,
            'Strong'
        );

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame((int)$user->id, (int)$row->userid);
        $this->assertSame('alice', $row->username);
        $this->assertSame('Alice', $row->firstname);
        $this->assertSame('Anders', $row->lastname);
        $this->assertSame(1, (int)$row->attemptnumber);
        $this->assertSame(18, (int)$row->totalscore);
        $this->assertSame(30, (int)$row->maxscore);
        $this->assertSame('Strong', $row->bandlabelsnapshot);
    }

    /**
     * Multi-user, multi-attempt fixture orders by userid ASC then attemptnumber ASC
     * (Phase 4 pre-flag #7). Submit times are deliberately out-of-order so the test
     * proves we sort by attemptnumber, not timecreated.
     */
    public function test_orders_by_userid_then_attemptnumber(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user1 = $this->getDataGenerator()->create_user(['username' => 'u1']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'u2']);

        // Insert in deliberately-mixed order; the helper must sort it.
        $this->insert_attempt((int)$scorecard->id, (int)$user2->id, 1, 10, 30, 33.33, null, 1000);
        $this->insert_attempt((int)$scorecard->id, (int)$user1->id, 2, 20, 30, 66.67, 'Strong', 2000);
        $this->insert_attempt((int)$scorecard->id, (int)$user1->id, 1, 15, 30, 50.00, 'Mid', 3000);
        $this->insert_attempt((int)$scorecard->id, (int)$user2->id, 2, 25, 30, 83.33, 'Strong', 4000);

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $this->assertCount(4, $rows);
        // The lower user id sorts first; both users get attemptnumber 1 then 2.
        $expectedlower = min((int)$user1->id, (int)$user2->id);
        $expectedhigher = max((int)$user1->id, (int)$user2->id);
        $this->assertSame($expectedlower, (int)$rows[0]->userid);
        $this->assertSame(1, (int)$rows[0]->attemptnumber);
        $this->assertSame($expectedlower, (int)$rows[1]->userid);
        $this->assertSame(2, (int)$rows[1]->attemptnumber);
        $this->assertSame($expectedhigher, (int)$rows[2]->userid);
        $this->assertSame(1, (int)$rows[2]->attemptnumber);
        $this->assertSame($expectedhigher, (int)$rows[3]->userid);
        $this->assertSame(2, (int)$rows[3]->attemptnumber);
    }

    /**
     * 4.1 group-filter contract: $groupid is accepted but is a no-op. Passing
     * null OR an arbitrary int should return the same set as omitting the param.
     * 4.3 will add the actual filter behavior; this test pins the 4.1 contract.
     */
    public function test_group_filter_null_returns_all(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 10, 30, 33.33, null);
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 2, 20, 30, 66.67, 'Strong');

        $defaultrows = scorecard_get_attempts($context, (int)$scorecard->id);
        $nullrows = scorecard_get_attempts($context, (int)$scorecard->id, null);
        $arbitraryrows = scorecard_get_attempts($context, (int)$scorecard->id, 999);

        $this->assertCount(2, $defaultrows);
        $this->assertSame(count($defaultrows), count($nullrows));
        $this->assertSame(count($defaultrows), count($arbitraryrows));
    }

    /**
     * Cross-scorecard isolation: helper returns only attempts for the target
     * scorecard, not attempts on a sibling scorecard within the same course.
     */
    public function test_returns_only_attempts_for_target_scorecard(): void {
        $this->resetAfterTest();
        [$first, , $context] = $this->create_scorecard();
        [$second, , ] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $this->insert_attempt((int)$first->id, (int)$user->id, 1, 10, 30, 33.33, null);
        $this->insert_attempt((int)$second->id, (int)$user->id, 1, 25, 30, 83.33, 'Strong');

        $rows = scorecard_get_attempts($context, (int)$first->id);

        $this->assertCount(1, $rows);
        $this->assertSame((int)$first->id, (int)$rows[0]->scorecardid);
        $this->assertSame(10, (int)$rows[0]->totalscore);
    }

    /**
     * render_report_empty_state contains the lang-string body so the manager sees
     * the friendly "no attempts yet" notice when no attempts have been submitted.
     */
    public function test_render_empty_state_contains_message(): void {
        $this->resetAfterTest();
        $html = $this->renderer()->render_report_empty_state();

        $this->assertStringContainsString(
            get_string('report:empty', 'mod_scorecard'),
            $html
        );
    }

    /**
     * render_report_table emits all SPEC §10.4 columns plus the always-shown
     * identity columns (Name / User ID / Username) regardless of identity policy.
     */
    public function test_render_table_emits_required_column_headers(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 18, 30, 60.00, 'Strong');
        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $html = $this->renderer()->render_report_table($scorecard, $rows, []);

        $expectedkeys = [
            'fullname', 'userid', 'username', 'attemptnumber', 'submitted',
            'totalscore', 'maxscore', 'percentage', 'band',
        ];
        foreach ($expectedkeys as $key) {
            $this->assertStringContainsString(
                get_string('report:col:' . $key, 'mod_scorecard'),
                $html
            );
        }
    }

    /**
     * SPEC §10.4 line 476: percentage ALWAYS renders in reports regardless of
     * $scorecard->showpercentage. This is a different gate than render_result_page.
     */
    public function test_render_table_percentage_always_shown_regardless_of_setting(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        // The showpercentage flag defaults to 0 in create_scorecard. The result
        // page would hide percentage in this configuration; the report MUST
        // still show it (SPEC §10.4 line 476).
        $this->assertSame(0, (int)$scorecard->showpercentage);

        $user = $this->getDataGenerator()->create_user();
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 20, 30, 66.67, 'Strong');
        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $html = $this->renderer()->render_report_table($scorecard, $rows, []);

        // 66.67 stored -> 67 displayed (round-half-away-from-zero, matching the
        // result page's rounding convention).
        $this->assertStringContainsString('67%', $html);
    }

    /**
     * Fallback path: bandlabelsnapshot is null when the attempt scored outside
     * any band's range. The table renders the no-band placeholder, not an empty
     * cell, so the manager can distinguish "no band match" from "data missing".
     */
    public function test_render_table_falls_back_to_noband_label_when_snapshot_null(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 5, 30, 16.67, null);
        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $html = $this->renderer()->render_report_table($scorecard, $rows, []);

        $this->assertStringContainsString(
            get_string('report:col:noband', 'mod_scorecard'),
            $html
        );
    }

    /**
     * Snapshot-only render: editing the live band row after the attempt does
     * not change the report's displayed band label. Reports read the attempt's
     * bandlabelsnapshot column, never the live bands table (SPEC §11.2).
     */
    public function test_render_table_uses_snapshot_not_live_band_label(): void {
        global $DB;
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $bandid = scorecard_add_band((object)[
            'scorecardid' => (int)$scorecard->id,
            'minscore' => 0,
            'maxscore' => 30,
            'label' => 'Original label',
            'message' => 'Body.',
            'messageformat' => FORMAT_HTML,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            1,
            18,
            30,
            60.00,
            'Original label'
        );

        // Edit the live band after the attempt; report must show the snapshot.
        $DB->set_field('scorecard_bands', 'label', 'Edited label', ['id' => $bandid]);

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);
        $html = $this->renderer()->render_report_table($scorecard, $rows, []);

        $this->assertStringContainsString('Original label', $html);
        $this->assertStringNotContainsString('Edited label', $html);
    }
}
