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
 * Restore tests for mod_scorecard.
 *
 * Phase 5b.5: backup → restore round-trip tests for the full nested
 * structure (items + bands always; attempts + responses gated). Pins:
 * SPEC §9.4 user-data gating directive on restore (positive + negative
 * cases); SPEC §11.2 snapshot-fidelity directive; SPEC §9.4 id-mapping
 * directive (response.itemid + attempt.bandid round-trip through new
 * ids assigned at restore).
 *
 * Shared fixture + backup-pipeline helpers come from backup_testcase.
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
 * Restore round-trip tests for mod_scorecard.
 */
#[CoversNothing]
final class restore_test extends backup_testcase {
    /**
     * Restore the given .mbz into a new course; return the cmid of the
     * restored scorecard activity in that new course.
     *
     * The restore-side userinfo flag drives whether attempts +
     * responses are restored. When backup contained user data and
     * restore is invoked with userinfo=false, attempts are skipped at
     * restore-time per SPEC §9.4 user-data gating directive.
     *
     * @param \stored_file $mbzfile The .mbz file from backup_to_mbz.
     * @param bool $userinfo Whether to include user data in the restore.
     * @return int Course module id of the restored scorecard activity.
     */
    private function restore_into_new_course(\stored_file $mbzfile, bool $userinfo = true): int {
        global $CFG, $USER;

        // The restore_controller, like backup_controller, requires a
        // valid user in the global scope.
        $this->setAdminUser();

        // Extract the .mbz under a unique backupid; restore_controller
        // looks for the extracted contents under $CFG->tempdir/backup/.
        $backupid = 'test-scorecard-restore-' . uniqid();
        $packer = \get_file_packer('application/vnd.moodle.backup');
        $extractpath = $CFG->tempdir . '/backup/' . $backupid;
        $mbzfile->extract_to_pathname($packer, $extractpath);

        // Destination course (target = new course; cleanest separation
        // from any existing course state in the test).
        $newcourse = $this->getDataGenerator()->create_course();

        $rc = new \restore_controller(
            $backupid,
            $newcourse->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        // Toggle the user-data setting at restore time. When the source
        // .mbz has user data but $userinfo is false, restore skips
        // attempts + responses per the SPEC §9.4 gating directive.
        $rc->get_plan()->get_setting('users')->set_value($userinfo);
        $rc->execute_plan();
        $rc->destroy();

        // Find the restored scorecard's course module id.
        $modinfo = \get_fast_modinfo($newcourse->id);
        $cms = $modinfo->get_instances_of('scorecard');
        $this->assertNotEmpty($cms, 'Restored scorecard cm should exist in the new course.');
        $cm = reset($cms);
        return (int)$cm->id;
    }

    /**
     * Items + bands round-trip through backup → restore.
     */
    public function test_restore_creates_items_and_bands(): void {
        global $DB;
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id);
        $newcmid = $this->restore_into_new_course($mbz);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $items = $DB->get_records('scorecard_items', ['scorecardid' => $cm->instance]);
        $bands = $DB->get_records('scorecard_bands', ['scorecardid' => $cm->instance]);

        $this->assertCount(2, $items, 'Both visible and soft-deleted items should restore.');
        $this->assertCount(2, $bands, 'Both visible and soft-deleted bands should restore.');
    }

    /**
     * SPEC §9.4 directive pin: soft-deleted items + bands survive the
     * full backup → restore round-trip with their deleted=1 flag intact.
     */
    public function test_restore_preserves_soft_deleted_items_and_bands(): void {
        global $DB;
        $this->resetAfterTest();
        $fixture = $this->make_backup_fixture();
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id);
        $newcmid = $this->restore_into_new_course($mbz);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $deleteditems = $DB->count_records('scorecard_items', [
            'scorecardid' => $cm->instance, 'deleted' => 1,
        ]);
        $deletedbands = $DB->count_records('scorecard_bands', [
            'scorecardid' => $cm->instance, 'deleted' => 1,
        ]);

        $this->assertEquals(1, $deleteditems, 'Soft-deleted item should round-trip with deleted=1.');
        $this->assertEquals(1, $deletedbands, 'Soft-deleted band should round-trip with deleted=1.');
    }

    /**
     * SPEC §9.4 user-data gating on restore (positive case): backup
     * with userinfo=true, restore with userinfo=true → attempts +
     * responses appear in the restored scorecard.
     */
    public function test_restore_with_userinfo_includes_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id, true);
        $newcmid = $this->restore_into_new_course($mbz, true);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $attempts = $DB->get_records('scorecard_attempts', ['scorecardid' => $cm->instance]);
        $this->assertCount(1, $attempts, 'Restored scorecard should include the attempt.');

        // 2 responses: one per item (visible + soft-deleted).
        $attempt = reset($attempts);
        $responses = $DB->get_records('scorecard_responses', ['attemptid' => $attempt->id]);
        $this->assertCount(2, $responses, 'Restored attempt should include all responses.');
    }

    /**
     * SPEC §9.4 user-data gating on restore (negative case): backup
     * with userinfo=true so the .mbz contains attempts; restore with
     * userinfo=false → attempts skipped at restore-time per the
     * gating directive.
     */
    public function test_restore_without_userinfo_excludes_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id, true);
        $newcmid = $this->restore_into_new_course($mbz, false);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $attempts = $DB->count_records('scorecard_attempts', ['scorecardid' => $cm->instance]);
        $this->assertEquals(0, $attempts, 'Restored scorecard should exclude attempts (restore userinfo=false).');
    }

    /**
     * SPEC §11.2 directive pin: snapshot fields preserved verbatim
     * through the full backup → restore round-trip.
     *
     * Single comprehensive assertion covering all four snapshot fields
     * plus the totalscore/maxscore/percentage trio. The fixture
     * deliberately stores label/message values that differ from the
     * visible band's current values, so a re-render regression at
     * restore time would fail visibly.
     */
    public function test_restore_preserves_all_snapshot_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id, true);
        $newcmid = $this->restore_into_new_course($mbz, true);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $attempts = $DB->get_records('scorecard_attempts', ['scorecardid' => $cm->instance]);
        $this->assertCount(1, $attempts);
        $attempt = reset($attempts);

        // Snapshot fields (all four).
        $this->assertEquals(
            'Frozen snapshot label 0',
            $attempt->bandlabelsnapshot,
            'bandlabelsnapshot round-trips verbatim (distinct from current band label).'
        );
        $this->assertEquals(
            '<p>Frozen snapshot message 0</p>',
            $attempt->bandmessagesnapshot,
            'bandmessagesnapshot round-trips verbatim (distinct from current band message).'
        );
        $this->assertEquals(
            FORMAT_HTML,
            (int)$attempt->bandmessageformatsnapshot,
            'bandmessageformatsnapshot round-trips verbatim.'
        );
        $this->assertNotNull($attempt->bandid, 'bandid should be remapped (not null).');

        // Score trio.
        $this->assertEquals(8, (int)$attempt->totalscore, 'totalscore round-trips verbatim.');
        $this->assertEquals(10, (int)$attempt->maxscore, 'maxscore round-trips verbatim.');
        $this->assertEquals(80.00, (float)$attempt->percentage, 'percentage round-trips verbatim.');
    }

    /**
     * SPEC §9.4 id-mapping directive pin (items): restored
     * response.itemid points to the NEW item id post-restore, not the
     * source-backup itemid. Verifies the set_mapping/get_mappingid
     * pattern in process_scorecard_item + process_scorecard_response.
     */
    public function test_restore_remaps_response_itemids(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id, true);
        $newcmid = $this->restore_into_new_course($mbz, true);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $newitemids = array_map('intval', array_keys(
            $DB->get_records('scorecard_items', ['scorecardid' => $cm->instance], '', 'id')
        ));
        $sourceitemids = array_map('intval', array_values($fixture['items']));

        $this->assertEmpty(
            array_intersect($newitemids, $sourceitemids),
            'Restored items should have new ids, not the source backup ids.'
        );

        // Each restored response should reference one of the NEW itemids.
        $responses = $DB->get_records_sql(
            'SELECT r.* FROM {scorecard_responses} r
             JOIN {scorecard_attempts} a ON a.id = r.attemptid
             WHERE a.scorecardid = ?',
            [$cm->instance]
        );
        $this->assertNotEmpty($responses, 'Restored responses should exist.');
        foreach ($responses as $r) {
            $this->assertContains(
                (int)$r->itemid,
                $newitemids,
                'response.itemid should be remapped to a NEW item id.'
            );
            $this->assertNotContains(
                (int)$r->itemid,
                $sourceitemids,
                'response.itemid should NOT be the source backup itemid.'
            );
        }
    }

    /**
     * SPEC §9.4 id-mapping directive pin (bands): restored
     * attempt.bandid points to the NEW band id post-restore, not the
     * source-backup bandid. Verifies the set_mapping/get_mappingid
     * pattern in process_scorecard_band + process_scorecard_attempt.
     */
    public function test_restore_remaps_attempt_bandids(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fixture = $this->make_backup_fixture([(int)$user->id]);
        $mbz = $this->backup_to_mbz((int)$fixture['cm']->id, true);
        $newcmid = $this->restore_into_new_course($mbz, true);

        $cm = \get_coursemodule_from_id('scorecard', $newcmid, 0, false, MUST_EXIST);

        $newbandids = array_map('intval', array_keys(
            $DB->get_records('scorecard_bands', ['scorecardid' => $cm->instance], '', 'id')
        ));
        $sourcebandids = array_map('intval', array_values($fixture['bands']));

        $this->assertEmpty(
            array_intersect($newbandids, $sourcebandids),
            'Restored bands should have new ids, not the source backup ids.'
        );

        $attempt = $DB->get_record(
            'scorecard_attempts',
            ['scorecardid' => $cm->instance],
            '*',
            MUST_EXIST
        );
        $this->assertContains(
            (int)$attempt->bandid,
            $newbandids,
            'attempt.bandid should be remapped to a NEW band id.'
        );
        $this->assertNotContains(
            (int)$attempt->bandid,
            $sourcebandids,
            'attempt.bandid should NOT be the source backup bandid.'
        );
    }
}
