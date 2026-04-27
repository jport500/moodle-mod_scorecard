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
 * Internal helpers for mod_scorecard's manage screen.
 *
 * Lifecycle gate (SPEC §4.5): items and bands hard-delete only when no
 * attempts exist for the parent scorecard; once any attempt exists, delete
 * becomes soft-delete (deleted=1, row retained for historical attempt
 * detail rendering). Bands follow the same rule once 2.3 lands.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Count attempts for a scorecard, cached per-request.
 *
 * Helpers branch on whether ANY attempt exists; the manage screen invokes
 * this multiple times per request (delete-confirm copy and delete execution
 * both consult it). The cache is reused at 2.3 for band lifecycle gating
 * and at Phase 4 for report generation. Backed by Moodle's MODE_REQUEST
 * cache rather than a function-scope static so PHPUnit's resetAfterTest()
 * purges it between methods cleanly.
 *
 * @param int $scorecardid
 * @return int Attempt count for the given scorecard.
 */
function scorecard_count_attempts(int $scorecardid): int {
    global $DB;
    $cache = cache::make('mod_scorecard', 'attemptcounts');
    $key = (string)$scorecardid;
    $value = $cache->get($key);
    if ($value === false) {
        $value = (int)$DB->count_records(
            'scorecard_attempts',
            ['scorecardid' => $scorecardid]
        );
        $cache->set($key, $value);
    }
    return (int)$value;
}

/**
 * Fetch the visible non-deleted items of a scorecard, in display order.
 *
 * Single source of truth for the item set the learner sees on the submission
 * form. Reused by the scoring engine in 3.2 to enumerate scorable items at
 * submit time, by the result-page item summary in 3.4, and by the lifecycle
 * gate at submit time in 3.3 (which re-queries this set immediately before
 * writing the attempt to detect items soft-deleted between render and submit).
 *
 * @param int $scorecardid
 * @return array Item rows keyed by id, sorted by sortorder ASC.
 */
function scorecard_get_visible_items(int $scorecardid): array {
    global $DB;
    return $DB->get_records(
        'scorecard_items',
        ['scorecardid' => $scorecardid, 'deleted' => 0, 'visible' => 1],
        'sortorder ASC'
    );
}

/**
 * Whether the given user has any attempt on the given scorecard.
 *
 * Used by view.php's learner branching to decide between form render and
 * result render. Cheap existence check; no need to load the full attempt
 * row when the caller only needs a yes/no.
 *
 * @param int $scorecardid
 * @param int $userid
 * @return bool
 */
function scorecard_user_has_attempt(int $scorecardid, int $userid): bool {
    global $DB;
    return $DB->record_exists(
        'scorecard_attempts',
        ['scorecardid' => $scorecardid, 'userid' => $userid]
    );
}

/**
 * Count attempts for a (scorecard, user) pair, used for attemptnumber assignment.
 *
 * Symmetrical with scorecard_count_attempts (which counts across users).
 * Submit handler in 3.3 reads this immediately before INSERT to set
 * attemptnumber = count + 1; Phase 4 reports use it as a per-user attempt
 * total without loading the rows.
 *
 * @param int $scorecardid
 * @param int $userid
 * @return int
 */
function scorecard_count_user_attempts(int $scorecardid, int $userid): int {
    global $DB;
    return (int)$DB->count_records(
        'scorecard_attempts',
        ['scorecardid' => $scorecardid, 'userid' => $userid]
    );
}

/**
 * Fetch the most-recent attempt by timecreated for the given (scorecard, user) pair.
 *
 * Symmetrical with scorecard_user_has_attempt and scorecard_count_user_attempts.
 * View.php's result branch reads the latest attempt and hands its snapshotted
 * columns to render_result_page. Phase 4 reports also use "user's latest
 * attempt" as a query, which is why this lives in locallib rather than
 * inline at the call site.
 *
 * Ordered by timecreated DESC to give natural-language semantics ("most
 * recent submission"). attemptnumber should agree in practice -- the submit
 * handler increments it monotonically per (scorecardid, userid) -- but
 * timecreated is the durable answer if a future bug ever desynchronises them.
 *
 * @param int $scorecardid
 * @param int $userid
 * @return stdClass|null Attempt row or null when the user has no attempts.
 */
function scorecard_get_latest_user_attempt(int $scorecardid, int $userid): ?stdClass {
    global $DB;
    $records = $DB->get_records(
        'scorecard_attempts',
        ['scorecardid' => $scorecardid, 'userid' => $userid],
        'timecreated DESC, id DESC',
        '*',
        0,
        1
    );
    return $records ? reset($records) : null;
}

/**
 * Add a new scored prompt to a scorecard.
 *
 * Sortorder defaults to MAX(sortorder)+1 across all rows (visible, hidden,
 * AND deleted) so that resurrecting a soft-deleted row in the future cannot
 * collide with a position assigned to a later add.
 *
 * The `required` field is hardcoded to 1 — SPEC §4.2 reserves it for v1.1
 * but always-required is the MVP behavior.
 *
 * @param stdClass $data Form data; must include scorecardid, prompt, promptformat.
 * @return int New item id.
 */
function scorecard_add_item(stdClass $data): int {
    global $DB;

    $now = time();
    $data->required = 1;
    $data->visible = $data->visible ?? 1;
    $data->deleted = 0;
    $data->timecreated = $now;
    $data->timemodified = $now;

    if (!isset($data->sortorder)) {
        $max = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) FROM {scorecard_items} WHERE scorecardid = ?',
            [$data->scorecardid]
        );
        $data->sortorder = $max + 1;
    }

    $itemid = (int)$DB->insert_record('scorecard_items', $data);

    // Phase 5a.2: recompute grade item if no attempts exist yet (SPEC §9.2
    // lifecycle gate). No-op when 1+ attempts exist — grademax is frozen.
    scorecard_recompute_grade_if_no_attempts((int)$data->scorecardid);

    return $itemid;
}

/**
 * Update an existing item.
 *
 * Re-parenting (scorecardid), the soft-delete flag, and sortorder are stripped
 * from the data object — those concerns belong to the dedicated delete and
 * move helpers, not the edit path.
 *
 * @param stdClass $data Form data; must include id.
 */
function scorecard_update_item(stdClass $data): void {
    global $DB;

    $data->timemodified = time();
    unset($data->scorecardid, $data->deleted, $data->sortorder);
    $DB->update_record('scorecard_items', $data);

    // Phase 5a.2: visibility toggle (the only editable field that can
    // change the visible-item count) recomputes grademax when no
    // attempts exist. Look up scorecardid from the just-updated item
    // since the function contract allows callers to pass $data without
    // scorecardid (or with a phantom one that gets stripped above).
    $scorecardid = (int)$DB->get_field(
        'scorecard_items',
        'scorecardid',
        ['id' => (int)$data->id]
    );
    scorecard_recompute_grade_if_no_attempts($scorecardid);
}

/**
 * Delete an item, branching on attempt count for the parent scorecard.
 *
 * 0 attempts: hard-delete; renumber remaining items so sortorder is a
 * contiguous 1..N sequence.
 *
 * 1+ attempts: soft-delete (deleted=1); keep sortorder so historical
 * attempt detail can still resolve responses → item.prompt.
 *
 * @param int $itemid
 */
function scorecard_delete_item(int $itemid): void {
    global $DB;

    $item = $DB->get_record('scorecard_items', ['id' => $itemid], '*', MUST_EXIST);

    if (scorecard_count_attempts((int)$item->scorecardid) > 0) {
        // Soft-delete branch: per SPEC §9.2, grademax does NOT recompute
        // when attempts exist. The item is marked deleted but the grade
        // item's grademax stays at its frozen value.
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemid]);
        $DB->set_field('scorecard_items', 'timemodified', time(), ['id' => $itemid]);
        return;
    }

    $DB->delete_records('scorecard_items', ['id' => $itemid]);
    scorecard_renumber_items((int)$item->scorecardid);

    // Phase 5a.2: hard-delete branch only (count_attempts == 0 guaranteed
    // by the early return above). Recompute grademax to reflect the
    // smaller visible-item count.
    scorecard_recompute_grade_if_no_attempts((int)$item->scorecardid);
}

/**
 * Move an item up or down by swapping sortorder with its non-deleted neighbor.
 *
 * Soft-deleted items are skipped when computing the neighbor; they appear at
 * their original position in the manage list but do not participate in
 * reorder. If the item is already at the top (`up`) or bottom (`down`) of
 * the non-deleted run, the call is a no-op.
 *
 * @param int $itemid
 * @param string $direction Either 'up' or 'down'.
 */
function scorecard_move_item(int $itemid, string $direction): void {
    global $DB;

    if ($direction !== 'up' && $direction !== 'down') {
        throw new coding_exception('scorecard_move_item: direction must be "up" or "down".');
    }

    $item = $DB->get_record('scorecard_items', ['id' => $itemid], '*', MUST_EXIST);
    if (!empty($item->deleted)) {
        return;
    }

    if ($direction === 'up') {
        $sql = 'SELECT * FROM {scorecard_items}
                WHERE scorecardid = :sid AND deleted = 0 AND sortorder < :so
                ORDER BY sortorder DESC';
    } else {
        $sql = 'SELECT * FROM {scorecard_items}
                WHERE scorecardid = :sid AND deleted = 0 AND sortorder > :so
                ORDER BY sortorder ASC';
    }

    $neighbors = $DB->get_records_sql(
        $sql,
        ['sid' => $item->scorecardid, 'so' => $item->sortorder],
        0,
        1
    );
    $neighbor = reset($neighbors);
    if (!$neighbor) {
        return;
    }

    $now = time();
    $transaction = $DB->start_delegated_transaction();
    $DB->set_field('scorecard_items', 'sortorder', $neighbor->sortorder, ['id' => $item->id]);
    $DB->set_field('scorecard_items', 'sortorder', $item->sortorder, ['id' => $neighbor->id]);
    $DB->set_field('scorecard_items', 'timemodified', $now, ['id' => $item->id]);
    $DB->set_field('scorecard_items', 'timemodified', $now, ['id' => $neighbor->id]);
    $transaction->allow_commit();
}

/**
 * Recompute sortorder so non-deleted items form a contiguous 1..N sequence.
 *
 * Called after hard-delete to close the gap left by the removed row.
 * Soft-deleted items retain their original sortorder (out-of-band) and are
 * filtered out by non-deleted queries elsewhere.
 *
 * @param int $scorecardid
 */
function scorecard_renumber_items(int $scorecardid): void {
    global $DB;

    $items = $DB->get_records(
        'scorecard_items',
        ['scorecardid' => $scorecardid, 'deleted' => 0],
        'sortorder ASC',
        'id, sortorder'
    );
    $now = time();
    $position = 1;
    foreach ($items as $item) {
        if ((int)$item->sortorder !== $position) {
            $DB->set_field('scorecard_items', 'sortorder', $position, ['id' => $item->id]);
            $DB->set_field('scorecard_items', 'timemodified', $now, ['id' => $item->id]);
        }
        $position++;
    }
}

/**
 * Whether the rating scale (scalemin / scalemax) can still be changed.
 *
 * Returns true when no attempts exist for the scorecard — the operator is
 * free to revise the scale at this point. Returns false when one or more
 * attempts exist, because changing scale values would silently re-score
 * historical attempts (or, more likely, render their stored maxscore /
 * percentage inconsistent with the new scale). SPEC §4.5: "Changing
 * scalemin or scalemax is blocked once any attempt exists."
 *
 * Used by mod_form::validation() to surface a per-field error on scalemin
 * and scalemax when the operator submits a changed value with attempts
 * present.
 *
 * @param int $scorecardid
 * @return bool True if the scale may be changed; false otherwise.
 */
function scorecard_scale_change_allowed(int $scorecardid): bool {
    return scorecard_count_attempts($scorecardid) === 0;
}

/**
 * Add a new result band to a scorecard.
 *
 * Sortorder defaults to MAX(sortorder)+1 — the column is schema-required but
 * vestigial in MVP since bands display by minscore ASC. Kept incremental for
 * forward compatibility with v1.1+ if explicit reorder is added.
 *
 * @param stdClass $data Form data; must include scorecardid, minscore, maxscore, label.
 * @return int New band id.
 */
function scorecard_add_band(stdClass $data): int {
    global $DB;

    $now = time();
    $data->message = $data->message ?? '';
    $data->messageformat = $data->messageformat ?? FORMAT_HTML;
    $data->deleted = 0;
    $data->timecreated = $now;
    $data->timemodified = $now;

    if (!isset($data->sortorder)) {
        $max = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) FROM {scorecard_bands} WHERE scorecardid = ?',
            [$data->scorecardid]
        );
        $data->sortorder = $max + 1;
    }

    return (int)$DB->insert_record('scorecard_bands', $data);
}

/**
 * Update an existing band.
 *
 * Re-parenting (scorecardid), the soft-delete flag, and sortorder are stripped
 * from the data object — the same separation-of-concerns rule as items.
 *
 * @param stdClass $data Form data; must include id.
 */
function scorecard_update_band(stdClass $data): void {
    global $DB;

    $data->timemodified = time();
    unset($data->scorecardid, $data->deleted, $data->sortorder);
    $DB->update_record('scorecard_bands', $data);
}

/**
 * Delete a band, branching on attempt count for the parent scorecard.
 *
 * 0 attempts: hard-delete the row.
 * 1+ attempts: soft-delete (deleted=1). The row is retained because attempt
 * rows reference it via bandid (soft FK). SPEC §8.4 already snapshots
 * label/message onto each attempt, so the soft-deleted band row is read-only
 * metadata after that point — but keeping it preserves the FK integrity that
 * Phase 4 reports may rely on for joining.
 *
 * No renumber pass after hard-delete — bands display by minscore so a sortorder
 * gap is harmless.
 *
 * @param int $bandid
 */
function scorecard_delete_band(int $bandid): void {
    global $DB;

    $band = $DB->get_record('scorecard_bands', ['id' => $bandid], '*', MUST_EXIST);

    if (scorecard_count_attempts((int)$band->scorecardid) > 0) {
        $DB->set_field('scorecard_bands', 'deleted', 1, ['id' => $bandid]);
        $DB->set_field('scorecard_bands', 'timemodified', time(), ['id' => $bandid]);
        return;
    }

    $DB->delete_records('scorecard_bands', ['id' => $bandid]);
}

/**
 * Compute band coverage analysis: overlaps (hard error), gaps (warning), item count.
 *
 * Returns a structured array consumed by both band_form::validation() (for
 * blocking save on overlap) and the manage.php Bands tab default render (for
 * surfacing a gap warning above the list). Pure logic — fully unit-testable
 * without HTTP or form scaffolding.
 *
 * Modes:
 * - $proposed === null: analyse the persisted band set as-is (used by manage
 *   page for the standing gap warning).
 * - $proposed !== null: inject the proposed band into the working set as if
 *   it were saved, replacing $excludebandid. Used by band_form::validation()
 *   to evaluate "would this save introduce overlap?".
 *
 * Overlaps: pairwise check, both bounds inclusive. Off-by-one edges
 * (a.maxscore == b.minscore) ARE reported as a one-point overlap because
 * the scoring engine §11 uses inclusive bounds on both sides — a learner
 * scoring exactly that value would match two bands without disambiguation.
 *
 * Gaps: computed only when overlaps is empty AND itemcount > 0. Theoretical
 * range = itemcount × scalemin .. itemcount × scalemax. Bands clipped to
 * range; gaps reported as a list of [min, max] tuples sorted by min ASC.
 *
 * @param int $scorecardid
 * @param int|null $excludebandid Existing band id to exclude (when editing).
 * @param stdClass|null $proposed Synthetic band {label, minscore, maxscore}
 *                                injected into the working set as id=0.
 * @return array Keys: overlaps (list of stdClass), gaps (list of {min, max}),
 *               itemcount (int).
 */
function scorecard_compute_band_coverage(
    int $scorecardid,
    ?int $excludebandid = null,
    ?stdClass $proposed = null
): array {
    global $DB;

    $params = ['scorecardid' => $scorecardid];
    $sql = 'SELECT id, label, minscore, maxscore
            FROM {scorecard_bands}
            WHERE scorecardid = :scorecardid AND deleted = 0';
    if ($excludebandid !== null) {
        $sql .= ' AND id != :excludebandid';
        $params['excludebandid'] = $excludebandid;
    }
    $sql .= ' ORDER BY minscore ASC, id ASC';
    $bands = array_values($DB->get_records_sql($sql, $params));

    if ($proposed !== null) {
        $bands[] = (object)[
            'id' => 0,
            'label' => (string)$proposed->label,
            'minscore' => (int)$proposed->minscore,
            'maxscore' => (int)$proposed->maxscore,
        ];
        usort($bands, function (stdClass $a, stdClass $b): int {
            return (int)$a->minscore - (int)$b->minscore;
        });
    }

    $itemcount = (int)$DB->count_records('scorecard_items', [
        'scorecardid' => $scorecardid,
        'deleted' => 0,
        'visible' => 1,
    ]);

    $overlaps = [];
    $bandcount = count($bands);
    for ($i = 0; $i < $bandcount; $i++) {
        for ($j = $i + 1; $j < $bandcount; $j++) {
            $a = $bands[$i];
            $b = $bands[$j];
            $lo = max((int)$a->minscore, (int)$b->minscore);
            $hi = min((int)$a->maxscore, (int)$b->maxscore);
            if ($lo <= $hi) {
                $overlaps[] = (object)[
                    'a_id' => (int)$a->id,
                    'a_label' => $a->label,
                    'a_min' => (int)$a->minscore,
                    'a_max' => (int)$a->maxscore,
                    'b_id' => (int)$b->id,
                    'b_label' => $b->label,
                    'b_min' => (int)$b->minscore,
                    'b_max' => (int)$b->maxscore,
                    'overlap_min' => $lo,
                    'overlap_max' => $hi,
                ];
            }
        }
    }

    $gaps = [];
    if (empty($overlaps) && $itemcount > 0) {
        $scorecard = $DB->get_record(
            'scorecard',
            ['id' => $scorecardid],
            'scalemin, scalemax',
            MUST_EXIST
        );
        $rangemin = $itemcount * (int)$scorecard->scalemin;
        $rangemax = $itemcount * (int)$scorecard->scalemax;

        $cursor = $rangemin;
        foreach ($bands as $band) {
            $bmin = (int)$band->minscore;
            $bmax = (int)$band->maxscore;
            if ($bmax < $rangemin || $bmin > $rangemax) {
                continue;
            }
            $clippedmin = max($bmin, $rangemin);
            if ($clippedmin > $cursor) {
                $gaps[] = ['min' => $cursor, 'max' => $clippedmin - 1];
            }
            $cursor = max($cursor, min($bmax, $rangemax) + 1);
        }
        if ($cursor <= $rangemax) {
            $gaps[] = ['min' => $cursor, 'max' => $rangemax];
        }
    }

    return [
        'overlaps' => $overlaps,
        'gaps' => $gaps,
        'itemcount' => $itemcount,
    ];
}

/**
 * Compute the data for a scorecard attempt: total, max, percentage, matched band, snapshots.
 *
 * Pure function -- does not touch the database. Caller fetches scorecard, items
 * (visible non-deleted), responses, and bands (deleted=0, pre-sorted by minscore
 * ASC then id ASC), then invokes this with well-formed inputs. Used by
 * submit.php in 3.3 and by the report layer in Phase 4 ("what would this
 * attempt have scored under current config?").
 *
 * Required $scorecard fields: scalemin (int), scalemax (int), fallbackmessage
 * (string), fallbackmessageformat (int). Other fields on the row are ignored.
 * Missing required fields are a caller bug, not the engine's defensive
 * responsibility.
 *
 * Bands array contract: pre-sorted by minscore ASC, id ASC. Engine iterates
 * as-given and the first band whose [minscore, maxscore] inclusive range
 * contains totalscore wins. Engine does NOT re-sort the input. Phase 2.4's
 * coverage validation prevents shared-boundary and equal-minscore states from
 * ever being saved, but the engine remains deterministic if a malformed
 * scorecard reaches it (e.g., backup restore, direct DB edit).
 *
 * Responses array contract: itemid => integer value. Responses for itemids
 * not present in $items are silently ignored from totalscore (audit-only;
 * those rows are written by the submit handler for historical fidelity but
 * do not contribute to scoring).
 *
 * @param stdClass $scorecard Scorecard config (scalemin, scalemax, fallbackmessage, fallbackmessageformat required).
 * @param array $items Visible non-deleted items keyed by id.
 * @param array $responses Map of itemid => int response value.
 * @param array $bands Bands sorted by minscore ASC, id ASC.
 * @return array {
 *     totalscore: int,
 *     maxscore: int,
 *     percentage: float (rounded to 2dp),
 *     bandid: int|null (null on fallback),
 *     bandlabelsnapshot: string|null (null on fallback),
 *     bandmessagesnapshot: string (matched band message via (string) cast, or fallbackmessage on no-match),
 *     bandmessageformatsnapshot: int (matched band format, or fallbackmessageformat on no-match),
 * }
 * @throws \coding_exception When $items is empty (maxscore would be 0) or any in-set response is outside [scalemin, scalemax].
 */
function scorecard_compute_attempt_data(
    stdClass $scorecard,
    array $items,
    array $responses,
    array $bands
): array {
    $scalemin = (int)$scorecard->scalemin;
    $scalemax = (int)$scorecard->scalemax;
    $itemcount = count($items);

    if ($itemcount === 0) {
        throw new \coding_exception(
            'scorecard_compute_attempt_data: $items is empty (maxscore would be zero); '
            . 'submit handler must reject empty scorecards via the lifecycle gate before invoking the engine.'
        );
    }

    $totalscore = 0;
    foreach ($responses as $itemid => $value) {
        if (!array_key_exists($itemid, $items)) {
            continue;
        }
        $value = (int)$value;
        if ($value < $scalemin || $value > $scalemax) {
            throw new \coding_exception(
                "scorecard_compute_attempt_data: response value {$value} for item {$itemid} "
                . "is outside scale [{$scalemin}, {$scalemax}]; submit handler must validate before invoking the engine."
            );
        }
        $totalscore += $value;
    }

    $maxscore = $itemcount * $scalemax;
    $percentage = round(($totalscore / $maxscore) * 100, 2);

    $bandid = null;
    $bandlabelsnapshot = null;
    $bandmessagesnapshot = (string)$scorecard->fallbackmessage;
    $bandmessageformatsnapshot = (int)$scorecard->fallbackmessageformat;

    foreach ($bands as $band) {
        if ($totalscore >= (int)$band->minscore && $totalscore <= (int)$band->maxscore) {
            $bandid = (int)$band->id;
            $bandlabelsnapshot = (string)$band->label;
            $bandmessagesnapshot = (string)$band->message;
            $bandmessageformatsnapshot = (int)$band->messageformat;
            break;
        }
    }

    return [
        'totalscore' => $totalscore,
        'maxscore' => $maxscore,
        'percentage' => $percentage,
        'bandid' => $bandid,
        'bandlabelsnapshot' => $bandlabelsnapshot,
        'bandmessagesnapshot' => $bandmessagesnapshot,
        'bandmessageformatsnapshot' => $bandmessageformatsnapshot,
    ];
}

/**
 * Fetch attempts for a scorecard with user identity fields joined, ordered for the report.
 *
 * Single source of truth for the Phase 4 report-page row set. Joins {user} so the
 * caller can render fullname() and the identity columns dictated by SPEC §10.4
 * without a per-row second query.
 *
 * Identity columns: SPEC §10.4 mandates `\core_user\fields::for_identity($context)`
 * for the optional set (email, idnumber, department, institution, custom profile
 * fields), plus userid + username always — username because downstream operators
 * need it as a join key into other LMS data. Name fields (firstname, lastname,
 * etc.) are pulled via `with_name()` so fullname() at render time renders correctly
 * regardless of site naming convention.
 *
 * Sort order: userid ASC, attemptnumber ASC (per Phase 4 pre-flag #7). Multiple
 * attempts per user appear together, sorted by attempt number ascending. Sortable
 * column controls are out of scope for MVP -- this is the default and only sort.
 *
 * Group filter (Phase 4.3): when $groupid is null or 0, no group filter is
 * applied (returns all attempts on the scorecard). When $groupid > 0, an
 * additional JOIN against {groups_members} restricts the result set to
 * attempts by users who are members of that group at query time. Group
 * membership is read live -- if a learner moves between groups after
 * submitting, the attempt's filter membership shifts accordingly. This
 * matches Moodle convention (membership is current-state, not historical).
 *
 * The optional JOIN is constructed via PHP string concatenation rather than
 * a LEFT JOIN with OR-condition trickery in the WHERE clause: cleaner SQL,
 * simpler query plans, the :groupid parameter is bound only when actually
 * filtering. Standard Moodle pattern.
 *
 * Snapshot-only reads: the helper does NOT join {scorecard_bands}. Reports display
 * the attempt's bandlabelsnapshot column, not the live band -- SPEC §11.2 carries
 * forward into reports.
 *
 * @param \context $context Module context, used by for_identity() to honour the
 *                          per-context identity policy.
 * @param int $scorecardid
 * @param int|null $groupid null or 0 returns all attempts; >0 filters to members
 *                          of the specified group. Caller (report.php) typically
 *                          passes the value from groups_get_activity_group($cm, true),
 *                          which handles the moodle/site:accessallgroups capability
 *                          check internally.
 * @return array<int, \stdClass> Attempt rows with user fields joined, ordered by
 *                               userid ASC, attemptnumber ASC. Returns empty
 *                               array when no attempts match the filter.
 */
function scorecard_get_attempts(\context $context, int $scorecardid, ?int $groupid = null): array {
    global $DB;

    // The for_identity() call resolves the field list per the site's
    // $CFG->showuseridentity and the context's local override. with_name()
    // ensures fullname() has every language-dependent name component available
    // without a second query.
    $userfieldsapi = \core_user\fields::for_identity($context, true)->with_name();
    $userfields = $userfieldsapi->get_sql('u', true);

    $params = ['scorecardid' => $scorecardid] + $userfields->params;
    $groupjoin = '';
    if ($groupid !== null && $groupid > 0) {
        $groupjoin = 'JOIN {groups_members} gm ON gm.userid = a.userid AND gm.groupid = :groupid';
        $params['groupid'] = (int)$groupid;
    }

    $sql = "SELECT a.id AS attemptid,
                   a.scorecardid,
                   a.userid,
                   a.attemptnumber,
                   a.totalscore,
                   a.maxscore,
                   a.percentage,
                   a.bandid,
                   a.bandlabelsnapshot,
                   a.bandmessagesnapshot,
                   a.bandmessageformatsnapshot,
                   a.timecreated,
                   a.timemodified,
                   u.username
                   {$userfields->selects}
            FROM {scorecard_attempts} a
            JOIN {user} u ON u.id = a.userid
            {$userfields->joins}
            {$groupjoin}
           WHERE a.scorecardid = :scorecardid
        ORDER BY a.userid ASC, a.attemptnumber ASC, a.id ASC";

    return array_values($DB->get_records_sql($sql, $params));
}

/**
 * Fetch per-attempt response rows for a batch of attempts, joined to live items.
 *
 * Phase 4.2 expandable detail block: report.php loads attempts via
 * scorecard_get_attempts() then calls this with array_column($attempts, 'attemptid')
 * to get every response in one round-trip. Avoids the N+1 antipattern that would
 * arise from per-row fetches inside the renderer loop.
 *
 * Live prompt reads (Phase 4.2 Type A option I): the SQL LEFT JOINs scorecard_items
 * to surface the current prompt text, promptformat, deleted flag, and sortorder.
 * scorecard_responses has no per-row prompt snapshot column -- adding one would be
 * a v1.x schema change (followup #21). The behavior matches Phase 3.4's result page,
 * which also reads live item prompts. If a teacher edits an item's prompt after
 * submissions exist, both surfaces show the new text alongside the snapshotted
 * scoring/band values.
 *
 * Soft-deleted items still resolve through the join (deleted=1, row retained for
 * historical detail). Hard-deleted items would land NULL on the joined columns;
 * SPEC §4.5's lifecycle gate prevents hard-delete once attempts exist, so this
 * is a defensive case rather than an expected one. ORDER BY sortorder uses
 * COALESCE(..., 999999) to push any NULL sortorder rows to the end deterministically.
 *
 * @param int[] $attemptids Attempt ids to batch-fetch responses for.
 * @return array<int, array<int, \stdClass>> Map of attemptid => list of response
 *         rows, each row carrying responseid, attemptid, itemid, responsevalue,
 *         timecreated, prompt, promptformat, deleted, sortorder. Attempts with no
 *         responses (or missing from input) do NOT appear in the map; callers
 *         should default to [] for unmapped attemptids.
 */
function scorecard_get_attempt_responses(array $attemptids): array {
    global $DB;

    if (empty($attemptids)) {
        return [];
    }

    [$insql, $inparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'aid');

    $sql = "SELECT r.id AS responseid,
                   r.attemptid,
                   r.itemid,
                   r.responsevalue,
                   r.timecreated,
                   i.prompt,
                   i.promptformat,
                   i.deleted,
                   i.sortorder
              FROM {scorecard_responses} r
         LEFT JOIN {scorecard_items} i ON i.id = r.itemid
             WHERE r.attemptid {$insql}
          ORDER BY r.attemptid ASC,
                   COALESCE(i.sortorder, 999999) ASC,
                   r.id ASC";

    $rows = $DB->get_records_sql($sql, $inparams);

    $grouped = [];
    foreach ($rows as $row) {
        $aid = (int)$row->attemptid;
        if (!isset($grouped[$aid])) {
            $grouped[$aid] = [];
        }
        $grouped[$aid][] = $row;
    }
    return $grouped;
}

/**
 * Derive the ordered item set for the report's CSV export from the responses batch.
 *
 * Phase 4.4 helper. Reuses the data structure returned by
 * scorecard_get_attempt_responses() (which already LEFT-JOINs scorecard_items)
 * rather than issuing a second SQL query. No item appears in the export's
 * column set unless at least one in-view attempt has a response for it -- this
 * matches the Phase 4 kickoff Q3 disposition: deleted items with zero
 * responses-in-view don't add empty-column noise.
 *
 * Ordering: live items first (deleted = 0) by sortorder ASC, then deleted items
 * (deleted = 1) by sortorder ASC at the end. Deterministic so test assertions
 * can pin specific column positions and operators reading the CSV in a
 * spreadsheet see a stable column order.
 *
 * Header text for deleted items: the LEFT JOIN preserves the live prompt
 * because soft-delete keeps the item row (deleted = 1, prompt unchanged). So
 * "latest snapshot" of a deleted item's prompt IS just its current prompt --
 * no separate snapshot lookup required. The "[deleted] " prefix is applied at
 * header-build time in scorecard_build_export_data, not here.
 *
 * @param array<int, array<int, \stdClass>> $responsesbyattempt Output of
 *        scorecard_get_attempt_responses() -- map of attemptid to response rows.
 * @return array<int, \stdClass> Ordered item rows. Each carries id, prompt,
 *         promptformat, deleted, sortorder. Empty array when no responses.
 */
function scorecard_get_export_item_set(array $responsesbyattempt): array {
    $itemsbyid = [];
    foreach ($responsesbyattempt as $rows) {
        foreach ($rows as $row) {
            $itemid = (int)$row->itemid;
            if ($itemid <= 0) {
                continue;
            }
            if (!isset($itemsbyid[$itemid])) {
                $itemsbyid[$itemid] = (object)[
                    'id' => $itemid,
                    'prompt' => (string)($row->prompt ?? ''),
                    'promptformat' => (int)($row->promptformat ?? FORMAT_HTML),
                    'deleted' => !empty($row->deleted) ? 1 : 0,
                    'sortorder' => $row->sortorder !== null ? (int)$row->sortorder : PHP_INT_MAX,
                ];
            }
        }
    }

    $items = array_values($itemsbyid);
    usort($items, function (\stdClass $a, \stdClass $b): int {
        if ($a->deleted !== $b->deleted) {
            return $a->deleted - $b->deleted;
        }
        return $a->sortorder - $b->sortorder;
    });

    return $items;
}

/**
 * Build the CSV export's headers + rows from the report data fetched by report.php.
 *
 * Phase 4.4 helper. Returns a plain array structure rather than streaming so
 * PHPUnit can assert the data shape without capturing output buffers --
 * standard Moodle pattern (mod_quiz, mod_feedback). The caller (export.php)
 * walks the structure and feeds each row to csv_export_writer for streaming.
 *
 * Headers (in order): fullname, userid, username, then the per-policy identity
 * fields (each via \core_user\fields::get_display_name), then attemptnumber,
 * submitted, totalscore, maxscore, percentage, band, then one column per item
 * in the item set. Live item headers use format_string on the current prompt;
 * deleted item headers prepend "[deleted] " to that current prompt. Per Phase
 * 4 kickoff Q7c disposition, the "latest snapshot" framing collapses to "the
 * current prompt" because soft-delete preserves the row.
 *
 * Rows: one per attempt, in the order $attempts was passed (typically userid
 * ASC, attemptnumber ASC from scorecard_get_attempts). Cell values follow the
 * header column order. Per-item cells contain the responsevalue or blank
 * string '' when the attempt has no response row for that item -- per kickoff
 * Q7d disposition (audit-honest blank for items added after the attempt was
 * submitted, etc.).
 *
 * Submitted timestamp: rendered via userdate() so the CSV reads sensibly in
 * the operator's timezone. Trade-off: not a machine-parsable ISO 8601 timestamp.
 * Possible v1.x enhancement (followup #22 if it surfaces) to add a parallel
 * "submitted_iso" column. Keeping MVP simple.
 *
 * @param \stdClass $scorecard Scorecard config row (scalemin / scalemax read
 *                             for the response-cell numeric range; band
 *                             snapshot fields read from each attempt row).
 * @param array<int, \stdClass> $attempts Attempt rows from
 *                                        scorecard_get_attempts(); already
 *                                        joined to user identity fields.
 * @param array<int, array<int, \stdClass>> $responsesbyattempt Map of attemptid
 *                                                              to response rows.
 * @param array<int, \stdClass> $itemset Output of scorecard_get_export_item_set;
 *                                       defines the per-item column order.
 * @param string[] $identityfields Per-policy identity field names from
 *                                 \core_user\fields::get_identity_fields().
 * @return array{headers: string[], rows: array<int, array<int, string>>}
 */
function scorecard_build_export_data(
    \stdClass $scorecard,
    array $attempts,
    array $responsesbyattempt,
    array $itemset,
    array $identityfields
): array {
    $headers = [
        get_string('report:col:fullname', 'mod_scorecard'),
        get_string('report:col:userid', 'mod_scorecard'),
        get_string('report:col:username', 'mod_scorecard'),
    ];
    foreach ($identityfields as $field) {
        $headers[] = \core_user\fields::get_display_name($field);
    }
    $headers[] = get_string('report:col:attemptnumber', 'mod_scorecard');
    $headers[] = get_string('report:col:submitted', 'mod_scorecard');
    $headers[] = get_string('report:col:totalscore', 'mod_scorecard');
    $headers[] = get_string('report:col:maxscore', 'mod_scorecard');
    $headers[] = get_string('report:col:percentage', 'mod_scorecard');
    $headers[] = get_string('report:col:band', 'mod_scorecard');

    $deletedprefix = get_string('report:detail:deletedprefix', 'mod_scorecard');
    foreach ($itemset as $item) {
        $promptlabel = format_string((string)$item->prompt);
        if (!empty($item->deleted)) {
            $promptlabel = $deletedprefix . $promptlabel;
        }
        $headers[] = $promptlabel;
    }

    $rows = [];
    foreach ($attempts as $attempt) {
        // Build per-attempt response lookup keyed by itemid for O(1) cell access.
        $responsebyitem = [];
        $attemptid = (int)$attempt->attemptid;
        foreach ($responsesbyattempt[$attemptid] ?? [] as $r) {
            $responsebyitem[(int)$r->itemid] = (int)$r->responsevalue;
        }

        $row = [
            fullname($attempt),
            (string)(int)$attempt->userid,
            (string)($attempt->username ?? ''),
        ];
        foreach ($identityfields as $field) {
            $row[] = (string)($attempt->{$field} ?? '');
        }
        $row[] = (string)(int)$attempt->attemptnumber;
        $row[] = userdate((int)$attempt->timecreated);
        $row[] = (string)(int)$attempt->totalscore;
        $row[] = (string)(int)$attempt->maxscore;
        $row[] = (string)(int)round((float)$attempt->percentage);
        $row[] = !empty($attempt->bandlabelsnapshot)
            ? (string)$attempt->bandlabelsnapshot
            : get_string('report:col:noband', 'mod_scorecard');

        foreach ($itemset as $item) {
            $itemid = (int)$item->id;
            $row[] = array_key_exists($itemid, $responsebyitem)
                ? (string)$responsebyitem[$itemid]
                : '';
        }

        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Validate, score, and persist a learner submission inside a single transaction.
 *
 * The HTTP entry point at /mod/scorecard/submit.php owns the auth boundary
 * (require_login + require_sesskey + require_capability); this function
 * trusts the caller has done that and focuses on validation, scoring, and
 * persistence. PHPUnit calls it directly with synthesized inputs.
 *
 * Validation order (matches the locked 3.3 contract):
 *   1. Itemid-subset: every key in $rawresponses must belong to the scorecard's
 *      item set (any state). Catches POST injection. Form-level reject on fail.
 *   2. Lifecycle gate: re-fetch visible items at submit time. Empty set means
 *      every item was soft-deleted between render and submit; form-level reject.
 *   3. Per-item: every visible item must have a response (missing -> per-fieldset
 *      error), and every response for a visible item must be a numeric int in
 *      [scalemin, scalemax] (out-of-range -> per-fieldset error). Steps 3a (missing)
 *      and 3b (out-of-range) collect together so the user sees every problem on
 *      one re-render.
 *   4. Duplicate: if the user already has an attempt and allowretakes is off,
 *      short-circuit with status='duplicate_attempt' for a silent redirect.
 *
 * Audit-write semantics (locked option B): response rows are written for every
 * itemid in $rawresponses that belongs to the scorecard, including itemids
 * soft-deleted between render and submit. Engine sums only over visible items;
 * orphan responses are preserved in scorecard_responses for Phase 4 reports.
 *
 * Gradebook: 3.3 does not call grade_update(). Phase 5a wires that in inside
 * this same handler, after commit, before the event trigger.
 *
 * @param stdClass $scorecard Scorecard row (must include id, scalemin, scalemax,
 *                            allowretakes, fallbackmessage, fallbackmessageformat).
 * @param stdClass $cm Course module row (used for context + URL in the event).
 * @param int $userid Submitting user id.
 * @param array $rawresponses Map of itemid => raw value (typically from $_POST['response']).
 * @return array {
 *     status: 'submitted'|'validation_failed'|'duplicate_attempt',
 *     errors: array<int|string, string> ([itemid => msg] for per-fieldset; ['_form' => msg] for form-level),
 *     attemptid: int|null,
 *     preselected: array<int, mixed> (echo-back of rawresponses on validation_failed),
 * }
 */
function scorecard_handle_submission(
    stdClass $scorecard,
    stdClass $cm,
    int $userid,
    array $rawresponses
): array {
    global $DB;

    $scorecardid = (int)$scorecard->id;
    $scalemin = (int)$scorecard->scalemin;
    $scalemax = (int)$scorecard->scalemax;

    // Step 1: itemid-subset (POST-injection guard).
    $allitems = $DB->get_records(
        'scorecard_items',
        ['scorecardid' => $scorecardid],
        '',
        'id'
    );
    foreach (array_keys($rawresponses) as $rid) {
        if (!isset($allitems[(int)$rid])) {
            return [
                'status' => 'validation_failed',
                'errors' => ['_form' => get_string('submit:error:invaliditem', 'mod_scorecard')],
                'attemptid' => null,
                'preselected' => $rawresponses,
            ];
        }
    }

    // Step 2: lifecycle gate.
    $visibleitems = scorecard_get_visible_items($scorecardid);
    if (empty($visibleitems)) {
        return [
            'status' => 'validation_failed',
            'errors' => ['_form' => get_string('submit:error:noitems', 'mod_scorecard')],
            'attemptid' => null,
            'preselected' => $rawresponses,
        ];
    }

    // Step 3: per-item missing + out-of-range, collected together.
    $errors = [];
    $cleanresponses = [];
    foreach ($visibleitems as $item) {
        $itemid = (int)$item->id;
        if (!array_key_exists($itemid, $rawresponses)) {
            $errors[$itemid] = get_string('submit:error:missing', 'mod_scorecard');
            continue;
        }
        $raw = $rawresponses[$itemid];
        if (!is_numeric($raw)) {
            $errors[$itemid] = get_string('submit:error:outofrange', 'mod_scorecard');
            continue;
        }
        $intval = (int)$raw;
        if ($intval < $scalemin || $intval > $scalemax) {
            $errors[$itemid] = get_string('submit:error:outofrange', 'mod_scorecard');
            continue;
        }
        $cleanresponses[$itemid] = $intval;
    }
    if (!empty($errors)) {
        return [
            'status' => 'validation_failed',
            'errors' => $errors,
            'attemptid' => null,
            'preselected' => $rawresponses,
        ];
    }

    // Step 4: duplicate attempt (retakes off).
    $existingcount = scorecard_count_user_attempts($scorecardid, $userid);
    if ($existingcount > 0 && empty($scorecard->allowretakes)) {
        return [
            'status' => 'duplicate_attempt',
            'errors' => [],
            'attemptid' => null,
            'preselected' => [],
        ];
    }

    // Validation passed. Compute score, then write attempt + responses in a transaction.
    $bands = $DB->get_records(
        'scorecard_bands',
        ['scorecardid' => $scorecardid, 'deleted' => 0],
        'minscore ASC, id ASC'
    );
    $enginedata = scorecard_compute_attempt_data($scorecard, $visibleitems, $cleanresponses, $bands);

    $now = time();
    $transaction = $DB->start_delegated_transaction();
    try {
        $attemptid = $DB->insert_record('scorecard_attempts', (object)[
            'scorecardid' => $scorecardid,
            'userid' => $userid,
            'attemptnumber' => $existingcount + 1,
            'totalscore' => $enginedata['totalscore'],
            'maxscore' => $enginedata['maxscore'],
            'percentage' => $enginedata['percentage'],
            'bandid' => $enginedata['bandid'],
            'bandlabelsnapshot' => $enginedata['bandlabelsnapshot'],
            'bandmessagesnapshot' => $enginedata['bandmessagesnapshot'],
            'bandmessageformatsnapshot' => $enginedata['bandmessageformatsnapshot'],
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Audit-write: every itemid in $rawresponses that belongs to the scorecard.
        // Includes soft-deleted-between-render-and-submit items so Phase 4 can
        // show that the item was answered before being removed.
        foreach ($rawresponses as $itemid => $value) {
            $itemid = (int)$itemid;
            if (!isset($allitems[$itemid])) {
                continue;
            }
            $DB->insert_record('scorecard_responses', (object)[
                'attemptid' => $attemptid,
                'itemid' => $itemid,
                'responsevalue' => is_numeric($value) ? (int)$value : 0,
                'timecreated' => $now,
            ]);
        }

        $transaction->allow_commit();
    } catch (\Throwable $e) {
        $transaction->rollback($e);
    }

    // Cache invalidation: scorecard_count_attempts caches the per-scorecard total.
    cache::make('mod_scorecard', 'attemptcounts')->delete((string)$scorecardid);

    // Event fires after commit, before return -- atomicity argument: subscribers
    // observing this event are guaranteed the rows are persisted.
    \mod_scorecard\event\attempt_submitted::create([
        'context' => \context_module::instance((int)$cm->id),
        'objectid' => $attemptid,
        'userid' => $userid,
        'relateduserid' => $userid,
        'other' => [
            'totalscore' => $enginedata['totalscore'],
            'maxscore' => $enginedata['maxscore'],
            'bandid' => $enginedata['bandid'],
        ],
    ])->trigger();

    return [
        'status' => 'submitted',
        'errors' => [],
        'attemptid' => (int)$attemptid,
        'preselected' => [],
    ];
}

/**
 * Compute the auto-grademax for a scorecard from its visible items.
 *
 * Per SPEC §9.2: when gradeenabled is on and $scorecard->grade is 0,
 * grademax defaults to visible item count × scalemax. Used by
 * scorecard_grade_item_update when computing the grade item's grademax
 * param. Phase 5a.2 will hook this from items-CRUD lifecycle functions
 * to recompute on item add/remove while no attempts exist.
 *
 * Returns 0 when the scorecard has no visible items; the caller
 * (grade_item_update) treats 0 as "no grademax yet" and falls back to
 * GRADE_TYPE_NONE until items are added.
 *
 * @param \stdClass $scorecard Scorecard activity record (id and scalemax read).
 * @return int Visible item count × scalemax; 0 when no visible items.
 */
function scorecard_compute_auto_grademax(\stdClass $scorecard): int {
    $items = scorecard_get_visible_items((int)$scorecard->id);
    return count($items) * (int)$scorecard->scalemax;
}

/**
 * Recompute the grade item for a scorecard if no attempts exist yet.
 *
 * Phase 5a.2 lifecycle gate: per SPEC §9.2, grademax is recalculated on
 * item add/remove ONLY while no attempts exist. Once the first attempt
 * persists, grademax freezes at its then-current value to keep historical
 * scoring stable (SPEC §11.2's snapshot-stability rule applied to grade
 * items as well as attempt rows).
 *
 * Called from scorecard_add_item, scorecard_update_item, and the
 * hard-delete branch of scorecard_delete_item after they mutate the items
 * table. No-op when the scorecard has 1+ attempts. The naming makes the
 * gate condition explicit so future maintainers don't accidentally call
 * it from contexts that should not be gated (e.g., a v1.x bulk-import
 * helper that needs to recompute regardless).
 *
 * @param int $scorecardid Scorecard activity id whose grade item may need recomputing.
 */
function scorecard_recompute_grade_if_no_attempts(int $scorecardid): void {
    global $DB;

    // Use a direct DB count rather than scorecard_count_attempts here:
    // count_attempts uses a MODE_REQUEST cache (db/caches.php) that
    // production mutators invalidate via cache::make()->delete() in
    // scorecard_handle_submission. This helper is called from item-CRUD
    // lifecycle hooks where the cache may be stale — and worse, calling
    // count_attempts here would prime the cache with the pre-mutation
    // value, breaking subsequent scorecard_count_attempts callers (e.g.
    // scorecard_delete_item's soft-vs-hard branch) within the same
    // request. Direct count is one cheap query and side-effect free.
    $count = $DB->count_records('scorecard_attempts', ['scorecardid' => $scorecardid]);
    if ($count > 0) {
        return;
    }

    $scorecard = $DB->get_record('scorecard', ['id' => $scorecardid], '*', MUST_EXIST);
    scorecard_update_grades($scorecard);
}
