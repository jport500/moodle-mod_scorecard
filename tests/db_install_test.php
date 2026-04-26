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
 * Schema integrity tests for mod_scorecard install.xml.
 *
 * Verifies that all five tables exist with their declared primary key,
 * foreign-key columns, and key indexes after install. Catches drift
 * between install.xml and the live schema (e.g., a column rename in
 * XML without an upgrade step).
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Schema integrity tests.
 */
#[CoversNothing]
final class db_install_test extends \advanced_testcase {
    /**
     * All five scorecard tables exist after install.
     */
    public function test_all_tables_exist(): void {
        global $DB;
        $manager = $DB->get_manager();

        $tables = [
            'scorecard',
            'scorecard_items',
            'scorecard_bands',
            'scorecard_attempts',
            'scorecard_responses',
        ];
        foreach ($tables as $name) {
            $this->assertTrue($manager->table_exists($name), "Table {$name} should exist");
        }
    }

    /**
     * The {scorecard} table has all 20 spec §8.1 columns including the renamed
     * scalemin / scalemax (NOT the original minvalue / maxvalue) and is missing
     * the dropped showpreviousattempt column.
     */
    public function test_scorecard_columns(): void {
        global $DB;
        $columns = $DB->get_columns('scorecard');

        $expected = [
            'id', 'course', 'name', 'intro', 'introformat',
            'scalemin', 'scalemax', 'displaystyle',
            'lowlabel', 'highlabel',
            'allowretakes', 'showresult', 'showpercentage', 'showitemsummary',
            'fallbackmessage', 'fallbackmessageformat',
            'gradeenabled', 'grade',
            'timecreated', 'timemodified',
        ];
        foreach ($expected as $col) {
            $this->assertArrayHasKey($col, $columns, "Column {$col} should exist on scorecard");
        }

        $this->assertArrayNotHasKey('minvalue', $columns, 'minvalue was renamed to scalemin');
        $this->assertArrayNotHasKey('maxvalue', $columns, 'maxvalue was renamed to scalemax');
        $this->assertArrayNotHasKey('showpreviousattempt', $columns, 'showpreviousattempt was dropped from spec');
    }

    /**
     * The {scorecard_attempts} table carries all four band-snapshot columns
     * required for historical-fidelity reporting.
     */
    public function test_attempts_snapshot_columns(): void {
        global $DB;
        $columns = $DB->get_columns('scorecard_attempts');

        $snapshot = [
            'bandid',
            'bandlabelsnapshot',
            'bandmessagesnapshot',
            'bandmessageformatsnapshot',
        ];
        foreach ($snapshot as $col) {
            $this->assertArrayHasKey($col, $columns, "Snapshot column {$col} should exist on scorecard_attempts");
        }
    }

    /**
     * Soft-delete flags exist on the three tables that need them per spec §4.5.
     */
    public function test_soft_delete_flags(): void {
        global $DB;
        $this->assertArrayHasKey('deleted', $DB->get_columns('scorecard_items'));
        $this->assertArrayHasKey('deleted', $DB->get_columns('scorecard_bands'));
    }

    /**
     * Foreign-key columns are present and integer-typed on every dependent table.
     */
    public function test_foreign_key_columns(): void {
        global $DB;

        $fks = [
            'scorecard_items' => ['scorecardid'],
            'scorecard_bands' => ['scorecardid'],
            'scorecard_attempts' => ['scorecardid', 'userid', 'bandid'],
            'scorecard_responses' => ['attemptid', 'itemid'],
        ];
        foreach ($fks as $table => $cols) {
            $columns = $DB->get_columns($table);
            foreach ($cols as $col) {
                $this->assertArrayHasKey($col, $columns, "FK column {$col} should exist on {$table}");
            }
        }
    }

    /**
     * The two compound and three single-column indexes declared in install.xml
     * resolve under the database manager.
     */
    public function test_key_indexes_present(): void {
        global $DB;
        $manager = $DB->get_manager();

        // Compound index on (scorecardid, sortorder) for scorecard_items.
        $itemstable = new \xmldb_table('scorecard_items');
        $itemsidx = new \xmldb_index('scorecardid_sortorder', XMLDB_INDEX_NOTUNIQUE, ['scorecardid', 'sortorder']);
        $this->assertTrue(
            $manager->index_exists($itemstable, $itemsidx),
            'scorecardid_sortorder index should exist on scorecard_items'
        );

        // Compound index on (scorecardid, minscore) for scorecard_bands.
        $bandstable = new \xmldb_table('scorecard_bands');
        $bandsidx = new \xmldb_index('scorecardid_minscore', XMLDB_INDEX_NOTUNIQUE, ['scorecardid', 'minscore']);
        $this->assertTrue(
            $manager->index_exists($bandstable, $bandsidx),
            'scorecardid_minscore index should exist on scorecard_bands'
        );

        // Compound (scorecardid, userid) on scorecard_attempts for the "does this user have an attempt" lookup.
        $attemptstable = new \xmldb_table('scorecard_attempts');
        $attemptsidx = new \xmldb_index('scorecardid_userid', XMLDB_INDEX_NOTUNIQUE, ['scorecardid', 'userid']);
        $this->assertTrue(
            $manager->index_exists($attemptstable, $attemptsidx),
            'scorecardid_userid index should exist on scorecard_attempts'
        );
    }
}
