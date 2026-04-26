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
 * Tests for mod_scorecard's learner submission handler.
 *
 * Covers 3.3's scorecard_handle_submission(): validation order (POST-injection
 * guard, lifecycle gate, per-item missing/out-of-range, duplicate attempt),
 * transactional write of attempt + response rows, audit-write semantics for
 * items soft-deleted between render and submit, and event firing on commit.
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
 * Submission handler tests for 3.3.
 */
#[CoversNothing]
final class submission_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture with predictable scale and configurable retake policy.
     *
     * @param bool $allowretakes
     * @param int $scalemin
     * @param int $scalemax
     * @return \stdClass scorecard row.
     */
    private function create_scorecard(
        bool $allowretakes = false,
        int $scalemin = 1,
        int $scalemax = 10
    ): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('scorecard', [
            'course' => $course->id,
            'name' => 'Submission fixture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'scalemin' => $scalemin,
            'scalemax' => $scalemax,
            'displaystyle' => 'radio',
            'lowlabel' => '',
            'highlabel' => '',
            'allowretakes' => $allowretakes ? 1 : 0,
            'showresult' => 1,
            'showpercentage' => 0,
            'showitemsummary' => 1,
            'fallbackmessage_editor' => ['text' => 'Default fallback', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ]);
        return $DB->get_record('scorecard', ['id' => $module->id], '*', MUST_EXIST);
    }

    /**
     * Add N items to the scorecard and return their ids in insertion order.
     *
     * @param int $scorecardid
     * @param int $count
     * @return int[]
     */
    private function add_items(int $scorecardid, int $count): array {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = scorecard_add_item((object)[
                'scorecardid' => $scorecardid,
                'prompt' => "Prompt $i",
                'promptformat' => FORMAT_HTML,
            ]);
        }
        return $ids;
    }

    /**
     * Add a band and return its id.
     */
    private function add_band(int $scorecardid, int $min, int $max, string $label): int {
        return scorecard_add_band((object)[
            'scorecardid' => $scorecardid,
            'minscore' => $min,
            'maxscore' => $max,
            'label' => $label,
            'message' => "Message for $label",
            'messageformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Resolve the cm row for a scorecard id.
     */
    private function cm_for(int $scorecardid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_instance('scorecard', $scorecardid, 0, false, MUST_EXIST);
        return $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST);
    }

    /**
     * Case 1: happy path -- valid responses commit attempt + N response rows + snapshot.
     */
    public function test_happy_path_commits_attempt_and_responses(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $itemids = $this->add_items((int)$scorecard->id, 3);
        $this->add_band((int)$scorecard->id, 0, 30, 'All');
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [
            $itemids[0] => 5,
            $itemids[1] => 6,
            $itemids[2] => 7,
        ];

        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );

        $this->assertSame('submitted', $result['status']);
        $this->assertNotNull($result['attemptid']);

        $attempt = $DB->get_record('scorecard_attempts', ['id' => $result['attemptid']], '*', MUST_EXIST);
        $this->assertSame(18, (int)$attempt->totalscore);
        $this->assertSame(30, (int)$attempt->maxscore);
        $this->assertSame(60.0, (float)$attempt->percentage);
        $this->assertSame('All', $attempt->bandlabelsnapshot);
        $this->assertSame(1, (int)$attempt->attemptnumber);

        $rows = $DB->get_records('scorecard_responses', ['attemptid' => $result['attemptid']]);
        $this->assertCount(3, $rows);
    }

    /**
     * Case 2: missing response on one item yields a per-fieldset error and writes nothing.
     */
    public function test_missing_response_blocks_write(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [$itemids[0] => 5]; // Missing response for $itemids[1].

        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );

        $this->assertSame('validation_failed', $result['status']);
        $this->assertArrayHasKey($itemids[1], $result['errors']);
        $this->assertArrayNotHasKey('_form', $result['errors']);
        $this->assertNull($result['attemptid']);
        $this->assertSame(0, $DB->count_records('scorecard_attempts', ['scorecardid' => $scorecard->id]));
        $this->assertSame($rawresponses, $result['preselected']);
    }

    /**
     * Case 3: out-of-range value yields a per-fieldset error and writes nothing.
     */
    public function test_out_of_range_response_blocks_write(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(false, 1, 10);
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [
            $itemids[0] => 5,
            $itemids[1] => 99,
        ];

        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );

        $this->assertSame('validation_failed', $result['status']);
        $this->assertArrayHasKey($itemids[1], $result['errors']);
        $this->assertSame(0, $DB->count_records('scorecard_attempts', ['scorecardid' => $scorecard->id]));
    }

    /**
     * Case 4: itemid injection (POST contains an itemid not in this scorecard).
     */
    public function test_itemid_injection_yields_form_level_error(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [
            $itemids[0] => 5,
            $itemids[1] => 5,
            999999 => 5, // Foreign itemid.
        ];

        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );

        $this->assertSame('validation_failed', $result['status']);
        $this->assertArrayHasKey('_form', $result['errors']);
        $this->assertSame(0, $DB->count_records('scorecard_attempts', ['scorecardid' => $scorecard->id]));
    }

    /**
     * Case 5: lifecycle gate -- every visible item soft-deleted between render and submit.
     */
    public function test_lifecycle_gate_all_items_soft_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        // Simulate items soft-deleted after the form was rendered.
        foreach ($itemids as $iid) {
            $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $iid]);
        }

        $rawresponses = [$itemids[0] => 5, $itemids[1] => 5];

        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );

        $this->assertSame('validation_failed', $result['status']);
        $this->assertArrayHasKey('_form', $result['errors']);
        $this->assertStringContainsString(
            'no scorable items',
            $result['errors']['_form']
        );
    }

    /**
     * Case 6: duplicate attempt -- retakes off and user already has an attempt.
     */
    public function test_duplicate_attempt_short_circuits_when_retakes_off(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(false);
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [$itemids[0] => 5, $itemids[1] => 5];
        $cm = $this->cm_for((int)$scorecard->id);

        $first = scorecard_handle_submission($scorecard, $cm, (int)$user->id, $rawresponses);
        $this->assertSame('submitted', $first['status']);

        $second = scorecard_handle_submission($scorecard, $cm, (int)$user->id, $rawresponses);
        $this->assertSame('duplicate_attempt', $second['status']);
        $this->assertNull($second['attemptid']);
        $this->assertSame(1, $DB->count_records('scorecard_attempts', ['scorecardid' => $scorecard->id]));
    }

    /**
     * Case 7: retakes on -- second attempt persists with attemptnumber=2.
     */
    public function test_retakes_on_persists_second_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(true);
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [$itemids[0] => 5, $itemids[1] => 5];
        $cm = $this->cm_for((int)$scorecard->id);

        $first = scorecard_handle_submission($scorecard, $cm, (int)$user->id, $rawresponses);
        $second = scorecard_handle_submission($scorecard, $cm, (int)$user->id, $rawresponses);

        $this->assertSame('submitted', $first['status']);
        $this->assertSame('submitted', $second['status']);
        $this->assertSame(2, $DB->count_records('scorecard_attempts', ['scorecardid' => $scorecard->id]));
        $this->assertSame(2, (int)$DB->get_field('scorecard_attempts', 'attemptnumber', ['id' => $second['attemptid']]));
    }

    /**
     * Case 8: audit-write -- response row preserved for an item soft-deleted between render and submit.
     *
     * Form rendered with items A and B both visible; learner answered both;
     * teacher soft-deleted B in another tab before learner clicked submit. The
     * response row for B is still written to scorecard_responses (audit trail
     * for Phase 4 reports), but the engine sums only over visible items so
     * totalscore reflects A only.
     */
    public function test_audit_write_preserves_response_for_soft_deleted_item(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        // Soft-delete item B between render and submit.
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemids[1]]);

        $rawresponses = [
            $itemids[0] => 7, // Visible item -- contributes to totalscore.
            $itemids[1] => 9, // Soft-deleted item -- audit-only response.
        ];

        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );

        $this->assertSame('submitted', $result['status']);

        $attempt = $DB->get_record('scorecard_attempts', ['id' => $result['attemptid']], '*', MUST_EXIST);
        $this->assertSame(7, (int)$attempt->totalscore, 'Engine must sum only over visible items.');
        $this->assertSame(10, (int)$attempt->maxscore, 'Maxscore reflects visible item count only.');

        $rows = $DB->get_records('scorecard_responses', ['attemptid' => $result['attemptid']]);
        $this->assertCount(2, $rows, 'Both responses written, including audit-only soft-deleted item.');
        $stored = [];
        foreach ($rows as $r) {
            $stored[(int)$r->itemid] = (int)$r->responsevalue;
        }
        $this->assertSame(7, $stored[$itemids[0]]);
        $this->assertSame(9, $stored[$itemids[1]]);
    }

    /**
     * Case 9: event fires after commit and carries the engine's score data.
     */
    public function test_event_fires_after_commit(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $itemids = $this->add_items((int)$scorecard->id, 2);
        $user = $this->getDataGenerator()->create_user();

        $rawresponses = [$itemids[0] => 5, $itemids[1] => 5];

        $sink = $this->redirectEvents();
        $result = scorecard_handle_submission(
            $scorecard,
            $this->cm_for((int)$scorecard->id),
            (int)$user->id,
            $rawresponses
        );
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame('submitted', $result['status']);
        $matching = array_filter(
            $events,
            fn($e) => $e instanceof \mod_scorecard\event\attempt_submitted
        );
        $this->assertCount(1, $matching, 'Exactly one attempt_submitted event fires.');

        $event = reset($matching);
        $this->assertSame((int)$user->id, (int)$event->userid);
        $this->assertSame((int)$result['attemptid'], (int)$event->objectid);
        $this->assertSame(10, $event->other['totalscore']);
    }
}
