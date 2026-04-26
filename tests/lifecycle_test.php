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
 * Tests for mod_scorecard lifecycle enforcement.
 *
 * Covers SPEC §4.5 lifecycle rules implemented in 2.5: scale lock once
 * attempts exist (scorecard_scale_change_allowed), and the corresponding
 * warning lang strings used by mod_form and the items add-path.
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
 * Lifecycle gate tests: scale-change allowed/blocked, warning lang strings.
 */
#[CoversNothing]
final class lifecycle_test extends \advanced_testcase {
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
            'name' => 'Lifecycle fixture',
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
     * Scale change is allowed when no attempts exist.
     */
    public function test_scale_change_allowed_when_no_attempts(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $this->assertSame(0, scorecard_count_attempts((int)$scorecard->id));
        $this->assertTrue(scorecard_scale_change_allowed((int)$scorecard->id));
    }

    /**
     * Scale change is blocked once at least one attempt exists.
     */
    public function test_scale_change_blocked_when_attempts_exist(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $this->insert_attempt((int)$scorecard->id);

        $this->assertSame(1, scorecard_count_attempts((int)$scorecard->id));
        $this->assertFalse(scorecard_scale_change_allowed((int)$scorecard->id));
    }

    /**
     * The lifecycle warning lang strings resolve cleanly (no [[…]] placeholder).
     *
     * Catches typo regressions in lang/en/scorecard.php where the manage.php
     * add-path or mod_form.php validation would surface a missing-string marker
     * to the operator instead of the intended copy.
     */
    public function test_lifecycle_lang_strings_resolve(): void {
        $added = get_string('item:notify:added_with_attempts', 'mod_scorecard');
        $this->assertStringNotContainsString('[[', $added);
        $this->assertStringContainsString('Existing attempts', $added);

        $blocked = get_string('error:scalechangeblocked', 'mod_scorecard');
        $this->assertStringNotContainsString('[[', $blocked);
        $this->assertStringContainsString('attempts', $blocked);
    }
}
