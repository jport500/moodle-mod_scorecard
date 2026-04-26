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
 * Tests for mod_scorecard activity-module API in lib.php.
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

/**
 * CRUD round-trip tests for scorecard_add_instance, scorecard_update_instance,
 * scorecard_delete_instance, including the dependent-row cascade on delete.
 *
 * Phase 1.5 ships these as integration-style skeleton tests; later phases
 * will add unit tests with strict per-method coverage as the scoring engine
 * and item authoring logic land.
 */
#[CoversNothing]
final class lib_test extends \advanced_testcase {
    /**
     * Build a $data object shaped like mod_form output for a fresh activity.
     *
     * @param int $courseid Course to attach to.
     * @return \stdClass
     */
    private function build_form_data(int $courseid): \stdClass {
        return (object)[
            'course' => $courseid,
            'name' => 'Test scorecard',
            'intro' => '<p>Intro</p>',
            'introformat' => FORMAT_HTML,
            'scalemin' => 1,
            'scalemax' => 10,
            'displaystyle' => 'radio',
            'lowlabel' => 'Low',
            'highlabel' => 'High',
            'allowretakes' => 0,
            'showresult' => 1,
            'showpercentage' => 0,
            'showitemsummary' => 1,
            'fallbackmessage_editor' => [
                'text' => '<p>Fallback</p>',
                'format' => FORMAT_HTML,
            ],
            'gradeenabled' => 0,
            'grade' => 0,
        ];
    }

    /**
     * scorecard_add_instance inserts a row with all submitted fields and returns its id.
     */
    public function test_add_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $data = $this->build_form_data($course->id);

        $id = scorecard_add_instance($data);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $row = $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Test scorecard', $row->name);
        $this->assertSame(1, (int)$row->scalemin);
        $this->assertSame(10, (int)$row->scalemax);
        $this->assertSame('radio', $row->displaystyle);
        $this->assertSame('<p>Fallback</p>', $row->fallbackmessage);
        $this->assertSame((string)FORMAT_HTML, (string)$row->fallbackmessageformat);
        $this->assertGreaterThan(0, (int)$row->timecreated);
        $this->assertGreaterThan(0, (int)$row->timemodified);
    }

    /**
     * scorecard_update_instance updates the row in place and refreshes timemodified.
     */
    public function test_update_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $data = $this->build_form_data($course->id);
        $id = scorecard_add_instance($data);

        $original = $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);

        // Sleep long enough for timemodified to advance reliably.
        $this->waitForSecond();

        $update = $this->build_form_data($course->id);
        $update->instance = $id;
        $update->name = 'Updated name';
        $update->scalemax = 5;
        $update->allowretakes = 1;

        $result = scorecard_update_instance($update);
        $this->assertTrue((bool)$result);

        $updated = $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Updated name', $updated->name);
        $this->assertSame(5, (int)$updated->scalemax);
        $this->assertSame(1, (int)$updated->allowretakes);
        $this->assertGreaterThanOrEqual((int)$original->timemodified, (int)$updated->timemodified);
    }

    /**
     * scorecard_delete_instance cascades through responses, attempts, bands, items, scorecard.
     */
    public function test_delete_instance_cascades(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $data = $this->build_form_data($course->id);
        $id = scorecard_add_instance($data);

        $now = time();

        $itemid = $DB->insert_record('scorecard_items', [
            'scorecardid' => $id,
            'prompt' => 'Item',
            'promptformat' => FORMAT_HTML,
            'sortorder' => 1,
            'required' => 1,
            'visible' => 1,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $bandid = $DB->insert_record('scorecard_bands', [
            'scorecardid' => $id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Low',
            'message' => 'msg',
            'messageformat' => FORMAT_HTML,
            'sortorder' => 1,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $attemptid = $DB->insert_record('scorecard_attempts', [
            'scorecardid' => $id,
            'userid' => 2,
            'attemptnumber' => 1,
            'totalscore' => 5,
            'maxscore' => 10,
            'percentage' => 50.00,
            'bandid' => $bandid,
            'bandlabelsnapshot' => 'Low',
            'bandmessagesnapshot' => 'msg',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('scorecard_responses', [
            'attemptid' => $attemptid,
            'itemid' => $itemid,
            'responsevalue' => 5,
            'timecreated' => $now,
        ]);

        $this->assertEquals(1, $DB->count_records('scorecard_items', ['scorecardid' => $id]));
        $this->assertEquals(1, $DB->count_records('scorecard_bands', ['scorecardid' => $id]));
        $this->assertEquals(1, $DB->count_records('scorecard_attempts', ['scorecardid' => $id]));
        $this->assertEquals(1, $DB->count_records('scorecard_responses', ['attemptid' => $attemptid]));

        $this->assertTrue(scorecard_delete_instance($id));

        $this->assertEquals(0, $DB->count_records('scorecard', ['id' => $id]));
        $this->assertEquals(0, $DB->count_records('scorecard_items', ['scorecardid' => $id]));
        $this->assertEquals(0, $DB->count_records('scorecard_bands', ['scorecardid' => $id]));
        $this->assertEquals(0, $DB->count_records('scorecard_attempts', ['scorecardid' => $id]));
        $this->assertEquals(0, $DB->count_records('scorecard_responses', ['attemptid' => $attemptid]));
    }

    /**
     * scorecard_delete_instance returns false if the scorecard does not exist.
     */
    public function test_delete_instance_returns_false_for_unknown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertFalse(scorecard_delete_instance(999999));
    }

    /**
     * scorecard_supports declares the phase-honest feature flags.
     */
    public function test_supports(): void {
        $this->assertTrue(scorecard_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(scorecard_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertTrue(scorecard_supports(FEATURE_GROUPS));
        $this->assertSame(MOD_PURPOSE_ASSESSMENT, scorecard_supports(FEATURE_MOD_PURPOSE));

        // Phase 1.4 flipped backup support to true.
        $this->assertTrue(scorecard_supports(FEATURE_BACKUP_MOODLE2));

        // Phase 5a will flip these.
        $this->assertFalse(scorecard_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(scorecard_supports(FEATURE_COMPLETION_HAS_RULES));

        $this->assertNull(scorecard_supports('unknown_feature_xyz'));
    }
}
