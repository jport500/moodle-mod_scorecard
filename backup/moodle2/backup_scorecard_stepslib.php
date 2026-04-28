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
 * Phase 5b.3 added nested backup elements for scorecard_items and
 * scorecard_bands — always included, regardless of user-data setting.
 * Phase 5b.4 adds nested elements for scorecard_attempts and
 * scorecard_responses — gated by the user-data backup setting.
 * Phase 5b.5 handles the restore side.
 *
 * Per SPEC §9.4: items and bands are backed up "including soft-deleted
 * ones, to preserve historical reporting" — the SQL sources here
 * deliberately include rows where `deleted = 1`, so the deleted flag
 * round-trips and historical attempts can resolve their original
 * prompt/label text post-restore.
 *
 * Per SPEC §11.2: attempt-side snapshot fields (bandid,
 * bandlabelsnapshot, bandmessagesnapshot, bandmessageformatsnapshot,
 * plus the totalscore/maxscore/percentage trio) round-trip verbatim —
 * restore preserves the historical view rather than re-rendering from
 * current bands.
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

        // Attempts container + row element. User-data table — sources are
        // bound only when $userinfo is true (see below). The structure
        // declaration is unconditional so the XML schema is consistent
        // regardless of the userinfo setting. Excludes id (attribute) and
        // scorecardid (FK to parent, set by source).
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attemptnumber',
            'totalscore', 'maxscore', 'percentage',
            'bandid',
            'bandlabelsnapshot', 'bandmessagesnapshot', 'bandmessageformatsnapshot',
            'timecreated', 'timemodified',
        ]);

        // Responses container + row element. Nested under attempt — each
        // response belongs to one attempt. Excludes id (attribute) and
        // attemptid (FK to parent, set by source).
        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], [
            'itemid', 'responsevalue', 'timecreated',
        ]);

        // Build the tree.
        $scorecard->add_child($items);
        $items->add_child($item);
        $scorecard->add_child($bands);
        $bands->add_child($band);
        $scorecard->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($responses);
        $responses->add_child($response);

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

        // Attempts + responses are user data — sources bound only when
        // user data is included in the backup (SPEC §9.4 directive). The
        // structure declarations above are unconditional so the XML
        // schema is consistent regardless of the userinfo setting.
        $userinfo = $this->get_setting_value('userinfo');
        if ($userinfo) {
            $attempt->set_source_table(
                'scorecard_attempts',
                ['scorecardid' => backup::VAR_PARENTID],
                'attemptnumber ASC, id ASC'
            );
            $response->set_source_table(
                'scorecard_responses',
                ['attemptid' => backup::VAR_PARENTID]
            );
        }

        // Id annotations. annotate_ids handles cross-references to Moodle
        // CORE tables (user, group, etc.). In-plugin cross-table references
        // (attempt.bandid → scorecard_bands; response.itemid →
        // scorecard_items) are NOT annotated here — backup serializes the
        // raw IDs, and restore_stepslib resolves them via set_mapping /
        // get_mappingid (Phase 5b.5). Convention follows mod_assign.
        $attempt->annotate_ids('user', 'userid');

        // No file annotations: scorecard_items.prompt, scorecard_bands.message,
        // and the response/attempt fields use format_text directly without
        // file_save_draft_area_files (Phase 2 authoring code path), so no
        // file areas are registered against these tables. fallbackmessage on
        // the scorecard row likewise has maxfiles=0 per Phase 1's mod_form.php
        // editor config. Phase 5b.4 adds none.

        return $this->prepare_activity_structure($scorecard);
    }
}
