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
 * Tests for mod_scorecard locallib helpers — item CRUD, reorder, lifecycle gate.
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
 * Item CRUD round-trip, sortorder reorder, soft/hard-delete branching.
 */
#[CoversNothing]
final class locallib_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture and return its row.
     *
     * @return \stdClass scorecard row.
     */
    private function create_scorecard(): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $data = (object)[
            'course' => $course->id,
            'name' => 'Fixture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'scalemin' => 1,
            'scalemax' => 10,
            'displaystyle' => 'radio',
            'lowlabel' => '',
            'highlabel' => '',
            'allowretakes' => 0,
            'showresult' => 1,
            'showpercentage' => 0,
            'showitemsummary' => 1,
            'fallbackmessage_editor' => ['text' => '', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ];
        $id = scorecard_add_instance($data);
        return $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Add N items via scorecard_add_item, returning their ids in insertion order.
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
                'lowlabel' => '',
                'highlabel' => '',
                'visible' => 1,
            ]);
        }
        return $ids;
    }

    /**
     * Insert a synthetic attempt row to flip the lifecycle gate.
     *
     * @param int $scorecardid
     * @return int Attempt id.
     */
    private function insert_attempt(int $scorecardid): int {
        global $DB;
        return (int)$DB->insert_record('scorecard_attempts', [
            'scorecardid' => $scorecardid,
            'userid' => 2,
            'attemptnumber' => 1,
            'totalscore' => 5,
            'maxscore' => 10,
            'percentage' => 50.00,
            'bandid' => null,
            'bandlabelsnapshot' => '',
            'bandmessagesnapshot' => '',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * scorecard_add_item appends a row with default visible=1, deleted=0,
     * required=1, and sortorder=1 (first item).
     */
    public function test_add_item_creates_row_with_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $itemid = scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'First',
            'promptformat' => FORMAT_HTML,
        ]);

        $row = $DB->get_record('scorecard_items', ['id' => $itemid], '*', MUST_EXIST);
        $this->assertSame((int)$scorecard->id, (int)$row->scorecardid);
        $this->assertSame('First', $row->prompt);
        $this->assertSame((string)FORMAT_HTML, (string)$row->promptformat);
        $this->assertSame(1, (int)$row->visible);
        $this->assertSame(0, (int)$row->deleted);
        $this->assertSame(1, (int)$row->required);
        $this->assertSame(1, (int)$row->sortorder);
        $this->assertGreaterThan(0, (int)$row->timecreated);
        $this->assertGreaterThan(0, (int)$row->timemodified);
    }

    /**
     * Successive adds get sortorder 1, 2, 3 (append-at-end semantics).
     */
    public function test_add_item_appends_sortorder(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $ids = $this->add_items($scorecard->id, 3);

        $rows = $DB->get_records_list(
            'scorecard_items',
            'id',
            $ids,
            'sortorder ASC',
            'id, sortorder'
        );
        $sortorders = [];
        foreach ($rows as $row) {
            $sortorders[] = (int)$row->sortorder;
        }
        $this->assertSame([1, 2, 3], $sortorders);
    }

    /**
     * scorecard_update_item updates editable fields and refreshes timemodified.
     */
    public function test_update_item_changes_editable_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 1);

        $this->waitForSecond();
        scorecard_update_item((object)[
            'id' => $ids[0],
            'prompt' => 'Edited',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => 'L',
            'highlabel' => 'H',
            'visible' => 0,
        ]);

        $row = $DB->get_record('scorecard_items', ['id' => $ids[0]], '*', MUST_EXIST);
        $this->assertSame('Edited', $row->prompt);
        $this->assertSame('L', $row->lowlabel);
        $this->assertSame('H', $row->highlabel);
        $this->assertSame(0, (int)$row->visible);
    }

    /**
     * scorecard_update_item ignores attempts to change scorecardid, sortorder,
     * or deleted (those concerns belong to dedicated helpers).
     */
    public function test_update_item_strips_locked_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 1);
        $original = $DB->get_record('scorecard_items', ['id' => $ids[0]], '*', MUST_EXIST);

        scorecard_update_item((object)[
            'id' => $ids[0],
            'prompt' => 'Edited',
            'promptformat' => FORMAT_HTML,
            'scorecardid' => 999,
            'sortorder' => 99,
            'deleted' => 1,
        ]);

        $row = $DB->get_record('scorecard_items', ['id' => $ids[0]], '*', MUST_EXIST);
        $this->assertSame((int)$original->scorecardid, (int)$row->scorecardid);
        $this->assertSame((int)$original->sortorder, (int)$row->sortorder);
        $this->assertSame(0, (int)$row->deleted);
    }

    /**
     * Hard-delete path: no attempts → row removed, remaining items renumbered to 1..N.
     */
    public function test_delete_item_no_attempts_hard_deletes_and_renumbers(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);

        scorecard_delete_item($ids[1]);

        $this->assertFalse($DB->record_exists('scorecard_items', ['id' => $ids[1]]));

        $rows = $DB->get_records(
            'scorecard_items',
            ['scorecardid' => $scorecard->id],
            'sortorder ASC',
            'id, sortorder'
        );
        $sortorders = [];
        foreach ($rows as $row) {
            $sortorders[] = (int)$row->sortorder;
        }
        $this->assertSame([1, 2], $sortorders);
    }

    /**
     * Soft-delete path: attempt exists → deleted flag set, row retained,
     * sortorder unchanged so historical references resolve.
     */
    public function test_delete_item_with_attempts_soft_deletes(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);
        $this->insert_attempt($scorecard->id);

        scorecard_delete_item($ids[1]);

        $row = $DB->get_record('scorecard_items', ['id' => $ids[1]], '*', MUST_EXIST);
        $this->assertSame(1, (int)$row->deleted);
        $this->assertSame(2, (int)$row->sortorder);

        $row1 = $DB->get_record('scorecard_items', ['id' => $ids[0]], 'sortorder');
        $row3 = $DB->get_record('scorecard_items', ['id' => $ids[2]], 'sortorder');
        $this->assertSame(1, (int)$row1->sortorder);
        $this->assertSame(3, (int)$row3->sortorder);
    }

    /**
     * scorecard_move_item('up') swaps sortorder with the previous neighbour.
     */
    public function test_move_item_up_swaps_sortorder(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);

        scorecard_move_item($ids[1], 'up');

        $row1 = $DB->get_record('scorecard_items', ['id' => $ids[0]], 'sortorder');
        $row2 = $DB->get_record('scorecard_items', ['id' => $ids[1]], 'sortorder');
        $this->assertSame(2, (int)$row1->sortorder);
        $this->assertSame(1, (int)$row2->sortorder);
    }

    /**
     * scorecard_move_item('down') swaps sortorder with the next neighbour.
     */
    public function test_move_item_down_swaps_sortorder(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);

        scorecard_move_item($ids[0], 'down');

        $row1 = $DB->get_record('scorecard_items', ['id' => $ids[0]], 'sortorder');
        $row2 = $DB->get_record('scorecard_items', ['id' => $ids[1]], 'sortorder');
        $this->assertSame(2, (int)$row1->sortorder);
        $this->assertSame(1, (int)$row2->sortorder);
    }

    /**
     * Move-up at the top is a no-op; move-down at the bottom is a no-op.
     */
    public function test_move_item_at_boundary_is_noop(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);

        scorecard_move_item($ids[0], 'up');
        scorecard_move_item($ids[2], 'down');

        $rows = $DB->get_records_list(
            'scorecard_items',
            'id',
            $ids,
            'sortorder ASC',
            'id, sortorder'
        );
        $sortorders = [];
        foreach ($rows as $row) {
            $sortorders[] = (int)$row->sortorder;
        }
        $this->assertSame([1, 2, 3], $sortorders);
    }

    /**
     * Move skips soft-deleted neighbours.
     */
    public function test_move_item_skips_soft_deleted_neighbour(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);

        // Soft-delete item 2 directly (bypass the lifecycle gate for fixturing).
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $ids[1]]);

        // Item 3 move-up should swap with item 1, skipping deleted item 2.
        scorecard_move_item($ids[2], 'up');

        $row1 = $DB->get_record('scorecard_items', ['id' => $ids[0]], 'sortorder');
        $row2 = $DB->get_record('scorecard_items', ['id' => $ids[1]], 'sortorder');
        $row3 = $DB->get_record('scorecard_items', ['id' => $ids[2]], 'sortorder');
        $this->assertSame(3, (int)$row1->sortorder);
        $this->assertSame(2, (int)$row2->sortorder);
        $this->assertSame(1, (int)$row3->sortorder);
    }

    /**
     * scorecard_count_attempts returns 0 for a fresh scorecard, 1 after insert.
     *
     * Two scorecards used to avoid the per-request cache shadowing the post-insert
     * count (the cache is correct in production — once you've read 0 within a
     * request, an attempt added later in the same request should still report 0
     * to keep delete-flow semantics consistent).
     */
    public function test_count_attempts_returns_correct_count(): void {
        $this->resetAfterTest();
        $first = $this->create_scorecard();
        $second = $this->create_scorecard();

        $this->assertSame(0, scorecard_count_attempts((int)$first->id));

        $this->insert_attempt($second->id);
        $this->assertSame(1, scorecard_count_attempts((int)$second->id));
    }

    /**
     * scorecard_renumber_items collapses gaps left by hard-delete.
     */
    public function test_renumber_items_collapses_gaps(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $ids = $this->add_items($scorecard->id, 3);

        // Manually create a sortorder gap by setting items to 1, 5, 9.
        $DB->set_field('scorecard_items', 'sortorder', 1, ['id' => $ids[0]]);
        $DB->set_field('scorecard_items', 'sortorder', 5, ['id' => $ids[1]]);
        $DB->set_field('scorecard_items', 'sortorder', 9, ['id' => $ids[2]]);

        scorecard_renumber_items((int)$scorecard->id);

        $rows = $DB->get_records_list(
            'scorecard_items',
            'id',
            $ids,
            'sortorder ASC',
            'id, sortorder'
        );
        $sortorders = [];
        foreach ($rows as $row) {
            $sortorders[] = (int)$row->sortorder;
        }
        $this->assertSame([1, 2, 3], $sortorders);
    }
}
