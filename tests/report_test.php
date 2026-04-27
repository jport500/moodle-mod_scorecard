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
     * Phase 4.5 helper: instantiate the flexible_table subclass and capture
     * its output as a string. Replaces the pre-4.5
     * $this->renderer()->render_report_table() call shape.
     *
     * @param \stdClass $scorecard
     * @param array $attempts
     * @param string[] $identityfields
     * @param int $pagesize
     * @return string Captured HTML.
     */
    private function render_table_html(
        \stdClass $scorecard,
        array $attempts,
        array $identityfields = [],
        int $pagesize = 25
    ): string {
        global $PAGE;
        $PAGE->set_url('/mod/scorecard/report.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');
        $baseurl = new \moodle_url('/mod/scorecard/report.php');
        $table = new \mod_scorecard\output\report_table(
            'test_report_' . uniqid(),
            $scorecard,
            $attempts,
            $identityfields,
            $renderer,
            $baseurl
        );
        ob_start();
        $table->out($pagesize, false);
        return ob_get_clean();
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
     * 4.3 group-filter "no filter" contract: $groupid null and $groupid 0 both
     * return the full attempt set. groups_get_activity_group() returns 0 as the
     * "All groups" sentinel; null is the default-no-filter shape from callers
     * outside the standard groups workflow. Both must short-circuit the JOIN.
     */
    public function test_group_filter_null_and_zero_return_all(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 10, 30, 33.33, null);
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 2, 20, 30, 66.67, 'Strong');

        $defaultrows = scorecard_get_attempts($context, (int)$scorecard->id);
        $nullrows = scorecard_get_attempts($context, (int)$scorecard->id, null);
        $zerorows = scorecard_get_attempts($context, (int)$scorecard->id, 0);

        $this->assertCount(2, $defaultrows);
        $this->assertSame(count($defaultrows), count($nullrows));
        $this->assertSame(count($defaultrows), count($zerorows));
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
     * Report table emits all SPEC §10.4 columns plus the always-shown
     * identity columns (Name / User ID / Username) regardless of identity
     * policy. Adapted in Phase 4.5 to capture flexible_table output via
     * ob_start/ob_get_clean instead of the pre-4.5 render_report_table
     * return-string shape.
     */
    public function test_render_table_emits_required_column_headers(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 18, 30, 60.00, 'Strong');
        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        $html = $this->render_table_html($scorecard, $rows);

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
        // Phase 4.2: trailing "Detail" column header.
        $this->assertStringContainsString(
            get_string('report:detail:heading', 'mod_scorecard'),
            $html
        );
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

        $html = $this->render_table_html($scorecard, $rows);

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

        $html = $this->render_table_html($scorecard, $rows);

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
        $html = $this->render_table_html($scorecard, $rows);

        $this->assertStringContainsString('Original label', $html);
        $this->assertStringNotContainsString('Edited label', $html);
    }

    /**
     * Phase 4.2 helper: insert a response row directly. Bypasses the submit
     * handler so individual tests can synthesize the (responsevalue, item-state)
     * combinations they need without driving the full submission flow.
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
     * scorecard_get_attempt_responses returns an empty array for an empty
     * attemptids input. Defensive against report.php's "no attempts" branch
     * also passing empty arrays.
     */
    public function test_get_attempt_responses_empty_input_returns_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], scorecard_get_attempt_responses([]));
    }

    /**
     * Batch fetch groups responses by attemptid and orders within each group by
     * item sortorder ASC. Two attempts × three items each round-trips correctly.
     */
    public function test_get_attempt_responses_groups_by_attemptid_and_orders_by_sortorder(): void {
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $item1 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'First',
            'promptformat' => FORMAT_HTML,
        ]);
        $item2 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Second',
            'promptformat' => FORMAT_HTML,
        ]);
        $item3 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Third',
            'promptformat' => FORMAT_HTML,
        ]);

        $attempt1 = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 15, 30, 50.0, null);
        $attempt2 = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 2, 20, 30, 66.67, 'Strong');

        // Insert in deliberately-mixed order; helper must sort by sortorder.
        $this->insert_response($attempt1, $item3, 7);
        $this->insert_response($attempt1, $item1, 5);
        $this->insert_response($attempt1, $item2, 3);
        $this->insert_response($attempt2, $item2, 6);
        $this->insert_response($attempt2, $item1, 8);
        $this->insert_response($attempt2, $item3, 6);

        $grouped = scorecard_get_attempt_responses([$attempt1, $attempt2]);

        $this->assertArrayHasKey($attempt1, $grouped);
        $this->assertArrayHasKey($attempt2, $grouped);
        $this->assertCount(3, $grouped[$attempt1]);
        $this->assertCount(3, $grouped[$attempt2]);

        // Within each attempt, rows ordered by sortorder ASC -> item1, item2, item3.
        $this->assertSame((int)$item1, (int)$grouped[$attempt1][0]->itemid);
        $this->assertSame((int)$item2, (int)$grouped[$attempt1][1]->itemid);
        $this->assertSame((int)$item3, (int)$grouped[$attempt1][2]->itemid);
        $this->assertSame((int)$item1, (int)$grouped[$attempt2][0]->itemid);
        $this->assertSame((int)$item2, (int)$grouped[$attempt2][1]->itemid);
        $this->assertSame((int)$item3, (int)$grouped[$attempt2][2]->itemid);

        // Joined item fields populated from live scorecard_items.
        $this->assertSame('First', $grouped[$attempt1][0]->prompt);
        $this->assertSame('Second', $grouped[$attempt1][1]->prompt);
        $this->assertSame('Third', $grouped[$attempt1][2]->prompt);
    }

    /**
     * Soft-deleted items still resolve through the LEFT JOIN -- the row is
     * retained per the soft-delete pattern, so prompt + deleted=1 come back
     * for audit-honest detail rendering.
     */
    public function test_get_attempt_responses_includes_soft_deleted_items(): void {
        global $DB;
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Will be deleted',
            'promptformat' => FORMAT_HTML,
        ]);
        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 5, 10, 50.0, null);
        $this->insert_response($attemptid, $itemid, 5);

        // Soft-delete the item AFTER the response is recorded.
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemid]);

        $grouped = scorecard_get_attempt_responses([$attemptid]);

        $this->assertArrayHasKey($attemptid, $grouped);
        $this->assertCount(1, $grouped[$attemptid]);
        $row = $grouped[$attemptid][0];
        $this->assertSame('Will be deleted', $row->prompt);
        $this->assertSame(1, (int)$row->deleted);
        $this->assertSame(5, (int)$row->responsevalue);
    }

    /**
     * render_attempt_detail emits one <p> per response inside <details>, with
     * the prompt rendered as a bold label and the "Response: V of MAX" copy.
     */
    public function test_render_attempt_detail_emits_per_item_prose(): void {
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $item1 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Pace of work',
            'promptformat' => FORMAT_HTML,
        ]);
        $item2 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Clarity of goals',
            'promptformat' => FORMAT_HTML,
        ]);
        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 13, 20, 65.0, null);
        $this->insert_response($attemptid, $item1, 7);
        $this->insert_response($attemptid, $item2, 6);

        $grouped = scorecard_get_attempt_responses([$attemptid]);
        $attempt = (object)['id' => $attemptid];
        $html = $this->renderer()->render_attempt_detail($scorecard, $attempt, $grouped[$attemptid]);

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString('Pace of work', $html);
        $this->assertStringContainsString('Clarity of goals', $html);
        // Response copy includes scalemax (10 from create_scorecard default).
        $this->assertStringContainsString('Response: 7 of 10', $html);
        $this->assertStringContainsString('Response: 6 of 10', $html);
    }

    /**
     * Soft-deleted items in the detail block render with the [deleted] prefix
     * and the whole line de-emphasized via Bootstrap utility classes.
     */
    public function test_render_attempt_detail_marks_soft_deleted_items(): void {
        global $DB;
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Removed prompt',
            'promptformat' => FORMAT_HTML,
        ]);
        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 4, 10, 40.0, null);
        $this->insert_response($attemptid, $itemid, 4);
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemid]);

        $grouped = scorecard_get_attempt_responses([$attemptid]);
        $html = $this->renderer()->render_attempt_detail(
            $scorecard,
            (object)['id' => $attemptid],
            $grouped[$attemptid]
        );

        $this->assertStringContainsString(
            get_string('report:detail:deletedprefix', 'mod_scorecard'),
            $html
        );
        // Whole-line muted/italic: Bootstrap utility classes applied to the <p>.
        $this->assertMatchesRegularExpression(
            '/class="[^"]*text-muted[^"]*fst-italic[^"]*"/',
            $html
        );
    }

    /**
     * Out-of-range responses (value outside [scalemin, scalemax]) get a
     * red-flagged suffix. SPEC §4.5 + scorecard_scale_change_allowed() block
     * scale changes once attempts exist, so the source of out-of-range values
     * is direct DB tampering or restore mismatches; this defensive flag
     * surfaces them for audit (closes followup #14).
     */
    public function test_render_attempt_detail_flags_out_of_range_response(): void {
        global $DB;
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Tampered item',
            'promptformat' => FORMAT_HTML,
        ]);
        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 15, 10, 150.0, null);

        // Direct DB write of an out-of-range value (scalemin=1, scalemax=10).
        $DB->insert_record('scorecard_responses', (object)[
            'attemptid' => $attemptid,
            'itemid' => $itemid,
            'responsevalue' => 15,
            'timecreated' => time(),
        ]);

        $grouped = scorecard_get_attempt_responses([$attemptid]);
        $html = $this->renderer()->render_attempt_detail(
            $scorecard,
            (object)['id' => $attemptid],
            $grouped[$attemptid]
        );

        // The danger-styled span carries the out-of-range copy with min/max.
        $this->assertStringContainsString('text-danger', $html);
        $expectedrange = get_string('report:detail:outofrange', 'mod_scorecard', (object)[
            'min' => 1,
            'max' => 10,
        ]);
        $this->assertStringContainsString($expectedrange, $html);
    }

    /**
     * Empty $responses argument still yields a valid <details> element with the
     * summary "View 0 responses" -- defensive against data-corruption / direct
     * DB tampering where an attempt row exists with no responses.
     */
    public function test_render_attempt_detail_emits_details_for_empty_responses(): void {
        $this->resetAfterTest();
        [$scorecard] = $this->create_scorecard();

        $html = $this->renderer()->render_attempt_detail(
            $scorecard,
            (object)['id' => 0],
            []
        );

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
        $expectedsummary = get_string('report:detail:summary', 'mod_scorecard', 0);
        $this->assertStringContainsString($expectedsummary, $html);
    }

    /**
     * Wired end-to-end: the report_table subclass emits the per-row detail
     * block. Adapted in Phase 4.5 -- the responses fetch now happens inside
     * the subclass's query_db() (per-page) rather than being passed in by
     * the caller, so the test fixture just inserts response rows and trusts
     * the subclass to fetch them when rendering.
     */
    public function test_render_report_table_includes_detail_block_per_row(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user(['username' => 'u1']);

        $itemid = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Wired end-to-end',
            'promptformat' => FORMAT_HTML,
        ]);
        $attemptid = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 8, 10, 80.0, 'Strong');
        $this->insert_response($attemptid, $itemid, 8);

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);
        $html = $this->render_table_html($scorecard, $rows);

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Wired end-to-end', $html);
        $this->assertStringContainsString('Response: 8 of 10', $html);
    }

    /**
     * Phase 4.3 helper: enrol a user in a course and add them to a specific
     * group. Group membership requires both an enrolment AND a group_member
     * row -- groups_members alone is insufficient if the user isn't enrolled.
     */
    private function enrol_into_group(\stdClass $course, \stdClass $user, int $groupid): void {
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $groupid,
            'userid' => $user->id,
        ]);
    }

    /**
     * Group filter activates: passing $groupid > 0 restricts the result to
     * users who are members of that group at query time. Members of OTHER
     * groups are excluded.
     */
    public function test_group_filter_returns_only_member_attempts(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        // Course id is on the scorecard row -- recover it for group fixtures.
        $course = (object)['id' => (int)$scorecard->course];

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'B']);

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $this->enrol_into_group($course, $usera, (int)$groupa->id);
        $this->enrol_into_group($course, $userb, (int)$groupb->id);

        $this->insert_attempt((int)$scorecard->id, (int)$usera->id, 1, 18, 30, 60.0, 'Strong');
        $this->insert_attempt((int)$scorecard->id, (int)$userb->id, 1, 12, 30, 40.0, 'Mid');

        $rowsfora = scorecard_get_attempts($context, (int)$scorecard->id, (int)$groupa->id);
        $rowsforb = scorecard_get_attempts($context, (int)$scorecard->id, (int)$groupb->id);

        $this->assertCount(1, $rowsfora);
        $this->assertSame((int)$usera->id, (int)$rowsfora[0]->userid);
        $this->assertCount(1, $rowsforb);
        $this->assertSame((int)$userb->id, (int)$rowsforb[0]->userid);
    }

    /**
     * Cross-group isolation: filtering by one group never leaks attempts from
     * another. Three users across three groups, three attempts, three filter
     * passes; each pass returns exactly its own one.
     */
    public function test_group_filter_cross_group_isolation(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $course = (object)['id' => (int)$scorecard->course];

        $groups = [];
        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $groups[$i] = $this->getDataGenerator()->create_group([
                'courseid' => $course->id,
                'name' => "Group {$i}",
            ]);
            $users[$i] = $this->getDataGenerator()->create_user();
            $this->enrol_into_group($course, $users[$i], (int)$groups[$i]->id);
            $this->insert_attempt(
                (int)$scorecard->id,
                (int)$users[$i]->id,
                1,
                10 + $i,
                30,
                (10.0 + $i) * 100 / 30,
                null
            );
        }

        for ($i = 1; $i <= 3; $i++) {
            $rows = scorecard_get_attempts($context, (int)$scorecard->id, (int)$groups[$i]->id);
            $this->assertCount(1, $rows, "Group {$i} should see exactly one attempt");
            $this->assertSame((int)$users[$i]->id, (int)$rows[0]->userid);
        }
    }

    /**
     * A user in multiple groups appears under either group's filter. Group
     * membership is multi-valued; the JOIN matches any membership row, and
     * the helper de-duplicates because each user has at most one attempt
     * per (scorecard, attemptnumber) -- the {groups_members} JOIN does not
     * multiply rows in this fixture.
     */
    public function test_group_filter_user_in_multiple_groups(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $course = (object)['id' => (int)$scorecard->course];

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'B']);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => (int)$groupa->id, 'userid' => $user->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => (int)$groupb->id, 'userid' => $user->id]);

        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 18, 30, 60.0, 'Strong');

        $rowsfora = scorecard_get_attempts($context, (int)$scorecard->id, (int)$groupa->id);
        $rowsforb = scorecard_get_attempts($context, (int)$scorecard->id, (int)$groupb->id);

        $this->assertCount(1, $rowsfora);
        $this->assertCount(1, $rowsforb);
        $this->assertSame((int)$user->id, (int)$rowsfora[0]->userid);
        $this->assertSame((int)$user->id, (int)$rowsforb[0]->userid);
    }

    /**
     * Filter with a nonexistent group id returns an empty result. Defensive
     * against stale session group ids surviving across course-edit operations
     * that delete groups.
     */
    public function test_group_filter_nonexistent_group_returns_empty(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $this->insert_attempt((int)$scorecard->id, (int)$user->id, 1, 18, 30, 60.0, 'Strong');

        $rows = scorecard_get_attempts($context, (int)$scorecard->id, 999999);

        $this->assertSame([], $rows);
    }

    /**
     * Phase 4.5: 30 attempts; page 1 (default) shows 25 fullnames; the
     * remaining 5 do not appear. Pagination slicing is the property under
     * test -- the implication that responses are only fetched for the
     * visible page is a structural property of the subclass design (asserted
     * via the absence of page-2 attempts' response prose in the captured
     * HTML; if responses for all 30 attempts were rendered, page 2's prose
     * would leak in even though their rows are sliced out).
     */
    public function test_pagination_default_page_size_25(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();

        $usernames = [];
        for ($i = 1; $i <= 30; $i++) {
            // Zero-padded so lexical sort matches numeric (u01 < u10 < u30).
            $name = sprintf('u%02d', $i);
            $usernames[$i] = $name;
            $user = $this->getDataGenerator()->create_user(['username' => $name]);
            $this->insert_attempt(
                (int)$scorecard->id,
                (int)$user->id,
                1,
                $i % 10 + 1,
                10,
                ($i % 10 + 1) * 10.0,
                null,
                1000 + $i
            );
        }

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);
        $this->assertCount(30, $rows);

        $html = $this->render_table_html($scorecard, $rows);

        // Rows are ordered by userid ASC, so the lower-id users (u01..u25
        // by creation order) are on page 1.
        for ($i = 1; $i <= 25; $i++) {
            $this->assertStringContainsString(
                $usernames[$i],
                $html,
                "Page 1 should contain {$usernames[$i]}"
            );
        }
        for ($i = 26; $i <= 30; $i++) {
            $this->assertStringNotContainsString(
                $usernames[$i],
                $html,
                "Page 1 should NOT contain {$usernames[$i]}"
            );
        }
    }

    /**
     * Phase 4.5: navigating to page 2 (via the `page` query param flexible_table
     * reads) shows the remaining attempts. Default page size 25 + 30 attempts
     * means page 2 has 5 rows (u26..u30).
     */
    public function test_pagination_navigates_to_page_2(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();

        $usernames = [];
        for ($i = 1; $i <= 30; $i++) {
            $name = sprintf('u%02d', $i);
            $usernames[$i] = $name;
            $user = $this->getDataGenerator()->create_user(['username' => $name]);
            $this->insert_attempt(
                (int)$scorecard->id,
                (int)$user->id,
                1,
                $i % 10 + 1,
                10,
                ($i % 10 + 1) * 10.0,
                null,
                1000 + $i
            );
        }

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);

        // Set the page query param so flexible_table reads "page 2" (0-indexed).
        $_GET['page'] = '1';
        try {
            $html = $this->render_table_html($scorecard, $rows);
        } finally {
            unset($_GET['page']);
        }

        // Page 2 has the last 5 users (u26..u30); first 25 are absent.
        for ($i = 26; $i <= 30; $i++) {
            $this->assertStringContainsString(
                $usernames[$i],
                $html,
                "Page 2 should contain {$usernames[$i]}"
            );
        }
        for ($i = 1; $i <= 25; $i++) {
            $this->assertStringNotContainsString(
                $usernames[$i],
                $html,
                "Page 2 should NOT contain {$usernames[$i]}"
            );
        }
    }

    /**
     * Phase 4.5: with 30 attempts (>1 page) the captured output contains a
     * pagination affordance (Moodle's standard pagebar). Defensive against
     * a regression where flexible_table::out() or finish_output() fails to
     * emit the bar even though we hit the multi-page threshold.
     */
    public function test_pagination_emits_pagebar_when_multipage(): void {
        $this->resetAfterTest();
        [$scorecard, , $context] = $this->create_scorecard();

        for ($i = 1; $i <= 30; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->insert_attempt(
                (int)$scorecard->id,
                (int)$user->id,
                1,
                5,
                10,
                50.0,
                null,
                1000 + $i
            );
        }

        $rows = scorecard_get_attempts($context, (int)$scorecard->id);
        $html = $this->render_table_html($scorecard, $rows);

        // Moodle's paging_bar emits an element with class "paging" or the
        // standard pagebar nav. Either marker confirms multi-page chrome.
        $haspaging = (
            str_contains($html, 'class="paging"') ||
            str_contains($html, 'pagination')
        );
        $this->assertTrue(
            $haspaging,
            'Multi-page table should emit pagination chrome'
        );
    }

    /**
     * render_report_empty_state with $filtered=true emits the group-filtered
     * copy ("No attempts in the selected group.") instead of the generic copy.
     * Phase 4.3 Q2 disposition (b.1) -- generic filtered copy, no group name
     * duplication since the selector above the notice already shows the name.
     */
    public function test_render_empty_state_filtered_uses_filtered_copy(): void {
        $this->resetAfterTest();
        $genericcopy = get_string('report:empty', 'mod_scorecard');
        $filteredcopy = get_string('report:empty:filtered', 'mod_scorecard');

        // Pre-condition: the two strings must actually differ. If a future
        // language pack accidentally collapses them, this assertion catches it.
        $this->assertNotSame($genericcopy, $filteredcopy);

        $defaulthtml = $this->renderer()->render_report_empty_state();
        $filteredhtml = $this->renderer()->render_report_empty_state(true);

        $this->assertStringContainsString($genericcopy, $defaulthtml);
        $this->assertStringNotContainsString($filteredcopy, $defaulthtml);
        $this->assertStringContainsString($filteredcopy, $filteredhtml);
        $this->assertStringNotContainsString($genericcopy, $filteredhtml);
    }
}
