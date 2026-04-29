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
 * Tests for mod_scorecard's template import UI orchestration helper (Phase 6.5b).
 *
 * Targets scorecard_template_import_handle (cmid-based populate-existing
 * orchestration helper) and the template_import_form class. Endpoint-level
 * POST simulation is out of scope per Phase 6.5 Q34 disposition.
 *
 * Sub-step 6.5b architectural reversal: tests exercise the populate-existing
 * flow against an empty scorecard (operator workflow surfaced at walkthrough)
 * rather than the create-new-from-template flow that 6.5 originally shipped.
 *
 * Browser walkthrough remains the canonical operator-facing UI gate.
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
require_once($CFG->dirroot . '/mod/scorecard/classes/form/template_import_form.php');

/**
 * Form construction; helper happy path; helper validation errors;
 * helper warnings + confirmation gate; helper json decode failure;
 * empty-state precondition; round-trip via empty-create-then-populate.
 */
#[CoversNothing]
final class template_import_ui_test extends \advanced_testcase {
    /**
     * Build a minimal well-formed template envelope as raw JSON.
     *
     * @param string $release Producer release for plugin.version.
     * @param array $overrides Field overrides merged onto defaults.
     * @return string JSON-encoded template.
     */
    private function build_minimal_json(string $release, array $overrides = []): string {
        $template = array_merge([
            'schema_version' => '1.0',
            'plugin' => ['name' => 'mod_scorecard', 'version' => $release],
            'exported_at' => '2026-04-28T22:52:31Z',
            'scorecard' => [
                'name' => 'UI Imported Test',
                'intro' => '',
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
        ], $overrides);
        return json_encode($template);
    }

    /**
     * Current plugin release (read from version.php via core_plugin_manager).
     *
     * @return string Release string.
     */
    private function current_release(): string {
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        return (string)($info->release ?? '');
    }

    /**
     * Create an empty scorecard activity in a fresh course; return its cmid.
     *
     * Uses the testing module generator so a real cm record exists, allowing
     * scorecard_template_import_handle's get_coursemodule_from_id to resolve.
     *
     * @return int Course module id.
     */
    private function create_empty_scorecard_cmid(): int {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('scorecard', [
            'course' => $course->id,
            'name' => 'Empty fixture',
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $cm = get_coursemodule_from_instance('scorecard', $module->id, $course->id, false, MUST_EXIST);
        return (int)$cm->id;
    }

    /**
     * Build a JSON template carrying one item + one band so populate has
     * something to insert and tests can assert content lands.
     *
     * @param string $release Producer release.
     * @return string JSON-encoded template with content.
     */
    private function build_json_with_content(string $release): string {
        return $this->build_minimal_json($release, [
            'items' => [[
                'prompt' => 'Test prompt',
                'promptformat' => (int)FORMAT_HTML,
                'lowlabel' => '',
                'highlabel' => '',
                'required' => 1,
                'visible' => 1,
                'sortorder' => 1,
            ]],
            'bands' => [[
                'minscore' => 0,
                'maxscore' => 10,
                'label' => 'Test band',
                'message' => '',
                'messageformat' => (int)FORMAT_HTML,
                'sortorder' => 1,
            ]],
        ]);
    }

    /**
     * Form construction (cmid-based; no section selector). Instantiates
     * without errors; expected elements present (filepicker + submit).
     */
    public function test_form_construction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $url = new \moodle_url('/mod/scorecard/template_import.php', ['cmid' => 1]);
        $form = new \mod_scorecard\form\template_import_form($url);

        ob_start();
        $form->display();
        $html = ob_get_clean();

        $this->assertStringContainsString('templatefile', $html);
        // Section selector must NOT be present in the 6.5b form.
        $this->assertStringNotContainsString('sectionnum', $html);
    }

    /**
     * Form validation rejects empty submission (no file).
     */
    public function test_form_validation_rejects_empty_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $url = new \moodle_url('/mod/scorecard/template_import.php', ['cmid' => 1]);
        $form = new \mod_scorecard\form\template_import_form($url);

        $reflection = new \ReflectionClass($form);
        $method = $reflection->getMethod('validation');
        $method->setAccessible(true);
        $errors = $method->invoke($form, ['templatefile' => 0], []);

        $this->assertArrayHasKey('templatefile', $errors);
    }

    /**
     * Helper happy path: clean template against empty scorecard → state=success,
     * cmid echoed back, items + bands populated.
     */
    public function test_handle_success_clean_template(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->create_empty_scorecard_cmid();
        $rawjson = $this->build_json_with_content($this->current_release());

        $result = scorecard_template_import_handle($cmid, $rawjson, false);

        $this->assertSame('success', $result['state']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame($cmid, $result['cmid']);

        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $items = $DB->get_records('scorecard_items', ['scorecardid' => $cm->instance]);
        $bands = $DB->get_records('scorecard_bands', ['scorecardid' => $cm->instance]);
        $this->assertCount(1, $items);
        $this->assertCount(1, $bands);
    }

    /**
     * Helper validation errors: corrupted template → state=errors, no items
     * inserted (empty scorecard remains empty).
     */
    public function test_handle_validation_errors_block_populate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->create_empty_scorecard_cmid();

        $template = json_decode($this->build_json_with_content($this->current_release()), true);
        unset($template['scorecard']['scalemax']);
        $rawjson = json_encode($template);

        $result = scorecard_template_import_handle($cmid, $rawjson, false);

        $this->assertSame('errors', $result['state']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNull($result['cmid']);

        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $this->assertSame(0, $DB->count_records('scorecard_items', ['scorecardid' => $cm->instance]));
        $this->assertSame(0, $DB->count_records('scorecard_bands', ['scorecardid' => $cm->instance]));
    }

    /**
     * Helper warnings + unconfirmed: surfaces warnings, blocks populate.
     */
    public function test_handle_warnings_unconfirmed_blocks_populate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->create_empty_scorecard_cmid();
        // Plugin version mismatch → warning, not error.
        $rawjson = $this->build_json_with_content('v0.0.1-mismatch');

        $result = scorecard_template_import_handle($cmid, $rawjson, false);

        $this->assertSame('warnings', $result['state']);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertNull($result['cmid']);

        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $this->assertSame(0, $DB->count_records('scorecard_items', ['scorecardid' => $cm->instance]));
    }

    /**
     * Helper warnings + confirmed: proceeds with populate.
     */
    public function test_handle_warnings_confirmed_proceeds(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->create_empty_scorecard_cmid();
        $rawjson = $this->build_json_with_content('v0.0.1-mismatch');

        $result = scorecard_template_import_handle($cmid, $rawjson, true);

        $this->assertSame('success', $result['state']);
        $this->assertSame([], $result['errors']);
        $this->assertNotEmpty($result['warnings'], 'Warnings still surface in success state');
        $this->assertSame($cmid, $result['cmid']);

        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);
        $this->assertSame(1, $DB->count_records('scorecard_items', ['scorecardid' => $cm->instance]));
    }

    /**
     * Helper json_decode failure: malformed input → state=errors with the
     * dedicated jsondecode_error code; scorecard remains empty.
     */
    public function test_handle_invalid_json_surfaces_decode_error(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->create_empty_scorecard_cmid();

        $result = scorecard_template_import_handle($cmid, 'this is not JSON', false);

        $this->assertSame('errors', $result['state']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('jsondecode_error', $result['errors'][0]['code']);
        $this->assertNull($result['cmid']);
    }

    /**
     * Empty-state precondition: import into a scorecard that already has
     * items returns state=errors with the notempty code; existing items are
     * untouched.
     */
    public function test_handle_rejects_non_empty_scorecard(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $cmid = $this->create_empty_scorecard_cmid();
        $cm = get_coursemodule_from_id('scorecard', $cmid, 0, false, MUST_EXIST);

        // Pre-populate with one existing item.
        scorecard_add_item((object)[
            'scorecardid' => $cm->instance,
            'prompt' => 'Pre-existing',
            'promptformat' => (int)FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);

        $rawjson = $this->build_json_with_content($this->current_release());
        $result = scorecard_template_import_handle($cmid, $rawjson, false);

        $this->assertSame('errors', $result['state']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('notempty', $result['errors'][0]['code']);

        // Pre-existing item still present, no new items added.
        $this->assertSame(1, $DB->count_records('scorecard_items', ['scorecardid' => $cm->instance]));
    }

    /**
     * Round-trip via empty-create-then-populate. Mirrors operator workflow:
     * standard "Add an activity" creates an empty scorecard; populate via
     * 6.5b helper; resulting state matches source template.
     */
    public function test_round_trip_empty_create_then_populate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Source scorecard with content (provides a realistic template).
        $sourcecourse = $this->getDataGenerator()->create_course();
        $sourcemodule = $this->getDataGenerator()->create_module('scorecard', [
            'course' => $sourcecourse->id,
            'name' => 'Source',
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        scorecard_add_item((object)[
            'scorecardid' => $sourcemodule->id,
            'prompt' => 'Source prompt',
            'promptformat' => (int)FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $sourcemodule->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Source band',
            'message' => '',
            'messageformat' => (int)FORMAT_HTML,
        ]);

        // Export → JSON round-trip → populate into freshly-created empty scorecard.
        $template = scorecard_template_export((int)$sourcemodule->id);
        $rawjson = json_encode($template);

        $destcmid = $this->create_empty_scorecard_cmid();
        $result = scorecard_template_import_handle($destcmid, $rawjson, false);

        $this->assertSame('success', $result['state']);

        $destcm = get_coursemodule_from_id('scorecard', $destcmid, 0, false, MUST_EXIST);
        $destitems = array_values($DB->get_records(
            'scorecard_items',
            ['scorecardid' => $destcm->instance],
            'sortorder ASC'
        ));
        $destbands = array_values($DB->get_records(
            'scorecard_bands',
            ['scorecardid' => $destcm->instance],
            'sortorder ASC'
        ));

        $this->assertCount(1, $destitems);
        $this->assertSame('Source prompt', $destitems[0]->prompt);
        $this->assertCount(1, $destbands);
        $this->assertSame('Source band', $destbands[0]->label);
    }
}
