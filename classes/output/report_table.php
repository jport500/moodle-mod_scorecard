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
 * Paginated report table for mod_scorecard (Phase 4.5).
 *
 * Subclasses core_table\flexible_table rather than table_sql because the
 * existing scorecard_get_attempts() helper already abstracts the query
 * construction (for_identity joins, optional group filter). flexible_table
 * lets us pass the pre-fetched attempt list and slice it for pagination
 * without re-deriving the SQL inside the subclass. table_sql would either
 * couple this subclass to the for_identity construction (losing the helper
 * abstraction reused by 4.4's CSV export) or require overriding query_db()
 * to call the helper anyway -- with no clear win over the flexible_table
 * shape.
 *
 * Pagination semantics:
 *  - Caller fetches all filtered attempts via scorecard_get_attempts() and
 *    passes them to the constructor.
 *  - query_db() slices the attempt list for the current page.
 *  - Per-page response fetch: scorecard_get_attempt_responses() is called
 *    inside query_db() with ONLY the visible-page attemptids -- not the
 *    full set -- so report bandwidth scales with page size, not total
 *    attempt count.
 *
 * Detail block delegation: col_detail() calls
 * $this->renderer->render_attempt_detail($scorecard, $row, $responses).
 * The renderer dependency is unusual relative to the rest of the plugin's
 * "page → renderer → HTML" flow but standard for flexible_table subclasses
 * that emit complex per-cell HTML (mod_assign's grading_table follows the
 * same inversion).
 *
 * Sortable columns: out of scope for MVP. Each column is registered with
 * no_sorting(); the default sort (userid ASC, attemptnumber ASC) carries
 * through from scorecard_get_attempts(). Sortable columns banked as a
 * possible v1.x enhancement.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\output;

use core_table\flexible_table;
use core_user\fields as userfields;
use html_writer;
use moodle_url;
use stdClass;

/**
 * Paginated report table for mod_scorecard.
 */
class report_table extends flexible_table {
    /** @var stdClass Scorecard config row -- read by col_detail (scalemin/scalemax) and col_band. */
    private stdClass $scorecard;

    /** @var array<int, stdClass> Pre-filtered attempts in display order. */
    private array $allattempts;

    /** @var string[] Identity field shortnames per the per-context policy. */
    private array $identityfields;

    /** @var renderer Plugin renderer for the per-row detail block delegation. */
    private renderer $renderer;

    /** @var array<int, array<int, stdClass>> Responses for the visible page only, keyed by attemptid. */
    private array $responsesbyattempt = [];

    /**
     * @var array<int, stdClass>|null Visible-page data rows; populated by query_db().
     *
     * Declared on this subclass because flexible_table itself does not
     * declare $rawdata (only sql_table does), so PHP 8.2+ would treat
     * direct assignment as creation of a dynamic property and emit a
     * deprecation. sql_table's declaration uses public visibility; we
     * match for consistency with the canonical pattern.
     */
    public $rawdata = null;

    /**
     * Construct the table and configure columns/headers/baseurl/sort settings.
     *
     * @param string $uniqueid flexible_table identifier (used for session-scoped state).
     * @param stdClass $scorecard Scorecard config row.
     * @param array<int, stdClass> $allattempts Pre-fetched attempts (already filtered by group).
     * @param string[] $identityfields Per-policy identity field names.
     * @param renderer $renderer Plugin renderer.
     * @param moodle_url $baseurl Page URL the pagination links point at.
     */
    public function __construct(
        string $uniqueid,
        stdClass $scorecard,
        array $allattempts,
        array $identityfields,
        renderer $renderer,
        moodle_url $baseurl
    ) {
        parent::__construct($uniqueid);
        $this->scorecard = $scorecard;
        $this->allattempts = $allattempts;
        $this->identityfields = $identityfields;
        $this->renderer = $renderer;

        // Tell flexible_table that the user id field on each row is "userid",
        // not the default "id" (which would clash with attemptid on our rows).
        $this->useridfield = 'userid';

        $columns = ['fullname', 'userid', 'username'];
        $headers = [
            get_string('report:col:fullname', 'mod_scorecard'),
            get_string('report:col:userid', 'mod_scorecard'),
            get_string('report:col:username', 'mod_scorecard'),
        ];
        foreach ($identityfields as $field) {
            $columns[] = 'identity_' . $field;
            $headers[] = (string)userfields::get_display_name($field);
        }
        $columns = array_merge(
            $columns,
            ['attemptnumber', 'submitted', 'totalscore', 'maxscore', 'percentage', 'band', 'detail']
        );
        $headers = array_merge($headers, [
            get_string('report:col:attemptnumber', 'mod_scorecard'),
            get_string('report:col:submitted', 'mod_scorecard'),
            get_string('report:col:totalscore', 'mod_scorecard'),
            get_string('report:col:maxscore', 'mod_scorecard'),
            get_string('report:col:percentage', 'mod_scorecard'),
            get_string('report:col:band', 'mod_scorecard'),
            get_string('report:detail:heading', 'mod_scorecard'),
        ]);

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        foreach ($columns as $col) {
            $this->no_sorting($col);
        }
        $this->set_attribute('class', 'generaltable scorecard-report-table');
    }

    /**
     * Convenience entry point mirroring sql_table::out() for callers that
     * want a single call: setup, query_db, build the row buffer, finish.
     *
     * flexible_table itself does NOT define out() -- only sql_table does --
     * so this subclass provides one to keep report.php's call site simple
     * (one method instead of an orchestration sequence). See sql_table::out
     * for the canonical Moodle pattern this mirrors.
     *
     * @param int $pagesize
     * @param bool $useinitialsbar
     */
    public function out($pagesize, $useinitialsbar): void {
        $this->setup();
        $this->query_db($pagesize, $useinitialsbar);
        if (!empty($this->rawdata)) {
            foreach ($this->rawdata as $row) {
                $formattedrow = $this->format_row($row);
                $this->add_data_keyed($formattedrow);
            }
        }
        $this->finish_output();
    }

    /**
     * Slice the pre-fetched attempts for the current page and batch-fetch
     * responses for the visible-page attemptids only.
     *
     * Phase 4.5 design note: the response fetch happens here, not at the
     * page level, so bandwidth scales with page size rather than total
     * attempt count. With 200 attempts × 20 items = 4000 response rows,
     * fetching only 25 attempts' responses cuts ~88% of the response-row
     * load relative to a page-level fetch.
     *
     * scorecard_get_attempt_responses() short-circuits empty input per its
     * 4.2 contract, so an empty page (e.g. last page of an empty filter)
     * doesn't need defensive handling here.
     *
     * @param int $pagesize
     * @param bool $useinitialsbar
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        $this->pagesize($pagesize, count($this->allattempts));
        $this->rawdata = array_slice(
            $this->allattempts,
            $this->get_page_start(),
            $this->get_page_size()
        );
        $pageattemptids = array_map(fn($row) => (int)$row->attemptid, $this->rawdata);
        $this->responsesbyattempt = \scorecard_get_attempt_responses($pageattemptids);
    }

    /**
     * Plain learner name (no profile link). Pre-4.5's render_report_table
     * also rendered the name unlinked; preserving that to keep 4.5 a
     * pure-pagination change rather than a UX behavior shift.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_fullname($row): string {
        return fullname($row);
    }

    /**
     * Plain integer rendering of the user id column.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_userid($row): string {
        return (string)(int)$row->userid;
    }

    /**
     * Username column with HTML escaping.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_username($row): string {
        return s((string)($row->username ?? ''));
    }

    /**
     * Identity-policy fields use an "identity_" column-name prefix so they
     * don't collide with any future reserved column names. format_row()
     * dispatches through other_cols() when no col_<columnname> method
     * exists, which is where the prefix is unwound.
     *
     * @param string $column
     * @param stdClass $row
     * @return string|null
     */
    public function other_cols($column, $row) {
        if (str_starts_with($column, 'identity_')) {
            $field = substr($column, strlen('identity_'));
            return s((string)($row->{$field} ?? ''));
        }
        return parent::other_cols($column, $row);
    }

    /**
     * Attempt-number cell.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_attemptnumber($row): string {
        return (string)(int)$row->attemptnumber;
    }

    /**
     * Submitted-date cell, formatted in the operator's timezone.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_submitted($row): string {
        return userdate((int)$row->timecreated);
    }

    /**
     * Total-score cell.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_totalscore($row): string {
        return (string)(int)$row->totalscore;
    }

    /**
     * Max-score cell.
     *
     * @param stdClass $row
     * @return string
     */
    public function col_maxscore($row): string {
        return (string)(int)$row->maxscore;
    }

    /**
     * Percentage rounded to integer for display, matching the result page's
     * round-half-away-from-zero convention. ALWAYS rendered regardless of
     * $scorecard->showpercentage (SPEC §10.4 line 476).
     *
     * @param stdClass $row
     * @return string
     */
    public function col_percentage($row): string {
        $rounded = (int)round((float)$row->percentage);
        return get_string('report:percentageformat', 'mod_scorecard', $rounded);
    }

    /**
     * Snapshot-only: bandlabelsnapshot from the attempt row, never the live
     * band record (SPEC §11.2). Falls back to the no-band placeholder when
     * snapshot is null (operator distinguishes "no band match" from "data
     * missing").
     *
     * @param stdClass $row
     * @return string
     */
    public function col_band($row): string {
        if (!empty($row->bandlabelsnapshot)) {
            return format_string((string)$row->bandlabelsnapshot);
        }
        return html_writer::span(
            get_string('report:col:noband', 'mod_scorecard'),
            'text-muted fst-italic'
        );
    }

    /**
     * Detail cell delegates to the plugin renderer's render_attempt_detail.
     * Responses for this attempt are looked up from the per-page batch
     * fetched in query_db().
     *
     * @param stdClass $row
     * @return string
     */
    public function col_detail($row): string {
        $attemptid = (int)$row->attemptid;
        $responses = $this->responsesbyattempt[$attemptid] ?? [];
        return $this->renderer->render_attempt_detail($this->scorecard, $row, $responses);
    }
}
