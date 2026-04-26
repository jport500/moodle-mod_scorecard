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
 * Tests for mod_scorecard band CRUD helpers in locallib.
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
 * Band CRUD round-trip + lifecycle-gated soft/hard-delete branching.
 */
#[CoversNothing]
final class locallib_band_test extends \advanced_testcase {
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
     * Insert a synthetic attempt to flip the lifecycle gate.
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
     * scorecard_add_band creates a row with the form-supplied range and label,
     * defaults messageformat, sets deleted=0, and assigns sortorder=1 for the first row.
     */
    public function test_add_band_creates_row_with_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $bandid = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Low',
            'message' => 'Low message',
            'messageformat' => FORMAT_HTML,
        ]);

        $row = $DB->get_record('scorecard_bands', ['id' => $bandid], '*', MUST_EXIST);
        $this->assertSame((int)$scorecard->id, (int)$row->scorecardid);
        $this->assertSame(0, (int)$row->minscore);
        $this->assertSame(10, (int)$row->maxscore);
        $this->assertSame('Low', $row->label);
        $this->assertSame('Low message', $row->message);
        $this->assertSame((string)FORMAT_HTML, (string)$row->messageformat);
        $this->assertSame(0, (int)$row->deleted);
        $this->assertSame(1, (int)$row->sortorder);
        $this->assertGreaterThan(0, (int)$row->timecreated);
    }

    /**
     * Successive adds get incremental sortorder, regardless of minscore order.
     */
    public function test_add_band_sortorder_increments_independent_of_minscore(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        // Insert in a non-natural minscore order to confirm sortorder is independent.
        $high = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 30, 'maxscore' => 40, 'label' => 'High',
        ]);
        $low = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0, 'maxscore' => 10, 'label' => 'Low',
        ]);

        $rowhigh = $DB->get_record('scorecard_bands', ['id' => $high]);
        $rowlow = $DB->get_record('scorecard_bands', ['id' => $low]);
        $this->assertSame(1, (int)$rowhigh->sortorder);
        $this->assertSame(2, (int)$rowlow->sortorder);
    }

    /**
     * scorecard_update_band updates editable fields and strips locked fields.
     */
    public function test_update_band_strips_locked_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $bandid = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Low',
        ]);
        $original = $DB->get_record('scorecard_bands', ['id' => $bandid], '*', MUST_EXIST);

        scorecard_update_band((object)[
            'id' => $bandid,
            'minscore' => 5,
            'maxscore' => 15,
            'label' => 'Medium',
            'scorecardid' => 999,
            'sortorder' => 99,
            'deleted' => 1,
        ]);

        $row = $DB->get_record('scorecard_bands', ['id' => $bandid], '*', MUST_EXIST);
        $this->assertSame(5, (int)$row->minscore);
        $this->assertSame(15, (int)$row->maxscore);
        $this->assertSame('Medium', $row->label);
        $this->assertSame((int)$original->scorecardid, (int)$row->scorecardid);
        $this->assertSame((int)$original->sortorder, (int)$row->sortorder);
        $this->assertSame(0, (int)$row->deleted);
    }

    /**
     * Hard-delete path: no attempts → row removed.
     */
    public function test_delete_band_no_attempts_hard_deletes(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $bandid = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Low',
        ]);

        scorecard_delete_band($bandid);

        $this->assertFalse($DB->record_exists('scorecard_bands', ['id' => $bandid]));
    }

    /**
     * Soft-delete path: attempt exists → deleted=1, row retained for FK integrity.
     */
    public function test_delete_band_with_attempts_soft_deletes(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $bandid = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Low',
        ]);
        $this->insert_attempt($scorecard->id);

        scorecard_delete_band($bandid);

        $row = $DB->get_record('scorecard_bands', ['id' => $bandid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$row->deleted);
        $this->assertSame('Low', $row->label);
    }
}
