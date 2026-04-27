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
 * Tests for mod_scorecard learner-facing rendering and helpers.
 *
 * Covers 3.1 deliverables: scorecard_get_visible_items() ordering and
 * filtering, scorecard_user_has_attempt() existence check, and the
 * render_learner_form() / render_learner_no_items() / result-placeholder
 * methods on the plugin renderer (HTML shape, accessibility-relevant
 * markup, hidden-field shape, and lang-string wiring).
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
 * Learner-render tests for 3.1 (form scaffold + helpers).
 */
#[CoversNothing]
final class learner_render_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture and return its row.
     *
     * @param array $overrides Optional field overrides on the scorecard create payload.
     * @return \stdClass scorecard row.
     */
    private function create_scorecard(array $overrides = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $data = (object)array_merge([
            'course' => $course->id,
            'name' => 'Render fixture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'scalemin' => 1,
            'scalemax' => 5,
            'displaystyle' => 'radio',
            'lowlabel' => 'Strongly disagree',
            'highlabel' => 'Strongly agree',
            'allowretakes' => 0,
            'showresult' => 1,
            'showpercentage' => 0,
            'showitemsummary' => 1,
            'fallbackmessage_editor' => ['text' => '', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ], $overrides);
        $id = scorecard_add_instance($data);
        return $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Add an item with sensible defaults; caller passes only the deltas.
     *
     * @param int $scorecardid
     * @param array $overrides
     * @return int Item id.
     */
    private function add_item(int $scorecardid, array $overrides = []): int {
        $data = (object)array_merge([
            'scorecardid' => $scorecardid,
            'prompt' => 'Sample prompt',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ], $overrides);
        return scorecard_add_item($data);
    }

    /**
     * Insert a synthetic attempt to flip user-has-attempt.
     *
     * @param int $scorecardid
     * @param int $userid
     * @return int Attempt id.
     */
    private function insert_attempt(int $scorecardid, int $userid): int {
        global $DB;
        return (int)$DB->insert_record('scorecard_attempts', [
            'scorecardid' => $scorecardid,
            'userid' => $userid,
            'attemptnumber' => 1,
            'totalscore' => 5,
            'maxscore' => 25,
            'percentage' => 20.00,
            'bandid' => null,
            'bandlabelsnapshot' => '',
            'bandmessagesnapshot' => '',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * scorecard_get_visible_items returns visible non-deleted items in sortorder ASC.
     */
    public function test_get_visible_items_filters_and_orders(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $first = $this->add_item((int)$scorecard->id, ['prompt' => 'First']);
        $second = $this->add_item((int)$scorecard->id, ['prompt' => 'Second']);
        $hidden = $this->add_item((int)$scorecard->id, ['prompt' => 'Hidden', 'visible' => 0]);
        $deleted = $this->add_item((int)$scorecard->id, ['prompt' => 'Deleted']);
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $deleted]);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $this->assertCount(2, $items);
        $this->assertArrayHasKey($first, $items);
        $this->assertArrayHasKey($second, $items);
        $this->assertArrayNotHasKey($hidden, $items);
        $this->assertArrayNotHasKey($deleted, $items);

        $sortorders = array_map(fn($i) => (int)$i->sortorder, array_values($items));
        $sorted = $sortorders;
        sort($sorted);
        $this->assertSame($sorted, $sortorders);
    }

    /**
     * scorecard_user_has_attempt returns false / true accurately.
     */
    public function test_user_has_attempt_round_trip(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $this->assertFalse(scorecard_user_has_attempt((int)$scorecard->id, 42));

        $this->insert_attempt((int)$scorecard->id, 42);

        $this->assertTrue(scorecard_user_has_attempt((int)$scorecard->id, 42));
        $this->assertFalse(scorecard_user_has_attempt((int)$scorecard->id, 99));
    }

    /**
     * The form renders one fieldset per item, with prompt as legend.
     */
    public function test_form_renders_fieldset_per_item(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $this->add_item((int)$scorecard->id, ['prompt' => 'Prompt one']);
        $this->add_item((int)$scorecard->id, ['prompt' => 'Prompt two']);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 7);

        $this->assertSame(2, substr_count($html, '<fieldset'));
        $this->assertStringContainsString('Prompt one', $html);
        $this->assertStringContainsString('Prompt two', $html);
        $this->assertStringContainsString('<legend', $html);
    }

    /**
     * Radios cover scalemin..scalemax inclusive with response[itemid] name pattern.
     */
    public function test_form_radio_shape(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(['scalemin' => 1, 'scalemax' => 5]);
        $itemid = $this->add_item((int)$scorecard->id);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 7);

        for ($v = 1; $v <= 5; $v++) {
            $this->assertStringContainsString(
                'value="' . $v . '"',
                $html,
                "Expected radio value $v"
            );
        }
        $this->assertStringContainsString('name="response[' . $itemid . ']"', $html);
    }

    /**
     * Negative-scalemin renders correctly (e.g., -2..+2 risk-style scale).
     */
    public function test_form_negative_scalemin(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(['scalemin' => -2, 'scalemax' => 2]);
        $this->add_item((int)$scorecard->id);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 7);

        foreach ([-2, -1, 0, 1, 2] as $v) {
            $this->assertStringContainsString('value="' . $v . '"', $html);
        }
    }

    /**
     * Hidden cmid + sesskey fields, action="submit.php".
     */
    public function test_form_hidden_fields_and_action(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $this->add_item((int)$scorecard->id);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 42);

        $this->assertStringContainsString('name="cmid"', $html);
        $this->assertStringContainsString('value="42"', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringContainsString('/mod/scorecard/submit.php', $html);
        $this->assertStringContainsString('method="post"', $html);
    }

    /**
     * Anchor spans render with ids; aria-describedby pairs them with the
     * leftmost / rightmost radios for screen-reader association.
     */
    public function test_form_anchor_aria_association(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard([
            'lowlabel' => 'Low end',
            'highlabel' => 'High end',
        ]);
        $itemid = $this->add_item((int)$scorecard->id);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 7);

        $this->assertStringContainsString('id="scorecard-anchor-low-' . $itemid . '"', $html);
        $this->assertStringContainsString('id="scorecard-anchor-high-' . $itemid . '"', $html);
        $this->assertStringContainsString(
            'aria-describedby="scorecard-anchor-low-' . $itemid . '"',
            $html
        );
        $this->assertStringContainsString(
            'aria-describedby="scorecard-anchor-high-' . $itemid . '"',
            $html
        );
        $this->assertStringContainsString('Low end', $html);
        $this->assertStringContainsString('High end', $html);
    }

    /**
     * Item-level low / high labels override the activity-level anchors.
     */
    public function test_form_item_anchor_override(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard([
            'lowlabel' => 'Activity low',
            'highlabel' => 'Activity high',
        ]);
        $this->add_item((int)$scorecard->id, [
            'lowlabel' => 'Item low override',
            'highlabel' => 'Item high override',
        ]);

        $items = scorecard_get_visible_items((int)$scorecard->id);

        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 7);

        $this->assertStringContainsString('Item low override', $html);
        $this->assertStringContainsString('Item high override', $html);
        $this->assertStringNotContainsString('Activity low', $html);
        $this->assertStringNotContainsString('Activity high', $html);
    }

    /**
     * Empty-state renders the expected lang string.
     */
    public function test_no_items_string_renders(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $noitems = $renderer->render_learner_no_items();

        $this->assertStringNotContainsString('[[', $noitems);
        $this->assertStringContainsString(
            get_string('view:noitems_learner', 'mod_scorecard'),
            $noitems
        );
    }

    /**
     * No "Add items" affordance for plain learners (canmanage=false default).
     */
    public function test_no_items_omits_manage_link_when_canmanage_false(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $noitems = $renderer->render_learner_no_items(false, 42);

        $this->assertStringNotContainsString('manage.php', $noitems);
        $this->assertStringNotContainsString(
            get_string('view:manageitemslink', 'mod_scorecard'),
            $noitems
        );
    }

    /**
     * "Add items" affordance surfaces when canmanage=true (admins, managers,
     * editing-teachers who also satisfy :submit). Link points at the items tab.
     */
    public function test_no_items_renders_manage_link_when_canmanage_true(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $noitems = $renderer->render_learner_no_items(true, 42);

        $this->assertStringContainsString('manage.php', $noitems);
        $this->assertStringContainsString('id=42', $noitems);
        $this->assertStringContainsString('tab=items', $noitems);
        $this->assertStringContainsString(
            get_string('view:manageitemslink', 'mod_scorecard'),
            $noitems
        );
    }

    /**
     * Phase 3 lang strings introduced in 3.1 resolve cleanly.
     */
    public function test_phase3_lang_strings_resolve(): void {
        $keys = ['submit:button', 'submit:back', 'result:hidden'];
        foreach ($keys as $key) {
            $value = get_string($key, 'mod_scorecard');
            $this->assertStringNotContainsString('[[', $value, "Lang key $key did not resolve");
        }
    }

    /**
     * Phase 3.5: previous-attempt callout renders headline + score + band label.
     */
    public function test_previous_attempt_callout_renders(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(['allowretakes' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $attemptid = (int)$DB->insert_record('scorecard_attempts', [
            'scorecardid' => (int)$scorecard->id,
            'userid' => (int)$user->id,
            'attemptnumber' => 1,
            'totalscore' => 18,
            'maxscore' => 30,
            'percentage' => 60.00,
            'bandid' => 7,
            'bandlabelsnapshot' => 'Strong',
            'bandmessagesnapshot' => 'Nice work.',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $attempt = $DB->get_record('scorecard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_previous_attempt_callout($attempt);

        $this->assertStringContainsString(
            get_string('retake:previousattempt:headline', 'mod_scorecard'),
            $html
        );
        $this->assertStringContainsString('18 / 30', $html);
        $this->assertStringContainsString('Strong', $html);
        $this->assertStringContainsString(userdate($now), $html);
        $this->assertStringNotContainsString('[[', $html);
    }

    /**
     * Phase 3.5: previous-attempt callout substitutes the noband lang string when bandlabelsnapshot is null.
     */
    public function test_previous_attempt_callout_falls_back_to_noband(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(['allowretakes' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $attemptid = (int)$DB->insert_record('scorecard_attempts', [
            'scorecardid' => (int)$scorecard->id,
            'userid' => (int)$user->id,
            'attemptnumber' => 1,
            'totalscore' => 5,
            'maxscore' => 30,
            'percentage' => 16.67,
            'bandid' => null,
            'bandlabelsnapshot' => null,
            'bandmessagesnapshot' => 'Fallback message.',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $attempt = $DB->get_record('scorecard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_previous_attempt_callout($attempt);

        $this->assertStringContainsString(
            get_string('retake:previousattempt:noband', 'mod_scorecard'),
            $html
        );
        $this->assertStringContainsString('5 / 30', $html);
    }

    /**
     * Phase 3.5: form on retake renders with no checked radios when preselected is empty.
     *
     * Q3 from the kickoff: a retake renders a blank form, not a pre-populated one.
     * View.php passes the default empty array; this test enforces the contract structurally.
     */
    public function test_render_learner_form_does_not_preselect_on_retake(): void {
        global $PAGE;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();
        $this->add_item((int)$scorecard->id, ['prompt' => 'P1']);
        $this->add_item((int)$scorecard->id, ['prompt' => 'P2']);
        $items = scorecard_get_visible_items((int)$scorecard->id);
        $PAGE->set_url('/mod/scorecard/view.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('mod_scorecard');

        $html = $renderer->render_learner_form($scorecard, $items, 1);

        $this->assertStringNotContainsString('checked="checked"', $html);
        $this->assertStringNotContainsString("checked='checked'", $html);
    }
}
