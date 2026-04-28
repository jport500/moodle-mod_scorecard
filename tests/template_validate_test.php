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
 * Tests for mod_scorecard's JSON template validation helper (Phase 6.3).
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
 * Round-trip invariant; envelope structure; layered field validation;
 * permissive-on-unknown warnings; cross-version warning.
 */
#[CoversNothing]
final class template_validate_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture (mirrors template_export_test convention).
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
     * Extract the set of error codes from a validate result for assertion-friendly comparison.
     *
     * @param array $result `['errors' => array, 'warnings' => array]` from
     *                      scorecard_template_validate.
     * @return array Two arrays of code strings: `[errors_codes, warnings_codes]`.
     */
    private function extract_codes(array $result): array {
        return [
            array_map(fn($e) => $e['code'], $result['errors']),
            array_map(fn($w) => $w['code'], $result['warnings']),
        ];
    }

    /**
     * Round-trip invariant — Test 1 per Q22 disposition (a). Couples 6.3
     * test to 6.1 helper deliberately so any future regression in either
     * surface fails this test. Real fixture round-trips clean.
     */
    public function test_round_trip_invariant_against_export(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        scorecard_add_item((object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'How are you?',
            'promptformat' => (int)FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'visible' => 1,
        ]);
        scorecard_add_band((object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'OK',
            'message' => '',
            'messageformat' => (int)FORMAT_HTML,
        ]);

        $template = scorecard_template_export((int)$scorecard->id);

        // Round-trip through json_encode/json_decode to mirror the production
        // path: export → JSON file → upload → json_decode → validate.
        $json = json_encode(
            $template,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $decoded = json_decode($json, true);

        $result = scorecard_template_validate($decoded);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Well-formed minimal template (envelope + empty items + empty bands)
     * validates clean. Operator-bootstrap-style template (no items + bands
     * authored yet) is a legitimate import target.
     */
    public function test_minimal_well_formed_template(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $template = $this->build_minimal_template((string)($info->release ?? ''));

        $result = scorecard_template_validate($template);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Envelope-level errors — missing each top-level field surfaces a
     * dedicated `envelope_missingfield` error keyed at the missing path.
     */
    public function test_envelope_missing_fields(): void {
        $this->resetAfterTest();

        foreach (['schema_version', 'plugin', 'exported_at', 'scorecard', 'items', 'bands'] as $field) {
            $template = $this->build_minimal_template('v0.7.0');
            unset($template[$field]);

            $result = scorecard_template_validate($template);
            [$errorcodes, $warningcodes] = $this->extract_codes($result);

            $this->assertContains(
                'envelope_missingfield',
                $errorcodes,
                "Missing field '{$field}' should surface envelope_missingfield"
            );
            $this->assertSame([], $warningcodes);
        }
    }

    /**
     * Schema version errors: wrong version, non-string type.
     */
    public function test_schema_version_errors(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        // Wrong recognised version — fatal.
        $template = $this->build_minimal_template($release);
        $template['schema_version'] = '2.0';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('schemaversion_unsupported', $errorcodes);

        // Future-1.x version — also fatal at v0.7.0 (single-version-only).
        $template = $this->build_minimal_template($release);
        $template['schema_version'] = '1.1';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('schemaversion_unsupported', $errorcodes);

        // Wrong type (int instead of string).
        $template = $this->build_minimal_template($release);
        $template['schema_version'] = 1;
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('envelope_wrongtype', $errorcodes);
    }

    /**
     * Plugin object errors: cross-plugin name fatal; version mismatch warning.
     */
    public function test_plugin_object_errors(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        // Cross-plugin name — fatal (Q19).
        $template = $this->build_minimal_template($release);
        $template['plugin']['name'] = 'mod_other';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('plugin_wrongname', $errorcodes);

        // Version mismatch — warning, not fatal (Q20).
        $template = $this->build_minimal_template('v0.5.0');
        [$errorcodes, $warningcodes] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertNotContains('plugin_wrongname', $errorcodes);
        $this->assertContains('plugin_versionmismatch', $warningcodes);
    }

    /**
     * Scorecard settings errors: missing field, wrong type, range invalid,
     * displaystyle non-radio.
     */
    public function test_scorecard_settings_errors(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        // Missing required field.
        $template = $this->build_minimal_template($release);
        unset($template['scorecard']['scalemin']);
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('scorecard_missingfield', $errorcodes);

        // Wrong type (string where int expected).
        $template = $this->build_minimal_template($release);
        $template['scorecard']['scalemin'] = 'one';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('scorecard_wrongtype', $errorcodes);

        // Range invalid: scalemin >= scalemax.
        $template = $this->build_minimal_template($release);
        $template['scorecard']['scalemin'] = 10;
        $template['scorecard']['scalemax'] = 5;
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('scorecard_rangeinvalid', $errorcodes);

        // Display style non-radio (locked at v1.0 per SPEC §4.1).
        $template = $this->build_minimal_template($release);
        $template['scorecard']['displaystyle'] = 'slider';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('scorecard_displaystylelocked', $errorcodes);
    }

    /**
     * Items array errors: missing field, wrong type, flag value out of range.
     */
    public function test_items_array_errors(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        $baseitem = [
            'prompt' => 'Test prompt',
            'promptformat' => (int)FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'required' => 1,
            'visible' => 1,
            'sortorder' => 1,
        ];

        // Missing field.
        $template = $this->build_minimal_template($release);
        $missingitem = $baseitem;
        unset($missingitem['prompt']);
        $template['items'] = [$missingitem];
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('item_missingfield', $errorcodes);

        // Wrong type.
        $template = $this->build_minimal_template($release);
        $wrongtypeitem = $baseitem;
        $wrongtypeitem['sortorder'] = 'one';
        $template['items'] = [$wrongtypeitem];
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('item_wrongtype', $errorcodes);

        // Flag value out of range.
        $template = $this->build_minimal_template($release);
        $badflagitem = $baseitem;
        $badflagitem['visible'] = 2;
        $template['items'] = [$badflagitem];
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('item_flagvalue', $errorcodes);
    }

    /**
     * Bands array errors: missing field, wrong type, range invalid.
     */
    public function test_bands_array_errors(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        $baseband = [
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Test',
            'message' => '',
            'messageformat' => (int)FORMAT_HTML,
            'sortorder' => 1,
        ];

        // Missing field.
        $template = $this->build_minimal_template($release);
        $missingband = $baseband;
        unset($missingband['label']);
        $template['bands'] = [$missingband];
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('band_missingfield', $errorcodes);

        // Wrong type.
        $template = $this->build_minimal_template($release);
        $wrongtypeband = $baseband;
        $wrongtypeband['minscore'] = 'zero';
        $template['bands'] = [$wrongtypeband];
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('band_wrongtype', $errorcodes);

        // Range invalid: minscore > maxscore.
        $template = $this->build_minimal_template($release);
        $rangeband = $baseband;
        $rangeband['minscore'] = 10;
        $rangeband['maxscore'] = 5;
        $template['bands'] = [$rangeband];
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('band_rangeinvalid', $errorcodes);
    }

    /**
     * Permissive-on-unknown disposition (Q17 (b)) — extra fields produce
     * warnings, not errors. Forward-compat with future schema iterations.
     */
    public function test_unknown_fields_produce_warnings(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        $template = $this->build_minimal_template($release);
        $template['extra_envelope_field'] = 'value';
        $template['scorecard']['extra_scorecard_field'] = 42;
        $template['items'] = [[
            'prompt' => 'p',
            'promptformat' => (int)FORMAT_HTML,
            'lowlabel' => '',
            'highlabel' => '',
            'required' => 1,
            'visible' => 1,
            'sortorder' => 1,
            'extra_item_field' => 'v',
        ]];

        $result = scorecard_template_validate($template);
        [$errorcodes, $warningcodes] = $this->extract_codes($result);

        $this->assertSame([], $errorcodes);
        $this->assertCount(3, $warningcodes);
        $this->assertSame(['unknownfield', 'unknownfield', 'unknownfield'], $warningcodes);

        // Path information lets the consumer (sub-step 6.5 import UI) tell
        // the operator which fields will be ignored.
        $paths = array_map(fn($w) => $w['path'], $result['warnings']);
        $this->assertContains('extra_envelope_field', $paths);
        $this->assertContains('scorecard.extra_scorecard_field', $paths);
        $this->assertContains('items.0.extra_item_field', $paths);
    }

    /**
     * Malformed exported_at: wrong format produces a fatal.
     */
    public function test_exported_at_format_invalid(): void {
        $this->resetAfterTest();
        $info = \core_plugin_manager::instance()->get_plugin_info('mod_scorecard');
        $release = (string)($info->release ?? '');

        $template = $this->build_minimal_template($release);
        // Permissive form (no Z suffix; sub-second precision) rejected.
        $template['exported_at'] = '2026-04-28T22:52:31.123';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('exportedat_invalid', $errorcodes);

        // Garbage rejected.
        $template['exported_at'] = 'yesterday';
        [$errorcodes, ] = $this->extract_codes(scorecard_template_validate($template));
        $this->assertContains('exportedat_invalid', $errorcodes);
    }

    /**
     * Build a minimal well-formed template with the producer fingerprint
     * pinned to the supplied release.
     *
     * @param string $release Producer release for plugin.version envelope field.
     * @return array Minimal template envelope.
     */
    private function build_minimal_template(string $release): array {
        return [
            'schema_version' => '1.0',
            'plugin' => [
                'name' => 'mod_scorecard',
                'version' => $release,
            ],
            'exported_at' => '2026-04-28T22:52:31Z',
            'scorecard' => [
                'name' => 'Test',
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
        ];
    }
}
