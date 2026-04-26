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
