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
 * Tests for mod_scorecard gradebook integration callbacks.
 *
 * Covers Phase 5a.1: scorecard_grade_item_update, scorecard_grade_item_delete,
 * scorecard_get_user_grades, scorecard_update_grades, plus the
 * scorecard_compute_auto_grademax helper. Lifecycle hooks
 * (add_instance / update_instance / delete_instance) are exercised
 * implicitly through create_module / update / delete paths.
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
require_once($CFG->libdir . '/gradelib.php');

/**
 * Phase 5a.1 grade callback tests.
 */
#[CoversNothing]
final class grade_test extends \advanced_testcase {
    /**
     * Create a course + scorecard activity with the given override attrs.
     *
     * @param array $attrs Field overrides merged on top of the defaults.
     * @return \stdClass Scorecard activity record from create_module.
     */
    private function make_scorecard(array $attrs = []): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $defaults = [
            'course' => $course->id,
            'name' => 'Test scorecard',
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemin' => 1,
            'scalemax' => 10,
        ];
        $attrs = array_merge($defaults, $attrs);
        return $this->getDataGenerator()->create_module('scorecard', (object)$attrs);
    }

    /**
     * Get the course_module record for a scorecard.
     *
     * Phase 5a.3 tests need to call scorecard_handle_submission, which
     * takes a $cm parameter. This helper resolves it from the scorecard
     * activity record via Moodle's standard cm-aware lookup.
     *
     * @param \stdClass $scorecard Scorecard activity record.
     * @return \stdClass Course module record.
     */
    private function get_cm_for_scorecard(\stdClass $scorecard): \stdClass {
        return \get_coursemodule_from_instance(
            'scorecard',
            (int)$scorecard->id,
            0,
            false,
            MUST_EXIST
        );
    }

    /**
     * Insert a visible scorecard item directly into the database.
     *
     * @param int $scorecardid Parent scorecard id.
     * @return int New item id.
     */
    private function add_visible_item(int $scorecardid): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecardid,
            'prompt' => 'Item prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a scorecard attempt directly into the database.
     *
     * @param int $scorecardid Parent scorecard id.
     * @param int $userid User the attempt belongs to.
     * @param int $totalscore Stored totalscore for the attempt.
     * @param int $maxscore Stored maxscore (defaults to 30).
     * @return int New attempt id.
     */
    private function add_attempt(
        int $scorecardid,
        int $userid,
        int $totalscore,
        int $maxscore = 30
    ): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('scorecard_attempts', (object)[
            'scorecardid' => $scorecardid,
            'userid' => $userid,
            'attemptnumber' => 1,
            'totalscore' => $totalscore,
            'maxscore' => $maxscore,
            'percentage' => $maxscore > 0 ? round($totalscore / $maxscore * 100, 2) : 0,
            'bandid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Assert grade item attributes for a scorecard via grade_item::fetch.
     *
     * grade_get_grades is display-oriented and does not expose gradetype /
     * grademax on its returned items. grade_item::fetch reads the canonical
     * grade_items row, which is the right surface for introspection.
     *
     * @param \stdClass $scorecard Scorecard activity record.
     * @param array $expected Map of grade-item attribute => expected value.
     */
    private function assert_scorecard_grade_item(
        \stdClass $scorecard,
        array $expected
    ): void {
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'scorecard',
            'iteminstance' => (int)$scorecard->id,
            'courseid' => (int)$scorecard->course,
        ]);
        $this->assertNotFalse($gradeitem, 'Grade item not found for scorecard.');
        foreach ($expected as $key => $value) {
            $this->assertEquals(
                $value,
                $gradeitem->$key,
                "Grade item {$key} mismatch."
            );
        }
    }

    /**
     * Assert a specific user's grade on a scorecard.
     *
     * @param \stdClass $scorecard Scorecard activity record.
     * @param int $userid User to check.
     * @param mixed $expectedraw Expected rawgrade value.
     */
    private function assert_scorecard_user_grade(
        \stdClass $scorecard,
        int $userid,
        $expectedraw
    ): void {
        $items = \grade_get_grades(
            (int)$scorecard->course,
            'mod',
            'scorecard',
            (int)$scorecard->id,
            [$userid]
        );
        $this->assertNotEmpty($items->items, 'Grade item not found.');
        $item = reset($items->items);
        $this->assertArrayHasKey($userid, $item->grades);
        $this->assertEquals(
            $expectedraw,
            $item->grades[$userid]->grade,
            "User {$userid} grade mismatch."
        );
    }

    /**
     * gradeenabled=0 at activity creation results in no grade item being
     * created — the gradebook has nothing to display.
     *
     * This differs from the toggle case (1→0) where a grade item that
     * existed under gradeenabled=1 has its gradetype set to NONE rather
     * than being removed. See
     * test_grade_item_toggle_to_disabled_changes_gradetype for that path.
     * Both shapes have the same operator-visible result (no grade column
     * shown for the activity); the implementation difference reflects
     * Moodle's grade_update behavior: gradetype=NONE updates an existing
     * grade item but does not create one when none exists.
     */
    public function test_no_grade_item_when_gradeenabled_disabled_at_creation(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard(['gradeenabled' => 0]);

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'scorecard',
            'iteminstance' => (int)$scorecard->id,
            'courseid' => (int)$scorecard->course,
        ]);
        $this->assertFalse(
            $gradeitem,
            'Grade item should not exist for gradeenabled=0 scorecard at creation.'
        );
    }

    /**
     * gradeenabled=1 with explicit grade > 0 uses that value as grademax.
     */
    public function test_grade_item_uses_explicit_grademax_when_set(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard(['gradeenabled' => 1, 'grade' => 50]);
        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 50.0,
        ]);
    }

    /**
     * gradeenabled=1 with grade=0 auto-computes grademax from visible items.
     *
     * 5a.1 does not hook items-CRUD lifecycle (that's 5a.2). To exercise the
     * auto-grademax computation here, we add items directly and then call
     * scorecard_grade_item_update manually — simulating what 5a.2's hook
     * will do automatically.
     */
    public function test_grade_item_auto_grademax_from_visible_items(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemax' => 10,
        ]);

        $this->add_visible_item((int)$scorecard->id);
        $this->add_visible_item((int)$scorecard->id);
        $this->add_visible_item((int)$scorecard->id);

        scorecard_grade_item_update($scorecard);

        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 30.0,
        ]);
    }

    /**
     * Toggling gradeenabled from 1 to 0 via update_instance changes gradetype
     * back to NONE without deleting the underlying grade item.
     */
    public function test_grade_item_toggle_to_disabled_changes_gradetype(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard(['gradeenabled' => 1, 'grade' => 50]);
        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_VALUE,
        ]);

        $scorecard->gradeenabled = 0;
        $scorecard->instance = $scorecard->id;
        scorecard_update_instance($scorecard);

        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_NONE,
        ]);
    }

    /**
     * delete_instance removes the grade item from the gradebook entirely.
     */
    public function test_delete_instance_removes_grade_item(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard(['gradeenabled' => 1, 'grade' => 50]);
        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_VALUE,
        ]);

        scorecard_delete_instance((int)$scorecard->id);

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'scorecard',
            'iteminstance' => (int)$scorecard->id,
            'courseid' => (int)$scorecard->course,
        ]);
        $this->assertFalse(
            $gradeitem,
            'Grade item should be removed after delete_instance.'
        );
    }

    /**
     * scorecard_get_user_grades returns the user's latest attempt per SPEC §9.2.
     *
     * Two attempts on the same scorecard for the same user; the second
     * (later id) is the one that should propagate as the user's grade.
     */
    public function test_get_user_grades_returns_latest_per_user(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard(['gradeenabled' => 1, 'grade' => 30]);
        $user = $this->getDataGenerator()->create_user();

        $this->add_attempt((int)$scorecard->id, (int)$user->id, 15);
        $this->add_attempt((int)$scorecard->id, (int)$user->id, 25);

        $grades = scorecard_get_user_grades($scorecard, (int)$user->id);
        $this->assertCount(1, $grades);
        $this->assertEquals(25, $grades[(int)$user->id]->rawgrade);
    }

    /**
     * scorecard_get_user_grades with userid=0 returns one row per user
     * (latest attempt each), correctly handling multiple users with
     * multiple attempts.
     */
    public function test_get_user_grades_multiple_users_multiple_attempts(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard(['gradeenabled' => 1, 'grade' => 30]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $this->add_attempt((int)$scorecard->id, (int)$user1->id, 10);
        $this->add_attempt((int)$scorecard->id, (int)$user2->id, 20);
        $this->add_attempt((int)$scorecard->id, (int)$user1->id, 12);
        $this->add_attempt((int)$scorecard->id, (int)$user2->id, 22);

        $grades = scorecard_get_user_grades($scorecard);
        $this->assertCount(2, $grades);
        $this->assertEquals(12, $grades[(int)$user1->id]->rawgrade);
        $this->assertEquals(22, $grades[(int)$user2->id]->rawgrade);
    }

    /**
     * Adding visible items with no attempts yet recomputes grademax to
     * reflect the new visible-item count × scalemax (SPEC §9.2 lifecycle
     * gate). Phase 5a.2 hook in scorecard_add_item.
     */
    public function test_add_item_recomputes_grademax_with_zero_attempts(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemax' => 10,
        ]);

        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 10.0,
        ]);

        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 2',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, [
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 20.0,
        ]);
    }

    /**
     * Toggling an item's visibility from 1 to 0 with no attempts yet
     * recomputes grademax (the now-invisible item drops out of the count).
     * Phase 5a.2 hook in scorecard_update_item.
     */
    public function test_update_item_visibility_toggle_recomputes_grademax(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemax' => 10,
        ]);

        $itemid1 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 2',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 20.0]);

        scorecard_update_item((object)[
            'id' => $itemid1,
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 0,
        ]);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 10.0]);
    }

    /**
     * Hard-deleting an item with no attempts yet recomputes grademax to
     * the smaller visible-item count. Phase 5a.2 hook in
     * scorecard_delete_item's hard-delete branch.
     */
    public function test_delete_item_hard_recomputes_grademax(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemax' => 10,
        ]);

        $itemid1 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 2',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 20.0]);

        scorecard_delete_item($itemid1);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 10.0]);
    }

    /**
     * Lifecycle gate: adding an item AFTER an attempt exists does NOT
     * recompute grademax. The grade item's grademax stays frozen at its
     * pre-attempt value (SPEC §9.2 + §11.2 stability rules applied to
     * grade items).
     */
    public function test_add_item_after_attempt_freezes_grademax(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemax' => 10,
        ]);

        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 2',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 20.0]);

        $user = $this->getDataGenerator()->create_user();
        $this->add_attempt((int)$scorecard->id, (int)$user->id, 15);

        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 3',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 20.0]);
    }

    /**
     * Lifecycle gate on the delete branch: deleting an item AFTER an
     * attempt exists takes scorecard_delete_item's soft-delete branch,
     * which does NOT call the recompute helper. Grademax stays frozen.
     * This pins the structural placement of the recompute hook in the
     * hard-delete branch only.
     */
    public function test_soft_delete_does_not_recompute_grademax(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 0,
            'scalemax' => 10,
        ]);

        $itemid1 = scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => (int)$scorecard->id,
            'prompt' => 'Item 2',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
        ]);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 20.0]);

        $user = $this->getDataGenerator()->create_user();
        $this->add_attempt((int)$scorecard->id, (int)$user->id, 15);

        scorecard_delete_item($itemid1);
        $this->assert_scorecard_grade_item($scorecard, ['grademax' => 20.0]);
    }

    /**
     * Submitting an attempt via scorecard_handle_submission propagates the
     * user's totalscore to the gradebook (Phase 5a.3 hook in the handler
     * after event trigger). Per SPEC §9.2 (Decision v0.4.2), the
     * user's grade is the latest attempt's totalscore.
     */
    public function test_submit_attempt_propagates_to_gradebook(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 1,
            'grade' => 10,
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $itemid = $this->add_visible_item((int)$scorecard->id);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->get_cm_for_scorecard($scorecard);

        $result = scorecard_handle_submission(
            $scorecard,
            $cm,
            (int)$user->id,
            [$itemid => 7]
        );
        $this->assertSame('submitted', $result['status']);

        $this->assert_scorecard_user_grade($scorecard, (int)$user->id, 7);
    }

    /**
     * Submitting an attempt with gradeenabled=0 does NOT create a grade
     * item in the gradebook. The handler's update_grades call still runs
     * but is a benign no-op in this case (per the gradetype=NONE
     * create-vs-update behavior split documented in
     * test_no_grade_item_when_gradeenabled_disabled_at_creation).
     */
    public function test_submit_attempt_does_not_create_gradebook_entry_when_disabled(): void {
        $this->resetAfterTest();
        $scorecard = $this->make_scorecard([
            'gradeenabled' => 0,
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $itemid = $this->add_visible_item((int)$scorecard->id);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->get_cm_for_scorecard($scorecard);

        $result = scorecard_handle_submission(
            $scorecard,
            $cm,
            (int)$user->id,
            [$itemid => 7]
        );
        $this->assertSame('submitted', $result['status']);

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'scorecard',
            'iteminstance' => (int)$scorecard->id,
            'courseid' => (int)$scorecard->course,
        ]);
        $this->assertFalse(
            $gradeitem,
            'No grade item should exist for gradeenabled=0 scorecard after submit.'
        );
    }

    /**
     * Submitting an attempt with completionsubmit=1 marks the cm complete
     * for the user (SPEC §9.3). Phase 5a.4 hook in
     * scorecard_handle_submission calls completion_info::update_state when
     * the cm has completion tracking enabled and the rule is set.
     */
    public function test_completion_complete_after_submit_when_completionsubmit_enabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $scorecard = $this->getDataGenerator()->create_module(
            'scorecard',
            (object)[
                'course' => $course->id,
                'name' => 'Completion test',
                'gradeenabled' => 1,
                'grade' => 10,
                'scalemin' => 1,
                'scalemax' => 10,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionsubmit' => 1,
            ]
        );
        $itemid = $this->add_visible_item((int)$scorecard->id);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->get_cm_for_scorecard($scorecard);

        $result = scorecard_handle_submission(
            $scorecard,
            $cm,
            (int)$user->id,
            [$itemid => 7]
        );
        $this->assertSame('submitted', $result['status']);

        $completion = new \completion_info($course);
        $cmdata = $completion->get_data($cm, false, (int)$user->id);
        $this->assertEquals(
            COMPLETION_COMPLETE,
            (int)$cmdata->completionstate,
            'Activity should be marked complete after submit with completionsubmit=1.'
        );
    }

    /**
     * Submitting an attempt with completionsubmit=0 does NOT mark the cm
     * complete. The hook in scorecard_handle_submission gates on
     * !empty($scorecard->completionsubmit) and does not fire update_state
     * when the rule is disabled.
     */
    public function test_completion_incomplete_when_completionsubmit_disabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $scorecard = $this->getDataGenerator()->create_module(
            'scorecard',
            (object)[
                'course' => $course->id,
                'name' => 'Completion test (disabled)',
                'gradeenabled' => 1,
                'grade' => 10,
                'scalemin' => 1,
                'scalemax' => 10,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionsubmit' => 0,
            ]
        );
        $itemid = $this->add_visible_item((int)$scorecard->id);
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->get_cm_for_scorecard($scorecard);

        $result = scorecard_handle_submission(
            $scorecard,
            $cm,
            (int)$user->id,
            [$itemid => 7]
        );
        $this->assertSame('submitted', $result['status']);

        $completion = new \completion_info($course);
        $cmdata = $completion->get_data($cm, false, (int)$user->id);
        $this->assertEquals(
            COMPLETION_INCOMPLETE,
            (int)$cmdata->completionstate,
            'Activity should not be auto-completed when completionsubmit=0.'
        );
    }
}
