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
 * Tests for mod_scorecard's JSON template import instantiation helper (Phase 6.4).
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
 * Round-trip invariant; settings + items + bands round-trip; sortorder
 * gap preservation; empty template; multiple imports same course.
 */
#[CoversNothing]
final class template_import_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture in a course (mirrors export+validate test convention).
     *
     * @param int $courseid Destination course id.
     * @param array $overrides Field overrides merged onto default settings.
     * @return \stdClass Persisted scorecard row.
     */
    private function create_scorecard(int $courseid, array $overrides = []): \stdClass {
        global $DB;
        $data = (object)array_merge([
            'course' => $courseid,
            'name' => 'Source Fixture',
            'intro' => '<p>Source intro.</p>',
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
     * Round-trip invariant — Test 1 per Q22 / Q28 disposition. Couples 6.4
     * to 6.1 (export) and 6.3 (validate). Source scorecard exported, decoded,
     * validated, imported into fresh course; resulting scorecard's authoring
     * structure matches source.
     */
    public function test_round_trip_invariant_export_validate_import(): void {
        global $DB;
        $this->resetAfterTest();

        // Source course + scorecard with items + bands.
        $sourcecourse = $this->getDataGenerator()->create_course();
        $source = $this->create_scorecard($sourcecourse->id, ['name' => 'Source']);

        scorecard_add_item((object)[
            'scorecardid' => $source->id,
            'prompt' => 'Source prompt 1',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => $source->id,
            'prompt' => 'Source prompt 2',
            'promptformat' => FORMAT_HTML,
            'lowlabel' => 'Never',
            'highlabel' => 'Always',
            'visible' => 1,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $source->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Low band',
            'message' => '<p>Low message.</p>',
            'messageformat' => FORMAT_HTML,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $source->id,
            'minscore' => 11,
            'maxscore' => 20,
            'label' => 'High band',
            'message' => '<p>High message.</p>',
            'messageformat' => FORMAT_HTML,
        ]);

        // Export → JSON → decode → validate → import. Mirrors production path.
        $template = scorecard_template_export((int)$source->id);
        $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($json, true);
        $validateresult = scorecard_template_validate($decoded);
        $this->assertSame([], $validateresult['errors']);

        // Destination course distinct from source.
        $destcourse = $this->getDataGenerator()->create_course();
        $cmid = scorecard_template_import($decoded, $destcourse->id);

        $this->assertGreaterThan(0, $cmid);
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $this->assertSame((int)$destcourse->id, (int)$cm->course);

        // Imported scorecard's authoring structure matches source.
        $imported = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Source', $imported->name);
        $this->assertSame((int)$source->scalemin, (int)$imported->scalemin);
        $this->assertSame((int)$source->scalemax, (int)$imported->scalemax);

        $importeditems = $DB->get_records('scorecard_items', ['scorecardid' => $imported->id], 'sortorder ASC');
        $this->assertCount(2, $importeditems);
        $importedbands = $DB->get_records('scorecard_bands', ['scorecardid' => $imported->id], 'sortorder ASC');
        $this->assertCount(2, $importedbands);
    }

    /**
     * Minimal instantiation: empty template (no items, no bands) creates a
     * scorecard activity in the destination course with 0 items + 0 bands.
     * Operator-bootstrap-style import is supported.
     */
    public function test_minimal_instantiation(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->build_minimal_template();

        $cmid = scorecard_template_import($template, $course->id);

        $this->assertGreaterThan(0, $cmid);
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Imported Test', $scorecard->name);
        $this->assertSame(0, $DB->count_records('scorecard_items', ['scorecardid' => $scorecard->id]));
        $this->assertSame(0, $DB->count_records('scorecard_bands', ['scorecardid' => $scorecard->id]));
    }

    /**
     * Settings round-trip — every whitelisted §8.1 field lands in DB with
     * correct value + native type. Asymmetric defaults (e.g., completionsubmit)
     * round-trip exactly, not via mod_form's default-overrides.
     */
    public function test_scorecard_settings_round_trip(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->build_minimal_template();
        $template['scorecard']['name'] = 'Career Fit Score';
        $template['scorecard']['scalemin'] = 1;
        $template['scorecard']['scalemax'] = 10;
        $template['scorecard']['lowlabel'] = 'Strongly disagree';
        $template['scorecard']['highlabel'] = 'Strongly agree';
        $template['scorecard']['allowretakes'] = 1;
        $template['scorecard']['showpercentage'] = 1;
        $template['scorecard']['gradeenabled'] = 1;
        $template['scorecard']['grade'] = 50;
        $template['scorecard']['completionsubmit'] = 0;

        $cmid = scorecard_template_import($template, $course->id);
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

        $this->assertSame('Career Fit Score', $scorecard->name);
        $this->assertSame(1, (int)$scorecard->scalemin);
        $this->assertSame(10, (int)$scorecard->scalemax);
        $this->assertSame('Strongly disagree', $scorecard->lowlabel);
        $this->assertSame('Strongly agree', $scorecard->highlabel);
        $this->assertSame(1, (int)$scorecard->allowretakes);
        $this->assertSame(1, (int)$scorecard->showpercentage);
        $this->assertSame(1, (int)$scorecard->gradeenabled);
        $this->assertSame(50, (int)$scorecard->grade);
        $this->assertSame(0, (int)$scorecard->completionsubmit);
    }

    /**
     * Items round-trip with sortorder preserved (gaps included per Q24).
     * Soft-delete flag is 0 on imported items — export excluded soft-deleted
     * rows; import creates fresh.
     */
    public function test_items_round_trip(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->build_minimal_template();
        $template['items'] = [
            [
                'prompt' => 'Item A',
                'promptformat' => (int)FORMAT_HTML,
                'lowlabel' => '',
                'highlabel' => '',
                'required' => 1,
                'visible' => 1,
                'sortorder' => 1,
            ],
            [
                'prompt' => 'Item B (anchored)',
                'promptformat' => (int)FORMAT_HTML,
                'lowlabel' => 'Never',
                'highlabel' => 'Always',
                'required' => 1,
                'visible' => 1,
                'sortorder' => 2,
            ],
            [
                'prompt' => 'Item C (hidden)',
                'promptformat' => (int)FORMAT_HTML,
                'lowlabel' => '',
                'highlabel' => '',
                'required' => 1,
                'visible' => 0,
                'sortorder' => 3,
            ],
        ];

        $cmid = scorecard_template_import($template, $course->id);
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);

        $items = array_values($DB->get_records(
            'scorecard_items',
            ['scorecardid' => $cm->instance],
            'sortorder ASC'
        ));
        $this->assertCount(3, $items);
        $this->assertSame('Item A', $items[0]->prompt);
        $this->assertSame('Item B (anchored)', $items[1]->prompt);
        $this->assertSame('Item C (hidden)', $items[2]->prompt);
        $this->assertSame('Never', $items[1]->lowlabel);
        $this->assertSame('Always', $items[1]->highlabel);
        $this->assertSame(1, (int)$items[0]->visible);
        $this->assertSame(0, (int)$items[2]->visible);

        // Soft-delete flag fresh-zeroed: export excluded soft-deleted; import
        // creates fresh non-deleted rows regardless of source state.
        foreach ($items as $item) {
            $this->assertSame(0, (int)$item->deleted);
        }
    }

    /**
     * Bands round-trip with HTML messages preserved verbatim.
     */
    public function test_bands_round_trip_with_html(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->build_minimal_template();
        $bandmessage = '<p>Real Notion-style message<!-- notionvc: abc-123 --></p>';
        $template['bands'] = [
            [
                'minscore' => 0,
                'maxscore' => 10,
                'label' => 'Concerning',
                'message' => $bandmessage,
                'messageformat' => (int)FORMAT_HTML,
                'sortorder' => 1,
            ],
            [
                'minscore' => 11,
                'maxscore' => 20,
                'label' => 'Strong',
                'message' => '',
                'messageformat' => (int)FORMAT_HTML,
                'sortorder' => 2,
            ],
        ];

        $cmid = scorecard_template_import($template, $course->id);
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);

        $bands = array_values($DB->get_records(
            'scorecard_bands',
            ['scorecardid' => $cm->instance],
            'sortorder ASC'
        ));
        $this->assertCount(2, $bands);
        $this->assertSame('Concerning', $bands[0]->label);
        $this->assertSame(0, (int)$bands[0]->minscore);
        $this->assertSame(10, (int)$bands[0]->maxscore);
        $this->assertSame($bandmessage, $bands[0]->message);
        $this->assertSame((int)FORMAT_HTML, (int)$bands[0]->messageformat);
        $this->assertSame('Strong', $bands[1]->label);
    }

    /**
     * Sortorder gap preservation per Q24. Source template carries items at
     * sortorder 1, 2, 3, 5 (gap at 4 from a hypothetical soft-deleted item
     * the export side excluded). Imported items preserve those exact values.
     */
    public function test_sortorder_gap_preservation(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->build_minimal_template();
        $template['items'] = [
            ['prompt' => 'A', 'promptformat' => (int)FORMAT_HTML, 'lowlabel' => '',
                'highlabel' => '', 'required' => 1, 'visible' => 1, 'sortorder' => 1],
            ['prompt' => 'B', 'promptformat' => (int)FORMAT_HTML, 'lowlabel' => '',
                'highlabel' => '', 'required' => 1, 'visible' => 1, 'sortorder' => 2],
            ['prompt' => 'C', 'promptformat' => (int)FORMAT_HTML, 'lowlabel' => '',
                'highlabel' => '', 'required' => 1, 'visible' => 1, 'sortorder' => 3],
            // Gap at 4: source's soft-deleted item excluded by export.
            ['prompt' => 'E', 'promptformat' => (int)FORMAT_HTML, 'lowlabel' => '',
                'highlabel' => '', 'required' => 1, 'visible' => 1, 'sortorder' => 5],
        ];

        $cmid = scorecard_template_import($template, $course->id);
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);

        $items = array_values($DB->get_records(
            'scorecard_items',
            ['scorecardid' => $cm->instance],
            'sortorder ASC'
        ));
        $this->assertCount(4, $items);
        $sortorders = array_map(fn($i) => (int)$i->sortorder, $items);
        $this->assertSame([1, 2, 3, 5], $sortorders);
    }

    /**
     * Multiple imports into the same course produce distinct scorecard
     * activities with independent items + bands + cmids.
     */
    public function test_multiple_imports_into_same_course(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->build_minimal_template();
        $template['items'] = [
            ['prompt' => 'P', 'promptformat' => (int)FORMAT_HTML, 'lowlabel' => '',
                'highlabel' => '', 'required' => 1, 'visible' => 1, 'sortorder' => 1],
        ];

        $cmid1 = scorecard_template_import($template, $course->id);
        $cmid2 = scorecard_template_import($template, $course->id);

        $this->assertNotSame($cmid1, $cmid2);
        $cm1 = get_coursemodule_from_id('scorecard', $cmid1, 0, false, MUST_EXIST);
        $cm2 = get_coursemodule_from_id('scorecard', $cmid2, 0, false, MUST_EXIST);
        $this->assertNotSame((int)$cm1->instance, (int)$cm2->instance);

        // Each scorecard owns its own items.
        $this->assertSame(1, $DB->count_records(
            'scorecard_items',
            ['scorecardid' => $cm1->instance]
        ));
        $this->assertSame(1, $DB->count_records(
            'scorecard_items',
            ['scorecardid' => $cm2->instance]
        ));
    }

    /**
     * Course not found surfaces moodle_exception with the documented identifier.
     */
    public function test_courseid_not_found_throws(): void {
        $this->resetAfterTest();
        $template = $this->build_minimal_template();
        $bogusid = 999999;

        $this->expectException(\moodle_exception::class);
        scorecard_template_import($template, $bogusid);
    }

    /**
     * Build a minimal well-formed template envelope.
     *
     * @return array Validated template envelope.
     */
    private function build_minimal_template(): array {
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        return [
            'schema_version' => '1.0',
            'plugin' => [
                'name' => 'mod_scorecard',
                'version' => (string)($info->release ?? ''),
            ],
            'exported_at' => '2026-04-28T22:52:31Z',
            'scorecard' => [
                'name' => 'Imported Test',
                'intro' => '<p>Imported intro.</p>',
                'introformat' => (int)FORMAT_HTML,
                'scalemin' => 1,
                'scalemax' => 10,
                'displaystyle' => 'radio',
                'lowlabel' => '',
                'highlabel' => '',
                'allowretakes' => 0,
                'showresult' => 1,
                'showpercentage' => 0,
                'showitemsummary' => 1,
                'fallbackmessage' => '',
                'fallbackmessageformat' => (int)FORMAT_HTML,
                'gradeenabled' => 0,
                'grade' => 0,
                'completionsubmit' => 0,
            ],
            'items' => [],
            'bands' => [],
        ];
    }
}
