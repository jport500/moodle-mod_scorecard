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
 * Backup steps for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete scorecard structure for backup.
 *
 * Phase 1.4 shipped settings-only backup (just the {scorecard} row).
 * Phase 5b.3 adds nested backup elements for scorecard_items and
 * scorecard_bands — both part of the activity's authoring structure
 * (always included, regardless of user data setting). Phase 5b.4
 * follows with attempts + responses (userdata-gated). Phase 5b.5
 * handles the restore side.
 *
 * Per SPEC §9.4: items and bands are backed up "including soft-deleted
 * ones, to preserve historical reporting" — the SQL sources here
 * deliberately include rows where `deleted = 1`, so the deleted flag
 * round-trips and historical attempts can resolve their original
 * prompt/label text post-restore.
 */
class backup_scorecard_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the activity backup structure.
     *
     * @return backup_nested_element The root activity element.
     */
    protected function define_structure() {
        // Root element: the scorecard row. Excludes id (handled by backup
        // framework) and course (replaced with new courseid by restore).
        // The completionsubmit field was added in Phase 5a.4 (savepoint
        // 2026042701) but missed from this declaration in v0.5.0; Phase
        // 5b.3 restores it as the root-element completeness fix.
        $scorecard = new backup_nested_element('scorecard', ['id'], [
            'name', 'intro', 'introformat',
            'scalemin', 'scalemax', 'displaystyle',
            'lowlabel', 'highlabel',
            'allowretakes', 'showresult', 'showpercentage', 'showitemsummary',
            'fallbackmessage', 'fallbackmessageformat',
            'gradeenabled', 'grade',
            'completionsubmit',
            'timecreated', 'timemodified',
        ]);

        // Items container + row element. Excludes id (attribute) and
        // scorecardid (FK to parent, set by source).
        $items = new backup_nested_element('items');
        $item = new backup_nested_element('item', ['id'], [
            'prompt', 'promptformat',
            'lowlabel', 'highlabel',
            'required', 'visible', 'deleted',
            'sortorder',
            'timecreated', 'timemodified',
        ]);

        // Bands container + row element. Same exclusions as items.
        $bands = new backup_nested_element('bands');
        $band = new backup_nested_element('band', ['id'], [
            'minscore', 'maxscore',
            'label', 'message', 'messageformat',
            'sortorder', 'deleted',
            'timecreated', 'timemodified',
        ]);

        // Build the tree.
        $scorecard->add_child($items);
        $items->add_child($item);
        $scorecard->add_child($bands);
        $bands->add_child($band);

        // Sources. Items + bands ordered by sortorder (with id as the
        // stable secondary key) so backup XML row order matches authoring
        // order. No `deleted = 0` filter — soft-deleted rows must round-
        // trip per SPEC §9.4.
        $scorecard->set_source_table('scorecard', ['id' => backup::VAR_ACTIVITYID]);
        $item->set_source_table(
            'scorecard_items',
            ['scorecardid' => backup::VAR_PARENTID],
            'sortorder ASC, id ASC'
        );
        $band->set_source_table(
            'scorecard_bands',
            ['scorecardid' => backup::VAR_PARENTID],
            'sortorder ASC, id ASC'
        );

        // No file annotations: scorecard_items.prompt and scorecard_bands.message
        // use format_text directly without file_save_draft_area_files (Phase 2
        // authoring code path), so no file areas are registered against these
        // tables. fallbackmessage on the scorecard row likewise has maxfiles=0
        // per Phase 1's mod_form.php editor config. Phase 5b.3 adds none.
        //
        // No id annotations: items + bands don't reference other tables at this
        // layer. attempts → responses → items cross-reference via
        // scorecard_responses.itemid lands in 5b.4.

        return $this->prepare_activity_structure($scorecard);
    }
}
