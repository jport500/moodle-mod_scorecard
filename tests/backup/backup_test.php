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
 * Backup tests for mod_scorecard.
 *
 * Phase 5b.3: tests the nested backup elements for scorecard_items and
 * scorecard_bands. SPEC §9.4 directives pinned: soft-deleted items and
 * bands must round-trip in backup XML to preserve historical reporting.
 * Plus a regression-guard test for the completionsubmit root-element
 * field (the v0.5.0 completeness fix bundled into 5b.3).
 *
 * Phase 5b.4 will extend with userdata-gated tests for attempts +
 * responses; Phase 5b.5 will add restore-side round-trip tests.
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\backup;

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Backup structure tests for mod_scorecard.
 */
#[CoversNothing]
final class backup_test extends \advanced_testcase {
    /**
     * Build a course + scorecard + items + bands fixture.
     *
     * Returns the course module record (cm) so callers can pass cm->id
     * to backup_controller. The fixture deliberately includes one
     * soft-deleted item and one soft-deleted band so the SPEC §9.4
     * "soft-deleted included" directive can be empirically pinned.
     *
     * @return array{cm: \stdClass, scorecard: \stdClass, items: array, bands: array}
     */
    private function make_backup_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $scorecard = $this->getDataGenerator()->create_module('scorecard', (object)[
            'course' => $course->id,
            'name' => 'Backup test scorecard',
            'gradeenabled' => 1,
            'grade' => 20,
            'completionsubmit' => 1,
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $cm = \get_coursemodule_from_instance('scorecard', $scorecard->id, $course->id, false, MUST_EXIST);

        $now = time();

        // Visible item.
        $itemvisible = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Visible item prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Soft-deleted item.
        $itemdeleted = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Soft-deleted item prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 1,
            'sortorder' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Visible band.
        $bandvisible = (int)$DB->insert_record('scorecard_bands', (object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 10,
            'label' => 'Visible band',
            'message' => 'Visible band message',
            'messageformat' => FORMAT_HTML,
            'sortorder' => 1,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // Soft-deleted band.
        $banddeleted = (int)$DB->insert_record('scorecard_bands', (object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 11,
            'maxscore' => 20,
            'label' => 'Soft-deleted band',
            'message' => 'Soft-deleted band message',
            'messageformat' => FORMAT_HTML,
            'sortorder' => 2,
            'deleted' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return [
            'cm' => $cm,
            'scorecard' => $scorecard,
            'items' => ['visible' => $itemvisible, 'deleted' => $itemdeleted],
            'bands' => ['visible' => $bandvisible, 'deleted' => $banddeleted],
        ];
    }

    /**
     * Run the backup pipeline on the given cm and return the parsed
     * scorecard.xml as a SimpleXMLElement.
     *
     * Pattern lifted from core's restore_date_testcase: invoke
     * backup_controller, execute plan, extract the resulting .mbz to
     * a temp directory, parse scorecard.xml. Cleanup on resetAfterTest
     * via $CFG->dataroot/temp lifecycle.
     *
     * @param int $cmid Course module id.
     * @return \SimpleXMLElement Parsed scorecard.xml.
     */
    private function backup_and_parse_scorecard_xml(int $cmid): \SimpleXMLElement {
        global $CFG, $USER;

        // The backup_controller requires a valid user; PHPUnit does not
        // authenticate one by default. setAdminUser populates $USER.
        $this->setAdminUser();

        // Avoid file-logger contention during the backup.
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $packer = \get_file_packer('application/vnd.moodle.backup');
        $extractpath = $CFG->dataroot . '/temp/backup/test-scorecard-backup-' . $cmid;
        $file->extract_to_pathname($packer, $extractpath);
        $bc->destroy();

        // Activity backup XML lives at activities/scorecard_<cmid>/scorecard.xml.
        $activitydir = "$extractpath/activities/scorecard_$cmid";
        $xmlpath = "$activitydir/scorecard.xml";
        $this->assertFileExists($xmlpath, 'scorecard.xml should exist in backup output.');

        return new \SimpleXMLElement(file_get_contents($xmlpath));
    }

    /**
     * Visible items appear in the backup XML's <items> container.
     */
    public function test_backup_includes_visible_items(): void {
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id);

        $items = $xml->xpath('//items/item');
        $this->assertNotEmpty($items, '<items> container should have <item> children.');

        $prompts = [];
        foreach ($items as $item) {
            $prompts[] = (string)$item->prompt;
        }
        $this->assertContains('Visible item prompt', $prompts);
    }

    /**
     * SPEC §9.4 directive pin: soft-deleted items round-trip in backup XML.
     *
     * The items SQL source must NOT filter on deleted=0 — historical
     * attempts post-restore need the soft-deleted rows to resolve their
     * original prompt text.
     */
    public function test_backup_includes_soft_deleted_items(): void {
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id);

        $items = $xml->xpath('//items/item');
        $this->assertCount(2, $items, 'Both visible and soft-deleted items expected.');

        $promptsbydeleted = [];
        foreach ($items as $item) {
            $deleted = (int)$item->deleted;
            $promptsbydeleted[$deleted][] = (string)$item->prompt;
        }
        $this->assertArrayHasKey(
            1,
            $promptsbydeleted,
            'At least one item with deleted=1 expected (SPEC §9.4 soft-delete preservation).'
        );
        $this->assertContains('Soft-deleted item prompt', $promptsbydeleted[1]);
    }

    /**
     * Visible bands appear in the backup XML's <bands> container.
     */
    public function test_backup_includes_visible_bands(): void {
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id);

        $bands = $xml->xpath('//bands/band');
        $this->assertNotEmpty($bands, '<bands> container should have <band> children.');

        $labels = [];
        foreach ($bands as $band) {
            $labels[] = (string)$band->label;
        }
        $this->assertContains('Visible band', $labels);
    }

    /**
     * SPEC §9.4 directive pin: soft-deleted bands round-trip in backup XML.
     *
     * Parallel to the items soft-delete test. Same rationale: the bands
     * SQL source must NOT filter on deleted=0.
     */
    public function test_backup_includes_soft_deleted_bands(): void {
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id);

        $bands = $xml->xpath('//bands/band');
        $this->assertCount(2, $bands, 'Both visible and soft-deleted bands expected.');

        $labelsbydeleted = [];
        foreach ($bands as $band) {
            $deleted = (int)$band->deleted;
            $labelsbydeleted[$deleted][] = (string)$band->label;
        }
        $this->assertArrayHasKey(
            1,
            $labelsbydeleted,
            'At least one band with deleted=1 expected (SPEC §9.4 soft-delete preservation).'
        );
        $this->assertContains('Soft-deleted band', $labelsbydeleted[1]);
    }

    /**
     * Regression guard for the v0.5.0 completionsubmit completeness fix.
     *
     * Phase 5a.4 added the completionsubmit column at savepoint
     * 2026042701, but the backup root element's field list wasn't
     * updated — scorecards backed up under v0.5.0 reverted to the
     * install.xml floor (0) for completionsubmit on restore. Phase 5b.3
     * adds completionsubmit to the root element; this test pins it.
     */
    public function test_backup_root_element_includes_completionsubmit(): void {
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id);

        $scorecards = $xml->xpath('//activity/scorecard');
        $this->assertNotEmpty($scorecards, 'Backup XML should contain a <scorecard> root activity element.');

        $scorecard = $scorecards[0];
        $this->assertTrue(
            isset($scorecard->completionsubmit),
            'Backup root element should serialize the completionsubmit field (Phase 5a.4 column added 2026042701).'
        );
        // Fixture sets completionsubmit=1; verify the value round-trips.
        $this->assertEquals('1', (string)$scorecard->completionsubmit);
    }
}
