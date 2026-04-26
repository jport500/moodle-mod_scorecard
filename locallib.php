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

    return (int)$DB->insert_record('scorecard_items', $data);
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
        $DB->set_field('scorecard_items', 'deleted', 1, ['id' => $itemid]);
        $DB->set_field('scorecard_items', 'timemodified', time(), ['id' => $itemid]);
        return;
    }

    $DB->delete_records('scorecard_items', ['id' => $itemid]);
    scorecard_renumber_items((int)$item->scorecardid);
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
