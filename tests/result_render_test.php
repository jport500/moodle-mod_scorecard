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
 * Tests for mod_scorecard's result page renderer.
 *
 * Covers 3.4's render_result_page(): snapshot-only reads, conditional
 * rendering of percentage / band heading / band message / item summary,
 * percentage rounding behaviour, audit-honest item summary including
 * soft-deleted items rendered with the deleted_marker badge.
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
 * Result-render tests for 3.4.
 */
#[CoversNothing]
final class result_render_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture, optionally toggling the result-display flags.
     */
    private function create_scorecard(
        bool $showpercentage = false,
        bool $showitemsummary = true
    ): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('scorecard', [
            'course' => $course->id,
            'name' => 'Result fixture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'scalemin' => 1,
            'scalemax' => 10,
            'displaystyle' => 'radio',
            'lowlabel' => '',
            'highlabel' => '',
            'allowretakes' => 0,
            'showresult' => 1,
            'showpercentage' => $showpercentage ? 1 : 0,
            'showitemsummary' => $showitemsummary ? 1 : 0,
            'fallbackmessage_editor' => ['text' => 'Fallback message body.', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ]);
        return $DB->get_record('scorecard', ['id' => $module->id], '*', MUST_EXIST);
    }

    /**
     * Insert an attempt row with full snapshot fields.
     */
    private function insert_attempt(
        int $scorecardid,
        int $userid,
        int $totalscore,
        int $maxscore,
        float $percentage,
        ?int $bandid,
        ?string $bandlabel,
        string $bandmessage,
        int $bandformat = FORMAT_HTML
    ): \stdClass {
        global $DB;
        $now = time();
        $id = $DB->insert_record('scorecard_attempts', (object)[
            'scorecardid' => $scorecardid,
            'userid' => $userid,
            'attemptnumber' => 1,
            'totalscore' => $totalscore,
            'maxscore' => $maxscore,
            'percentage' => $percentage,
            'bandid' => $bandid,
            'bandlabelsnapshot' => $bandlabel,
            'bandmessagesnapshot' => $bandmessage,
            'bandmessageformatsnapshot' => $bandformat,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $DB->get_record('scorecard_attempts', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Add an item with optional soft-deletion.
     */
    private function add_item(int $scorecardid, string $prompt, bool $deleted = false): int {
        global $DB;
        $id = scorecard_add_item((object)[
            'scorecardid' => $scorecardid,
            'prompt' => $prompt,
            'promptformat' => FORMAT_HTML,
        ]);
        if ($deleted) {
            $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $id]);
        }
        return $id;
    }

    /**
     * Resolve the renderer using a configured page context.
     */
    private function renderer(): \mod_scorecard\output\renderer {
        global $PAGE;
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('mod_scorecard');
    }

    /**
     * Case 1: matched band -- headline, label heading, and message body all render.
     */
    public function test_matched_band_renders_label_and_message(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            18,
            30,
            60.00,
            42,
            'Strong',
            'You did well.'
        );

        $html = $this->renderer()->render_result_page($scorecard, $attempt, [], []);

        $this->assertStringContainsString('Your score: 18 out of 30', $html);
        $this->assertStringContainsString('Strong', $html);
        $this->assertStringContainsString('You did well.', $html);
    }

    /**
     * Case 2: fallback -- bandid + bandlabelsnapshot null, message comes from fallback snapshot.
     */
    public function test_fallback_renders_message_without_label(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            5,
            30,
            16.67,
            null,
            null,
            'Fallback message body.'
        );

        $html = $this->renderer()->render_result_page($scorecard, $attempt, [], []);

        $this->assertStringContainsString('Your score: 5 out of 30', $html);
        $this->assertStringContainsString('Fallback message body.', $html);
        $this->assertStringNotContainsString('scorecard-result-band-label', $html);
    }

    /**
     * Case 3: matched band with empty message -- label heading without body, NOT fallback.
     */
    public function test_matched_band_with_empty_message_renders_label_only(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            10,
            30,
            33.33,
            7,
            'Mid',
            ''
        );

        $html = $this->renderer()->render_result_page($scorecard, $attempt, [], []);

        $this->assertStringContainsString('Mid', $html);
        $this->assertStringNotContainsString('scorecard-result-band-message', $html);
    }

    /**
     * Case 4a: showpercentage=0 hides the percentage display.
     */
    public function test_percentage_hidden_when_setting_off(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(false);
        $user = $this->getDataGenerator()->create_user();
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            18,
            30,
            60.00,
            null,
            null,
            'Fallback.'
        );

        $html = $this->renderer()->render_result_page($scorecard, $attempt, [], []);

        $this->assertStringNotContainsString('60%', $html);
        $this->assertStringNotContainsString('scorecard-result-percentage', $html);
    }

    /**
     * Case 4b: showpercentage=1 renders rounded integer percentage.
     */
    public function test_percentage_shown_and_rounded(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(true);
        $user = $this->getDataGenerator()->create_user();
        // 66.67 stored -> 67 displayed (round-half-away-from-zero default).
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            20,
            30,
            66.67,
            null,
            null,
            'Fallback.'
        );

        $html = $this->renderer()->render_result_page($scorecard, $attempt, [], []);

        $this->assertStringContainsString('67%', $html);
        $this->assertStringNotContainsString('66.67', $html);
    }

    /**
     * Case 5: percentage rounding edge cases -- 33.33 -> 33, 50.00 -> 50.
     */
    public function test_percentage_rounding_edge_cases(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(true);
        $user = $this->getDataGenerator()->create_user();

        $attempt33 = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 10, 30, 33.33, null, null, 'F.');
        $html33 = $this->renderer()->render_result_page($scorecard, $attempt33, [], []);
        $this->assertStringContainsString('33%', $html33);

        $attempt50 = $this->insert_attempt((int)$scorecard->id, (int)$user->id, 15, 30, 50.00, null, null, 'F.');
        $html50 = $this->renderer()->render_result_page($scorecard, $attempt50, [], []);
        $this->assertStringContainsString('50%', $html50);
    }

    /**
     * Case 6a: showitemsummary=0 hides the summary block entirely.
     */
    public function test_item_summary_hidden_when_setting_off(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(false, false);
        $user = $this->getDataGenerator()->create_user();
        $itemid = $this->add_item((int)$scorecard->id, 'Prompt A');
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            7,
            10,
            70.00,
            null,
            null,
            'F.'
        );
        $items = $DB->get_records('scorecard_items', ['id' => $itemid]);
        $responses = [$itemid => 7];

        $html = $this->renderer()->render_result_page($scorecard, $attempt, $items, $responses);

        $this->assertStringNotContainsString('scorecard-result-summary', $html);
        $this->assertStringNotContainsString('Prompt A', $html);
    }

    /**
     * Case 6b: showitemsummary=1 renders summary in <details> with prompt + value rows.
     */
    public function test_item_summary_renders_with_details(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(false, true);
        $user = $this->getDataGenerator()->create_user();
        $a = $this->add_item((int)$scorecard->id, 'Prompt A');
        $b = $this->add_item((int)$scorecard->id, 'Prompt B');
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            12,
            20,
            60.00,
            null,
            null,
            'F.'
        );
        $items = $DB->get_records_list('scorecard_items', 'id', [$a, $b]);
        $responses = [$a => 5, $b => 7];

        $html = $this->renderer()->render_result_page($scorecard, $attempt, $items, $responses);

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Prompt A', $html);
        $this->assertStringContainsString('Prompt B', $html);
        $this->assertStringContainsString('Your response: 5', $html);
        $this->assertStringContainsString('Your response: 7', $html);
    }

    /**
     * Case 7: soft-deleted item in summary renders with strikethrough + deleted badge.
     */
    public function test_item_summary_marks_soft_deleted_items(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(false, true);
        $user = $this->getDataGenerator()->create_user();
        $a = $this->add_item((int)$scorecard->id, 'Prompt A', false);
        $b = $this->add_item((int)$scorecard->id, 'Prompt B', true);
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            12,
            20,
            60.00,
            null,
            null,
            'F.'
        );
        $items = $DB->get_records_list('scorecard_items', 'id', [$a, $b]);
        $responses = [$a => 5, $b => 7];

        $html = $this->renderer()->render_result_page($scorecard, $attempt, $items, $responses);

        $deletedlabel = get_string('badge:deleted', 'mod_scorecard');
        $this->assertStringContainsString('<s ', $html, 'Deleted item prompt is wrapped in <s>.');
        $this->assertStringContainsString($deletedlabel, $html);
        // Visible item must NOT have a <s> wrap (exact string assertion is fragile;
        // count occurrences of the strikethrough open tag instead).
        $this->assertSame(1, substr_count($html, '<s '));
    }

    /**
     * Case 8: snapshot-only reads -- changing live band rows after attempt does not affect render.
     */
    public function test_renders_snapshot_not_live_band(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $user = $this->getDataGenerator()->create_user();
        $bandid = scorecard_add_band((object)[
            'scorecardid' => (int)$scorecard->id,
            'minscore' => 0,
            'maxscore' => 30,
            'label' => 'Original label',
            'message' => 'Original message.',
            'messageformat' => FORMAT_HTML,
        ]);
        $attempt = $this->insert_attempt(
            (int)$scorecard->id,
            (int)$user->id,
            18,
            30,
            60.00,
            $bandid,
            'Original label',
            'Original message.'
        );

        // Edit live band after the attempt; snapshot should not change.
        $DB->set_field('scorecard_bands', 'label', 'Edited label', ['id' => $bandid]);
        $DB->set_field('scorecard_bands', 'message', 'Edited message.', ['id' => $bandid]);

        $html = $this->renderer()->render_result_page($scorecard, $attempt, [], []);

        $this->assertStringContainsString('Original label', $html);
        $this->assertStringContainsString('Original message.', $html);
        $this->assertStringNotContainsString('Edited label', $html);
        $this->assertStringNotContainsString('Edited message.', $html);
    }
}
