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
 * Privacy provider tests for mod_scorecard.
 *
 * Phase 5b.1: tests the metadata declaration plus the three export-contract
 * methods (get_contexts_for_userid, get_users_in_context, export_user_data).
 * The three delete-contract methods land in 5b.2 with their own test class.
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Privacy provider tests for mod_scorecard.
 */
#[CoversNothing]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Build a course + scorecard + items + attempts fixture for a user.
     *
     * Returns the scorecard activity record, course module, course, items
     * keyed by id, and the attempt record. Used as the standard fixture
     * across the export tests.
     *
     * @param int|null $userid Optional user id; created if null.
     * @return array{scorecard: \stdClass, cm: \stdClass, course: \stdClass, items: array, attempt: \stdClass, user: \stdClass}
     */
    private function make_fixture(?int $userid = null): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $scorecard = $this->getDataGenerator()->create_module('scorecard', (object)[
            'course' => $course->id,
            'name' => 'Test scorecard',
            'gradeenabled' => 0,
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $cm = \get_coursemodule_from_instance('scorecard', $scorecard->id, $course->id, false, MUST_EXIST);

        $user = $userid ? \core_user::get_user($userid) : $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $now = time();
        $itemid1 = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'First prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $itemid2 = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Second prompt',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $attemptid = (int)$DB->insert_record('scorecard_attempts', (object)[
            'scorecardid' => $scorecard->id,
            'userid' => $user->id,
            'attemptnumber' => 1,
            'totalscore' => 14,
            'maxscore' => 20,
            'percentage' => 70.0,
            'bandid' => null,
            'bandlabelsnapshot' => 'Strong',
            'bandmessagesnapshot' => 'Solid result',
            'bandmessageformatsnapshot' => FORMAT_HTML,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('scorecard_responses', (object)[
            'attemptid' => $attemptid,
            'itemid' => $itemid1,
            'responsevalue' => 7,
            'timecreated' => $now,
        ]);
        $DB->insert_record('scorecard_responses', (object)[
            'attemptid' => $attemptid,
            'itemid' => $itemid2,
            'responsevalue' => 7,
            'timecreated' => $now,
        ]);

        return [
            'scorecard' => $scorecard,
            'cm' => $cm,
            'course' => $course,
            'items' => [$itemid1, $itemid2],
            'attempt' => $DB->get_record('scorecard_attempts', ['id' => $attemptid], '*', MUST_EXIST),
            'user' => $user,
        ];
    }

    /**
     * get_metadata declares both user-data tables with required fields.
     *
     * SPEC §9.5: scorecard_responses metadata MUST include itemid as a
     * graph-traversal link. Phase 5b.1 fixed the missing itemid
     * declaration; this test pins that the declaration stays correct.
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_scorecard');
        provider::get_metadata($collection);
        $items = $collection->get_collection();

        $tables = [];
        foreach ($items as $item) {
            $tables[$item->get_name()] = $item->get_privacy_fields();
        }

        $this->assertArrayHasKey('scorecard_attempts', $tables);
        $this->assertArrayHasKey('scorecard_responses', $tables);

        $this->assertArrayHasKey('userid', $tables['scorecard_attempts']);
        $this->assertArrayHasKey('totalscore', $tables['scorecard_attempts']);
        $this->assertArrayHasKey('bandlabelsnapshot', $tables['scorecard_attempts']);

        $this->assertArrayHasKey('attemptid', $tables['scorecard_responses']);
        $this->assertArrayHasKey(
            'itemid',
            $tables['scorecard_responses'],
            'SPEC §9.5: itemid is required as a graph-traversal link in scorecard_responses metadata.'
        );
        $this->assertArrayHasKey('responsevalue', $tables['scorecard_responses']);
    }

    /**
     * get_contexts_for_userid returns the cm context for a user with attempts.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $fixture = $this->make_fixture();

        $contextlist = provider::get_contexts_for_userid((int)$fixture['user']->id);
        $contextids = $contextlist->get_contextids();

        $this->assertCount(1, $contextids);
        $this->assertEquals(
            \context_module::instance($fixture['cm']->id)->id,
            (int)$contextids[0]
        );
    }

    /**
     * get_contexts_for_userid returns empty for users with no attempts.
     */
    public function test_get_contexts_for_userid_empty_for_unrelated_user(): void {
        $this->resetAfterTest();
        $fixture = $this->make_fixture();
        $unrelated = $this->getDataGenerator()->create_user();

        $contextlist = provider::get_contexts_for_userid((int)$unrelated->id);

        $this->assertEmpty($contextlist->get_contextids());
    }

    /**
     * get_users_in_context lists users with attempts in the given cm context.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $fixture = $this->make_fixture();

        // Add a second user with an attempt on the same scorecard.
        $user2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user2->id, $fixture['course']->id);
        global $DB;
        $now = time();
        $DB->insert_record('scorecard_attempts', (object)[
            'scorecardid' => $fixture['scorecard']->id,
            'userid' => $user2->id,
            'attemptnumber' => 1,
            'totalscore' => 12,
            'maxscore' => 20,
            'percentage' => 60.0,
            'bandid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $context = \context_module::instance($fixture['cm']->id);
        $userlist = new userlist($context, 'mod_scorecard');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertCount(2, $userids);
        $this->assertContains((int)$fixture['user']->id, $userids);
        $this->assertContains((int)$user2->id, $userids);
    }

    /**
     * export_user_data writes per-attempt data to the writer with the right shape.
     *
     * Verifies SPEC §9.5 contract: snapshotted band fields, current item
     * prompt text, response values, all under per-attempt subcontext.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $fixture = $this->make_fixture();
        $context = \context_module::instance($fixture['cm']->id);

        $contextlist = new approved_contextlist(
            $fixture['user'],
            'mod_scorecard',
            [$context->id]
        );
        provider::export_user_data($contextlist);

        $attemptslabel = get_string('privacy:export:attempts', 'mod_scorecard');
        $subcontext = [$attemptslabel, 'Attempt 1'];
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data($subcontext);
        $this->assertNotEmpty($data);
        $this->assertEquals(1, (int)$data->attemptnumber);
        $this->assertEquals(14, (int)$data->totalscore);
        $this->assertEquals(20, (int)$data->maxscore);
        $this->assertEquals('Strong', $data->bandlabelsnapshot);
        $this->assertCount(2, $data->responses);
        $this->assertEquals(7, (int)$data->responses[0]->response);
        $this->assertStringContainsString('First prompt', $data->responses[0]->prompt);
    }

    /**
     * Soft-deleted-item handling: responses to soft-deleted items still
     * appear in export with the [deleted] prefix on the prompt text.
     *
     * This pins the LEFT JOIN behavior in export_user_data — INNER JOIN
     * would silently filter these responses out, which would be a
     * privacy violation (user's response data omitted from their own
     * export). The test exercises the full path: item exists with
     * deleted=1, response references it, export must include the
     * response with the [deleted] prefix on the prompt.
     */
    public function test_export_includes_responses_to_soft_deleted_items(): void {
        $this->resetAfterTest();
        global $DB;
        $fixture = $this->make_fixture();

        // Soft-delete the first item AFTER attempts exist (lifecycle-gate path).
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $fixture['items'][0]]);

        $context = \context_module::instance($fixture['cm']->id);
        $contextlist = new approved_contextlist(
            $fixture['user'],
            'mod_scorecard',
            [$context->id]
        );
        provider::export_user_data($contextlist);

        $attemptslabel = get_string('privacy:export:attempts', 'mod_scorecard');
        $subcontext = [$attemptslabel, 'Attempt 1'];
        $data = writer::with_context($context)->get_data($subcontext);

        $this->assertNotEmpty($data);
        $this->assertCount(2, $data->responses);

        $deletedprefix = get_string('report:detail:deletedprefix', 'mod_scorecard');
        $this->assertStringStartsWith(
            $deletedprefix,
            $data->responses[0]->prompt,
            'Response to soft-deleted item should be prefixed with [deleted].'
        );
        $this->assertEquals(7, (int)$data->responses[0]->response);

        // Second response (item not deleted) should NOT have the prefix.
        $this->assertStringNotContainsString(
            $deletedprefix,
            $data->responses[1]->prompt
        );
    }

    /**
     * Build a course + scorecard + items + band + per-user attempts fixture.
     *
     * 5b.2 helper extraction: 3 delete tests need multi-user fixtures.
     * make_fixture (used by 5b.1's export tests) creates a 1-user shape;
     * this helper creates a 1-band-1-scorecard fixture with one attempt
     * per user and 2 responses per attempt. Reusable by 5b.4 backup +
     * 5b.5 restore work where multi-user fixtures will be needed again.
     *
     * @param array $userids User ids to create attempts for.
     * @return array{scorecard: \stdClass, cm: \stdClass, course: \stdClass, items: array, bandid: int, attempts: array}
     */
    private function make_fixture_with_users(array $userids): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $scorecard = $this->getDataGenerator()->create_module('scorecard', (object)[
            'course' => $course->id,
            'name' => 'Multi-user fixture scorecard',
            'gradeenabled' => 0,
            'scalemin' => 1,
            'scalemax' => 10,
        ]);
        $cm = \get_coursemodule_from_instance('scorecard', $scorecard->id, $course->id, false, MUST_EXIST);

        $now = time();
        $itemid1 = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Item 1',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $itemid2 = (int)$DB->insert_record('scorecard_items', (object)[
            'scorecardid' => $scorecard->id,
            'prompt' => 'Item 2',
            'promptformat' => FORMAT_HTML,
            'visible' => 1,
            'deleted' => 0,
            'sortorder' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $bandid = (int)$DB->insert_record('scorecard_bands', (object)[
            'scorecardid' => $scorecard->id,
            'minscore' => 0,
            'maxscore' => 20,
            'label' => 'Test band',
            'message' => '',
            'messageformat' => FORMAT_HTML,
            'sortorder' => 1,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $attempts = [];
        foreach ($userids as $userid) {
            $attemptid = (int)$DB->insert_record('scorecard_attempts', (object)[
                'scorecardid' => $scorecard->id,
                'userid' => $userid,
                'attemptnumber' => 1,
                'totalscore' => 14,
                'maxscore' => 20,
                'percentage' => 70.0,
                'bandid' => $bandid,
                'bandlabelsnapshot' => 'Test band',
                'bandmessagesnapshot' => '',
                'bandmessageformatsnapshot' => FORMAT_HTML,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $DB->insert_record('scorecard_responses', (object)[
                'attemptid' => $attemptid,
                'itemid' => $itemid1,
                'responsevalue' => 7,
                'timecreated' => $now,
            ]);
            $DB->insert_record('scorecard_responses', (object)[
                'attemptid' => $attemptid,
                'itemid' => $itemid2,
                'responsevalue' => 7,
                'timecreated' => $now,
            ]);
            $attempts[$userid] = $attemptid;
        }

        return [
            'scorecard' => $scorecard,
            'cm' => $cm,
            'course' => $course,
            'items' => [$itemid1, $itemid2],
            'bandid' => $bandid,
            'attempts' => $attempts,
        ];
    }

    /**
     * delete_data_for_all_users_in_context removes all attempts + responses
     * for every user in the given cm context, while preserving the
     * scorecard, its items, and its bands (SPEC §9.5).
     */
    public function test_delete_data_for_all_users_in_context(): void {
        $this->resetAfterTest();
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $fixture = $this->make_fixture_with_users([(int)$user1->id, (int)$user2->id]);
        $context = \context_module::instance($fixture['cm']->id);

        // Pre-state: 2 attempts, 4 responses.
        $this->assertEquals(2, $DB->count_records(
            'scorecard_attempts',
            ['scorecardid' => $fixture['scorecard']->id]
        ));

        provider::delete_data_for_all_users_in_context($context);

        // All attempts + responses gone for this scorecard.
        $this->assertEquals(0, $DB->count_records(
            'scorecard_attempts',
            ['scorecardid' => $fixture['scorecard']->id]
        ));
        $this->assertFalse($DB->record_exists(
            'scorecard_responses',
            ['attemptid' => $fixture['attempts'][(int)$user1->id]]
        ));
        $this->assertFalse($DB->record_exists(
            'scorecard_responses',
            ['attemptid' => $fixture['attempts'][(int)$user2->id]]
        ));

        // Scorecard structure preserved.
        $this->assertTrue($DB->record_exists(
            'scorecard',
            ['id' => $fixture['scorecard']->id]
        ));
        $this->assertEquals(2, $DB->count_records(
            'scorecard_items',
            ['scorecardid' => $fixture['scorecard']->id]
        ));
        $this->assertTrue($DB->record_exists(
            'scorecard_bands',
            ['id' => $fixture['bandid']]
        ));
    }

    /**
     * delete_data_for_user removes only the target user's attempts +
     * responses, leaving other users' data and the scorecard structure
     * untouched (SPEC §9.5).
     */
    public function test_delete_data_for_user(): void {
        $this->resetAfterTest();
        global $DB;

        $target = $this->getDataGenerator()->create_user();
        $unrelated = $this->getDataGenerator()->create_user();
        $fixture = $this->make_fixture_with_users([(int)$target->id, (int)$unrelated->id]);
        $context = \context_module::instance($fixture['cm']->id);

        $contextlist = new approved_contextlist(
            $target,
            'mod_scorecard',
            [$context->id]
        );
        provider::delete_data_for_user($contextlist);

        // Target's data gone.
        $this->assertFalse($DB->record_exists('scorecard_attempts', [
            'scorecardid' => $fixture['scorecard']->id,
            'userid' => $target->id,
        ]));
        $this->assertFalse($DB->record_exists(
            'scorecard_responses',
            ['attemptid' => $fixture['attempts'][(int)$target->id]]
        ));

        // Unrelated user's data preserved.
        $this->assertTrue($DB->record_exists('scorecard_attempts', [
            'scorecardid' => $fixture['scorecard']->id,
            'userid' => $unrelated->id,
        ]));
        $this->assertEquals(2, $DB->count_records(
            'scorecard_responses',
            ['attemptid' => $fixture['attempts'][(int)$unrelated->id]]
        ));

        // Scorecard structure preserved.
        $this->assertTrue($DB->record_exists(
            'scorecard',
            ['id' => $fixture['scorecard']->id]
        ));
        $this->assertEquals(2, $DB->count_records(
            'scorecard_items',
            ['scorecardid' => $fixture['scorecard']->id]
        ));
        $this->assertTrue($DB->record_exists(
            'scorecard_bands',
            ['id' => $fixture['bandid']]
        ));
    }

    /**
     * delete_data_for_users removes attempts + responses for the listed
     * users only, preserving non-listed users' data and the scorecard
     * structure (SPEC §9.5).
     */
    public function test_delete_data_for_users(): void {
        $this->resetAfterTest();
        global $DB;

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        $fixture = $this->make_fixture_with_users([
            (int)$user1->id,
            (int)$user2->id,
            (int)$user3->id,
        ]);
        $context = \context_module::instance($fixture['cm']->id);

        $userlist = new approved_userlist(
            $context,
            'mod_scorecard',
            [(int)$user1->id, (int)$user2->id]
        );
        provider::delete_data_for_users($userlist);

        // Approved 2 users' data gone.
        $this->assertFalse($DB->record_exists('scorecard_attempts', [
            'scorecardid' => $fixture['scorecard']->id,
            'userid' => $user1->id,
        ]));
        $this->assertFalse($DB->record_exists('scorecard_attempts', [
            'scorecardid' => $fixture['scorecard']->id,
            'userid' => $user2->id,
        ]));

        // Third user's data preserved.
        $this->assertTrue($DB->record_exists('scorecard_attempts', [
            'scorecardid' => $fixture['scorecard']->id,
            'userid' => $user3->id,
        ]));
        $this->assertEquals(2, $DB->count_records(
            'scorecard_responses',
            ['attemptid' => $fixture['attempts'][(int)$user3->id]]
        ));

        // Scorecard structure preserved.
        $this->assertTrue($DB->record_exists(
            'scorecard',
            ['id' => $fixture['scorecard']->id]
        ));
        $this->assertEquals(2, $DB->count_records(
            'scorecard_items',
            ['scorecardid' => $fixture['scorecard']->id]
        ));
        $this->assertTrue($DB->record_exists(
            'scorecard_bands',
            ['id' => $fixture['bandid']]
        ));
    }
}
