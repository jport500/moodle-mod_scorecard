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
 * Phase 5b.3: nested backup elements for scorecard_items and
 * scorecard_bands. SPEC §9.4 directives pinned: soft-deleted items and
 * bands must round-trip in backup XML to preserve historical reporting.
 * Plus a regression-guard test for the completionsubmit root-element
 * field (the v0.5.0 completeness fix bundled into 5b.3).
 *
 * Phase 5b.4: userdata-gated tests for scorecard_attempts and
 * scorecard_responses. SPEC §9.4 user-data gating pinned: attempts +
 * responses included only when the userinfo backup setting is on.
 * SPEC §11.2 snapshot-fidelity directive pinned: bandid,
 * bandlabelsnapshot, bandmessagesnapshot, bandmessageformatsnapshot,
 * totalscore, maxscore, percentage all round-trip verbatim.
 *
 * Phase 5b.5: shared fixture + backup-pipeline helpers extracted to
 * backup_testcase.php; this class now extends backup_testcase. Restore-
 * side round-trip tests live in restore_test.php (also extends
 * backup_testcase).
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\backup;

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/backup_testcase.php');

/**
 * Backup structure tests for mod_scorecard.
 */
#[CoversNothing]
final class backup_test extends backup_testcase {
    /**
     * Run the backup pipeline on the given cm and return the parsed
     * scorecard.xml as a SimpleXMLElement.
     *
     * Thin wrapper: backup_to_mbz (inherited) + extract + parse.
     *
     * @param int $cmid Course module id.
     * @param bool $userinfo Whether to include user data in the backup.
     * @return \SimpleXMLElement Parsed scorecard.xml.
     */
    private function backup_and_parse_scorecard_xml(int $cmid, bool $userinfo = true): \SimpleXMLElement {
        global $CFG;

        $file = $this->backup_to_mbz($cmid, $userinfo);
        $packer = \get_file_packer('application/vnd.moodle.backup');
        $extractpath = $CFG->dataroot . '/temp/backup/test-scorecard-backup-' . $cmid . '-' . ($userinfo ? 'on' : 'off');
        $file->extract_to_pathname($packer, $extractpath);

        // Activity backup XML lives at activities/scorecard_<cmid>/scorecard.xml.
        $xmlpath = "$extractpath/activities/scorecard_$cmid/scorecard.xml";
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

    /**
     * SPEC §9.4 user-data gating directive (positive case): attempts
     * round-trip in backup XML when the userinfo setting is on.
     */
    public function test_backup_includes_attempts_when_userinfo_enabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id, true);

        $attempts = $xml->xpath('//attempts/attempt');
        $this->assertCount(1, $attempts, 'Userinfo-on backup should include the attempt.');
        $this->assertEquals((string)$user->id, (string)$attempts[0]->userid);
    }

    /**
     * SPEC §9.4 user-data gating directive (negative case): attempts
     * are absent from backup XML when the userinfo setting is off.
     */
    public function test_backup_excludes_attempts_when_userinfo_disabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id, false);

        $attempts = $xml->xpath('//attempts/attempt');
        $this->assertCount(0, $attempts, 'Userinfo-off backup should exclude attempts (SPEC §9.4 user-data gating).');
    }

    /**
     * Responses round-trip in backup XML when userinfo is on. Fixture
     * creates one response per item per attempt (visible + soft-deleted
     * items both, so a response to a soft-deleted item is exercised).
     */
    public function test_backup_includes_responses_when_userinfo_enabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id, true);

        $responses = $xml->xpath('//attempts/attempt/responses/response');
        $this->assertCount(2, $responses, 'Userinfo-on backup should include all responses for the attempt.');

        $itemids = [];
        foreach ($responses as $r) {
            $itemids[] = (int)$r->itemid;
        }
        $this->assertContains((int)$fixture['items']['visible'], $itemids);
        $this->assertContains(
            (int)$fixture['items']['deleted'],
            $itemids,
            'Response to soft-deleted item should round-trip.'
        );
    }

    /**
     * SPEC §11.2 directive pin: snapshot fields round-trip verbatim.
     *
     * Single comprehensive assertion covering all four snapshot fields
     * (bandid, bandlabelsnapshot, bandmessagesnapshot,
     * bandmessageformatsnapshot) plus the totalscore/maxscore/percentage
     * trio. If any field is dropped from the row element list in a
     * future edit, this test fails immediately. The fixture deliberately
     * stores label/message values that differ from the visible band's
     * current values, so a re-render regression would fail visibly.
     */
    public function test_backup_preserves_all_snapshot_fields(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $xml = $this->backup_and_parse_scorecard_xml((int)$fixture['cm']->id, true);

        $attempts = $xml->xpath('//attempts/attempt');
        $this->assertCount(1, $attempts);
        $attempt = $attempts[0];

        // Snapshot fields (all four).
        $this->assertEquals(
            (string)$fixture['bands']['visible'],
            (string)$attempt->bandid,
            'bandid round-trips verbatim.'
        );
        $this->assertEquals(
            'Frozen snapshot label 0',
            (string)$attempt->bandlabelsnapshot,
            'bandlabelsnapshot round-trips verbatim (distinct from current band label).'
        );
        $this->assertEquals(
            '<p>Frozen snapshot message 0</p>',
            (string)$attempt->bandmessagesnapshot,
            'bandmessagesnapshot round-trips verbatim (distinct from current band message).'
        );
        $this->assertEquals(
            (string)FORMAT_HTML,
            (string)$attempt->bandmessageformatsnapshot,
            'bandmessageformatsnapshot round-trips verbatim.'
        );

        // Score trio.
        $this->assertEquals('8', (string)$attempt->totalscore, 'totalscore round-trips verbatim.');
        $this->assertEquals('10', (string)$attempt->maxscore, 'maxscore round-trips verbatim.');
        $this->assertEquals(80.00, (float)$attempt->percentage, 'percentage round-trips verbatim.');
    }
}
