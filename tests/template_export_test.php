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
 * Tests for mod_scorecard's JSON template export helper (Phase 6.1).
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
 * Envelope shape, whitelist projection, soft-delete exclusion, HTML round-trip.
 */
#[CoversNothing]
final class template_export_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture with optional setting overrides.
     *
     * @param array $overrides Field overrides merged onto the default settings.
     * @return \stdClass Persisted scorecard row.
     */
    private function create_scorecard(array $overrides = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $data = (object)array_merge([
            'course' => $course->id,
            'name' => 'Fixture',
            'intro' => '<p>Fixture intro.</p>',
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
            'fallbackmessage_editor' => ['text' => '<p>Default.</p>', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ], $overrides);
        $id = scorecard_add_instance($data);
        return $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Envelope-shape sanity: six top-level keys, schema_version "1.0",
     * plugin object, ISO 8601 exported_at, empty items + bands arrays for
     * a scorecard with no items + bands authored.
     */
    public function test_envelope_shape_minimal(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $template = scorecard_template_export((int)$scorecard->id);

        $this->assertSame('1.0', $template['schema_version']);
        $this->assertSame('mod_scorecard', $template['plugin']['name']);
        $this->assertNotEmpty($template['plugin']['version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $template['exported_at']
        );
        $this->assertIsArray($template['scorecard']);
        $this->assertSame([], $template['items']);
        $this->assertSame([], $template['bands']);
    }

    /**
     * Scorecard settings round-trip with native types (int for numeric
     * columns, string for text/char). id, course, timecreated, timemodified
     * excluded per SPEC §9.6 whitelist.
     */
    public function test_scorecard_settings_round_trip(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard([
            'name' => 'Career Fit Score',
            'scalemin' => 1,
            'scalemax' => 10,
            'lowlabel' => 'Strongly disagree',
            'highlabel' => 'Strongly agree',
            'allowretakes' => 1,
            'showpercentage' => 1,
            'gradeenabled' => 1,
            'grade' => 50,
        ]);

        $template = scorecard_template_export((int)$scorecard->id);

        $this->assertSame('Career Fit Score', $template['scorecard']['name']);
        $this->assertSame(1, $template['scorecard']['scalemin']);
        $this->assertSame(10, $template['scorecard']['scalemax']);
        $this->assertSame('Strongly disagree', $template['scorecard']['lowlabel']);
        $this->assertSame('Strongly agree', $template['scorecard']['highlabel']);
        $this->assertSame(1, $template['scorecard']['allowretakes']);
        $this->assertSame(1, $template['scorecard']['showpercentage']);
        $this->assertSame(1, $template['scorecard']['gradeenabled']);
        $this->assertSame(50, $template['scorecard']['grade']);
        $this->assertSame('radio', $template['scorecard']['displaystyle']);

        // Whitelist confirmation: id, course, timestamps excluded.
        $this->assertArrayNotHasKey('id', $template['scorecard']);
        $this->assertArrayNotHasKey('course', $template['scorecard']);
        $this->assertArrayNotHasKey('timecreated', $template['scorecard']);
        $this->assertArrayNotHasKey('timemodified', $template['scorecard']);
    }

    /**
     * Items round-trip in sortorder ASC; per-item anchors and visibility flag
     * preserved; id, scorecardid, timestamps excluded.
     */
    public function test_items_round_trip_in_sortorder(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'First prompt',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Second prompt',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => 'Never',
            'highlabel' => 'Always',
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Third (hidden) prompt',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 0,
        ]);

        $template = scorecard_template_export((int)$scorecard->id);

        $this->assertCount(3, $template['items']);
        $this->assertSame('First prompt', $template['items'][0]['prompt']);
        $this->assertSame('Second prompt', $template['items'][1]['prompt']);
        $this->assertSame('Third (hidden) prompt', $template['items'][2]['prompt']);
        $this->assertSame(1, $template['items'][0]['sortorder']);
        $this->assertSame(2, $template['items'][1]['sortorder']);
        $this->assertSame(3, $template['items'][2]['sortorder']);

        // Hidden item still present in template; visibility flag preserved.
        $this->assertSame(1, $template['items'][0]['visible']);
        $this->assertSame(0, $template['items'][2]['visible']);

        // Per-item anchors round-trip; missing ones are empty string, not null.
        $this->assertSame('Never', $template['items'][1]['lowlabel']);
        $this->assertSame('Always', $template['items'][1]['highlabel']);
        $this->assertSame('', $template['items'][0]['lowlabel']);

        // Whitelist confirmation per row.
        $this->assertArrayNotHasKey('id', $template['items'][0]);
        $this->assertArrayNotHasKey('scorecardid', $template['items'][0]);
        $this->assertArrayNotHasKey('timecreated', $template['items'][0]);
        $this->assertArrayNotHasKey('deleted', $template['items'][0]);
    }

    /**
     * Bands round-trip in sortorder ASC with ranges, label, message, and
     * messageformat preserved verbatim.
     */
    public function test_bands_round_trip_in_sortorder(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 19,
            'label' => 'Concerning',
            'message' => '<p>Needs change.</p>',
            'messageformat' => FORMAT_HTML,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 20,
            'maxscore' => 50,
            'label' => 'Strong',
            'message' => '<p>Doing well.</p>',
            'messageformat' => FORMAT_HTML,
        ]);

        $template = scorecard_template_export((int)$scorecard->id);

        $this->assertCount(2, $template['bands']);
        $this->assertSame('Concerning', $template['bands'][0]['label']);
        $this->assertSame('Strong', $template['bands'][1]['label']);
        $this->assertSame(0, $template['bands'][0]['minscore']);
        $this->assertSame(19, $template['bands'][0]['maxscore']);
        $this->assertSame('<p>Needs change.</p>', $template['bands'][0]['message']);
        $this->assertSame((int)FORMAT_HTML, $template['bands'][0]['messageformat']);

        // Whitelist confirmation per row.
        $this->assertArrayNotHasKey('id', $template['bands'][0]);
        $this->assertArrayNotHasKey('scorecardid', $template['bands'][0]);
        $this->assertArrayNotHasKey('deleted', $template['bands'][0]);
        $this->assertArrayNotHasKey('timecreated', $template['bands'][0]);
    }

    /**
     * Soft-deleted items + bands excluded from the export — the structural
     * distinction from §9.4 backup/restore semantics.
     */
    public function test_soft_deleted_rows_excluded(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $liveitemid = scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Live item',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        $deleteditemid = scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Soft-deleted item',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 5,
            'label' => 'Live band',
            'message' => '',
            'messageformat' => FORMAT_HTML,
        ]);
        $deletedbandid = scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 6,
            'maxscore' => 10,
            'label' => 'Soft-deleted band',
            'message' => '',
            'messageformat' => FORMAT_HTML,
        ]);

        // Soft-delete directly. The lifecycle gate (scorecard_delete_item)
        // requires an attempt to exist; we want to test the export-side
        // exclusion in isolation, so set the deleted flag without going
        // through the delete-helper branch logic.
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $deleteditemid]);
        $DB->set_field('scorecard_bands', 'deleted', 1, ['id' => $deletedbandid]);
        unset($liveitemid);

        $template = scorecard_template_export((int)$scorecard->id);

        $this->assertCount(1, $template['items']);
        $this->assertSame('Live item', $template['items'][0]['prompt']);
        $this->assertCount(1, $template['bands']);
        $this->assertSame('Live band', $template['bands'][0]['label']);
    }

    /**
     * HTML content (Notion-pasted-style) round-trips byte-identically; format
     * constants preserved as int. Confirms the export does not run prompts or
     * messages through format_text or any HTML-mutating helper.
     */
    public function test_html_content_round_trips_verbatim(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        // Real Notion-pasted-style HTML observed in dev DB scorecard id=2.
        $promptbody = '<p><span class="notion-enable-hover" data-token-index="0">'
            . 'How aligned</span> is your work with your values?</p>';
        $bandmessage = '<p>Something needs to change. The good news: you noticed.</p>';

        scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => $promptbody,
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Concerning',
            'message' => $bandmessage,
            'messageformat' => FORMAT_HTML,
        ]);

        $template = scorecard_template_export((int)$scorecard->id);

        $this->assertSame($promptbody, $template['items'][0]['prompt']);
        $this->assertSame((int)FORMAT_HTML, $template['items'][0]['promptformat']);
        $this->assertSame($bandmessage, $template['bands'][0]['message']);
        $this->assertSame((int)FORMAT_HTML, $template['bands'][0]['messageformat']);
    }
}
