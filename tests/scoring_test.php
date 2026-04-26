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
 * Tests for mod_scorecard's pure-logic scoring engine.
 *
 * Covers 3.2 deliverable scorecard_compute_attempt_data(): totalscore/maxscore
 * arithmetic, percentage rounding, band matching with first-match-on-minscore-ASC
 * semantics, fallback snapshots when no band matches, audit-only behavior for
 * orphan responses, and coding_exception on programmer-error inputs (empty
 * items, out-of-range response).
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard;

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scorecard/locallib.php');

/**
 * Pure-logic tests for scorecard_compute_attempt_data(). No DB fixtures.
 */
#[CoversNothing]
final class scoring_test extends \basic_testcase {
    /**
     * Build a scorecard config stdClass with the four engine-required fields.
     */
    private function scorecard(
        int $scalemin = 1,
        int $scalemax = 10,
        string $fallbackmessage = 'Default fallback',
        int $fallbackmessageformat = FORMAT_HTML
    ): \stdClass {
        return (object)[
            'scalemin' => $scalemin,
            'scalemax' => $scalemax,
            'fallbackmessage' => $fallbackmessage,
            'fallbackmessageformat' => $fallbackmessageformat,
        ];
    }

    /**
     * Build an items array keyed by id (engine reads keys, not item internals).
     *
     * @param int[] $ids
     * @return \stdClass[] Keyed by id.
     */
    private function items(array $ids): array {
        $items = [];
        foreach ($ids as $id) {
            $items[$id] = (object)['id' => $id];
        }
        return $items;
    }

    /**
     * Build a band stdClass.
     */
    private function band(
        int $id,
        int $minscore,
        int $maxscore,
        string $label = 'Band',
        ?string $message = 'Band message',
        int $messageformat = FORMAT_HTML
    ): \stdClass {
        return (object)[
            'id' => $id,
            'minscore' => $minscore,
            'maxscore' => $maxscore,
            'label' => $label,
            'message' => $message,
            'messageformat' => $messageformat,
        ];
    }

    /**
     * Case 1: single band, totalscore lands inside it.
     */
    public function test_single_band_match(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20, 30]);
        $responses = [10 => 5, 20 => 6, 30 => 7];
        $bands = [$this->band(100, 0, 30, 'Solid', 'Nice work.')];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(18, $result['totalscore']);
        $this->assertSame(30, $result['maxscore']);
        $this->assertSame(60.0, $result['percentage']);
        $this->assertSame(100, $result['bandid']);
        $this->assertSame('Solid', $result['bandlabelsnapshot']);
        $this->assertSame('Nice work.', $result['bandmessagesnapshot']);
        $this->assertSame((int)FORMAT_HTML, $result['bandmessageformatsnapshot']);
    }

    /**
     * Case 2: multiple bands, correct one wins on minscore ASC iteration.
     */
    public function test_multi_band_correct_match(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20, 30]);
        // Total = 4 + 5 + 6 = 15 -> mid band [11..20].
        $responses = [10 => 4, 20 => 5, 30 => 6];
        $bands = [
            $this->band(101, 0, 10, 'Low', 'Try again.'),
            $this->band(102, 11, 20, 'Mid', 'Decent.'),
            $this->band(103, 21, 30, 'High', 'Excellent.'),
        ];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(15, $result['totalscore']);
        $this->assertSame(102, $result['bandid']);
        $this->assertSame('Mid', $result['bandlabelsnapshot']);
        $this->assertSame('Decent.', $result['bandmessagesnapshot']);
    }

    /**
     * Case 3: fallback when totalscore is below all bands.
     */
    public function test_fallback_when_below_all_bands(): void {
        $scorecard = $this->scorecard(1, 10, 'No band fits.', FORMAT_PLAIN);
        $items = $this->items([10, 20]);
        $responses = [10 => 1, 20 => 1]; // Total 2.
        $bands = [
            $this->band(201, 5, 10, 'Mid'),
            $this->band(202, 11, 20, 'High'),
        ];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(2, $result['totalscore']);
        $this->assertNull($result['bandid']);
        $this->assertNull($result['bandlabelsnapshot']);
        $this->assertSame('No band fits.', $result['bandmessagesnapshot']);
        $this->assertSame((int)FORMAT_PLAIN, $result['bandmessageformatsnapshot']);
    }

    /**
     * Case 4: fallback when totalscore is above all bands.
     */
    public function test_fallback_when_above_all_bands(): void {
        $scorecard = $this->scorecard(1, 10, 'Off the charts.', FORMAT_MARKDOWN);
        $items = $this->items([10, 20]);
        $responses = [10 => 10, 20 => 10]; // Total 20.
        $bands = [
            $this->band(301, 0, 5, 'Low'),
            $this->band(302, 6, 15, 'Mid'),
        ];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(20, $result['totalscore']);
        $this->assertNull($result['bandid']);
        $this->assertNull($result['bandlabelsnapshot']);
        $this->assertSame('Off the charts.', $result['bandmessagesnapshot']);
        $this->assertSame((int)FORMAT_MARKDOWN, $result['bandmessageformatsnapshot']);
    }

    /**
     * Case 5: boundary value -- totalscore exactly equals matched band's minscore.
     */
    public function test_boundary_totalscore_equals_band_minscore(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20]);
        $responses = [10 => 6, 20 => 5]; // Total 11.
        $bands = [
            $this->band(401, 0, 10, 'Low'),
            $this->band(402, 11, 20, 'Mid'),
        ];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(11, $result['totalscore']);
        $this->assertSame(402, $result['bandid']);
        $this->assertSame('Mid', $result['bandlabelsnapshot']);
    }

    /**
     * Case 6: boundary value -- totalscore exactly equals matched band's maxscore.
     */
    public function test_boundary_totalscore_equals_band_maxscore(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20]);
        $responses = [10 => 5, 20 => 5]; // Total 10.
        $bands = [
            $this->band(501, 0, 10, 'Low'),
            $this->band(502, 11, 20, 'Mid'),
        ];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(10, $result['totalscore']);
        $this->assertSame(501, $result['bandid']);
        $this->assertSame('Low', $result['bandlabelsnapshot']);
    }

    /**
     * Case 7: shared-boundary engine robustness -- malformed scorecard reaches engine.
     */
    public function test_shared_boundary_robustness_first_match_wins(): void {
        // This shared-boundary state is prevented by manage.php's coverage
        // validation (Phase 2.4), but the engine must remain deterministic if
        // a malformed scorecard reaches it (e.g., backup restore from a
        // different version, or a direct DB edit by an admin). First match on
        // input order is the locked behavior; caller is responsible for sort.
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20]);
        $responses = [10 => 5, 20 => 5]; // Total 10.
        $bands = [
            $this->band(601, 0, 10, 'A'),
            $this->band(602, 10, 20, 'B'), // Shares boundary X=10 with band A.
        ];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(10, $result['totalscore']);
        $this->assertSame(601, $result['bandid'], 'Lower band wins on shared boundary (first match).');
        $this->assertSame('A', $result['bandlabelsnapshot']);
    }

    /**
     * Case 8: maxscore arithmetic with the smallest non-trivial item set.
     */
    public function test_maxscore_one_item_times_scalemax(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([42]);
        $responses = [42 => 7];
        $bands = [];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(7, $result['totalscore']);
        $this->assertSame(10, $result['maxscore']);
        $this->assertSame(70.0, $result['percentage']);
        $this->assertNull($result['bandid']);
    }

    /**
     * Case 9: audit-only -- response for an itemid not in $items is dropped from totalscore.
     */
    public function test_audit_only_responses_excluded_from_totalscore(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20]); // Item 99 was soft-deleted between render and submit.
        $responses = [10 => 5, 20 => 5, 99 => 9]; // 9 is for a no-longer-visible item.
        $bands = [];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(10, $result['totalscore'], 'Orphan response (itemid 99) must not contribute.');
        $this->assertSame(20, $result['maxscore']);
        $this->assertSame(50.0, $result['percentage']);
    }

    /**
     * Case 10: percentage rounded to 2 decimals (2/3 of 10 = 6.67, not 6.6666... or 7).
     */
    public function test_percentage_rounded_to_two_decimals(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20, 30]); // Maxscore = 30.
        $responses = [10 => 7, 20 => 7, 30 => 6]; // Total = 20 -> 20/30 = 66.6666...
        $bands = [];

        $result = scorecard_compute_attempt_data($scorecard, $items, $responses, $bands);

        $this->assertSame(20, $result['totalscore']);
        $this->assertSame(30, $result['maxscore']);
        $this->assertSame(66.67, $result['percentage']);
    }

    /**
     * Case 11: coding_exception thrown when $items is empty (maxscore would be zero).
     */
    public function test_throws_coding_exception_on_empty_items(): void {
        $scorecard = $this->scorecard(1, 10);
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessageMatches('/maxscore would be zero/');

        scorecard_compute_attempt_data($scorecard, [], [], []);
    }

    /**
     * Case 12: coding_exception thrown when a response value exceeds scalemax.
     */
    public function test_throws_coding_exception_on_out_of_range_response(): void {
        $scorecard = $this->scorecard(1, 10);
        $items = $this->items([10, 20]);
        $responses = [10 => 5, 20 => 11]; // 11 > scalemax 10.

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessageMatches('/outside scale \[1, 10\]/');

        scorecard_compute_attempt_data($scorecard, $items, $responses, []);
    }
}
