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
 * Tests for mod_scorecard band coverage analysis (overlaps, gaps).
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
require_once($CFG->dirroot . '/mod/scorecard/lib.php');
require_once($CFG->dirroot . '/mod/scorecard/locallib.php');

/**
 * Coverage analysis edge cases: overlaps, gaps, exclude-bandid, item-count gating.
 */
#[CoversNothing]
final class locallib_band_coverage_test extends \advanced_testcase {
    /**
     * Build a scorecard fixture with a defined scale for predictable range arithmetic.
     *
     * @param int $scalemin
     * @param int $scalemax
     * @return \stdClass scorecard row.
     */
    private function create_scorecard(int $scalemin = 1, int $scalemax = 10): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $data = (object)[
            'course' => $course->id,
            'name' => 'Fixture',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'scalemin' => $scalemin,
            'scalemax' => $scalemax,
            'displaystyle' => 'radio',
            'lowlabel' => '',
            'highlabel' => '',
            'allowretakes' => 0,
            'showresult' => 1,
            'showpercentage' => 0,
            'showitemsummary' => 1,
            'fallbackmessage_editor' => ['text' => '', 'format' => FORMAT_HTML],
            'gradeenabled' => 0,
            'grade' => 0,
        ];
        $id = scorecard_add_instance($data);
        return $DB->get_record('scorecard', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Add N visible non-deleted items so the theoretical range is N*scalemin..N*scalemax.
     *
     * @param int $scorecardid
     * @param int $count
     * @return int[] Item ids.
     */
    private function add_items(int $scorecardid, int $count): array {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = scorecard_add_item((object)[
                'scorecardid' => $scorecardid,
                'prompt' => "P$i",
                'promptformat' => FORMAT_HTML,
            ]);
        }
        return $ids;
    }

    /**
     * Add a band fixture and return its id.
     *
     * @param int $scorecardid
     * @param int $min
     * @param int $max
     * @param string $label
     * @return int Band id.
     */
    private function add_band(int $scorecardid, int $min, int $max, string $label): int {
        return scorecard_add_band((object)[
            'scorecardid' => $scorecardid,
            'minscore' => $min,
            'maxscore' => $max,
            'label' => $label,
        ]);
    }

    /**
     * No bands, no items: itemcount=0, gaps skipped, overlaps empty.
     */
    public function test_no_bands_no_items(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard();

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame(0, $coverage['itemcount']);
        $this->assertSame([], $coverage['overlaps']);
        $this->assertSame([], $coverage['gaps']);
    }

    /**
     * Items but no bands: full theoretical range surfaces as a single gap.
     */
    public function test_no_bands_with_items_reports_full_range_as_gap(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        // Theoretical range: 5*1=5 .. 5*10=50.

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame(5, $coverage['itemcount']);
        $this->assertSame([], $coverage['overlaps']);
        $this->assertCount(1, $coverage['gaps']);
        $this->assertSame(5, $coverage['gaps'][0]['min']);
        $this->assertSame(50, $coverage['gaps'][0]['max']);
    }

    /**
     * Single band exactly tiling the range: no gaps, no overlaps.
     */
    public function test_single_band_exact_tile(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        $this->add_band((int)$scorecard->id, 5, 50, 'All');

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame([], $coverage['overlaps']);
        $this->assertSame([], $coverage['gaps']);
    }

    /**
     * Single band leaving leading and trailing gaps.
     */
    public function test_single_band_with_leading_and_trailing_gaps(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        // Range 5..50; band covers 20..30, so gaps are 5..19 and 31..50.
        $this->add_band((int)$scorecard->id, 20, 30, 'Mid');

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame([], $coverage['overlaps']);
        $this->assertCount(2, $coverage['gaps']);
        $this->assertSame(['min' => 5, 'max' => 19], $coverage['gaps'][0]);
        $this->assertSame(['min' => 31, 'max' => 50], $coverage['gaps'][1]);
    }

    /**
     * Three bands fully tile with no gaps: empty gaps array.
     */
    public function test_three_bands_full_tile(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        // Range 5..50.
        $this->add_band((int)$scorecard->id, 5, 19, 'Low');
        $this->add_band((int)$scorecard->id, 20, 35, 'Mid');
        $this->add_band((int)$scorecard->id, 36, 50, 'High');

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame([], $coverage['overlaps']);
        $this->assertSame([], $coverage['gaps']);
    }

    /**
     * Three bands with a middle gap: gap reported sorted by min ASC.
     */
    public function test_three_bands_with_middle_gap(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        // Range 5..50; gap 21..29.
        $this->add_band((int)$scorecard->id, 5, 20, 'Low');
        $this->add_band((int)$scorecard->id, 30, 40, 'Mid');
        $this->add_band((int)$scorecard->id, 41, 50, 'High');

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame([], $coverage['overlaps']);
        $this->assertCount(1, $coverage['gaps']);
        $this->assertSame(['min' => 21, 'max' => 29], $coverage['gaps'][0]);
    }

    /**
     * Off-by-one overlap: a.maxscore == b.minscore is reported (both inclusive bounds).
     */
    public function test_offbyone_overlap_at_shared_boundary(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        $this->add_band((int)$scorecard->id, 5, 25, 'Low');
        $this->add_band((int)$scorecard->id, 25, 50, 'High');

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertCount(1, $coverage['overlaps']);
        $this->assertSame(25, $coverage['overlaps'][0]->overlap_min);
        $this->assertSame(25, $coverage['overlaps'][0]->overlap_max);
        // Gap detection skipped while overlaps are present.
        $this->assertSame([], $coverage['gaps']);
    }

    /**
     * Excluding a band id by editing it suppresses self-overlap false positives.
     */
    public function test_excludebandid_suppresses_self_overlap(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        $bandid = $this->add_band((int)$scorecard->id, 5, 25, 'Low');

        // Without exclusion + the same band as proposed: the proposed and the
        // persisted entry overlap with each other (would block save).
        $proposed = (object)['label' => 'Low', 'minscore' => 5, 'maxscore' => 25];
        $without = scorecard_compute_band_coverage((int)$scorecard->id, null, $proposed);
        $this->assertNotEmpty($without['overlaps']);

        // With exclusion: the persisted band is removed, so no self-overlap.
        $with = scorecard_compute_band_coverage((int)$scorecard->id, $bandid, $proposed);
        $this->assertSame([], $with['overlaps']);
    }

    /**
     * Proposed band injected into the working set affects gap detection.
     */
    public function test_proposed_band_closes_gap_in_analysis(): void {
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        $this->add_band((int)$scorecard->id, 5, 25, 'Low');
        // Without proposed: gap 26..50.
        $without = scorecard_compute_band_coverage((int)$scorecard->id);
        $this->assertCount(1, $without['gaps']);

        // With a proposed band closing the gap: no gap.
        $proposed = (object)['label' => 'High', 'minscore' => 26, 'maxscore' => 50];
        $with = scorecard_compute_band_coverage((int)$scorecard->id, null, $proposed);
        $this->assertSame([], $with['gaps']);
    }

    /**
     * Soft-deleted bands are excluded from coverage analysis.
     */
    public function test_soft_deleted_bands_excluded(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $this->add_items((int)$scorecard->id, 5);
        $this->add_band((int)$scorecard->id, 5, 25, 'Low');
        $bandid = $this->add_band((int)$scorecard->id, 26, 50, 'High');

        // Manually soft-delete the High band (simulating post-attempt state).
        $DB->set_field('scorecard_bands', 'deleted', 1, ['id' => $bandid]);

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        // High is excluded; gap 26..50 reappears.
        $this->assertSame([], $coverage['overlaps']);
        $this->assertCount(1, $coverage['gaps']);
        $this->assertSame(['min' => 26, 'max' => 50], $coverage['gaps'][0]);
    }

    /**
     * Hidden items reduce the theoretical range proportionally.
     */
    public function test_hidden_items_excluded_from_range(): void {
        global $DB;
        $this->resetAfterTest();
        $scorecard = $this->create_scorecard(1, 10);
        $ids = $this->add_items((int)$scorecard->id, 5);
        // Mark two items as hidden — itemcount drops to 3, range becomes 3..30.
        $DB->set_field('scorecard_items', 'visible', 0, ['id' => $ids[0]]);
        $DB->set_field('scorecard_items', 'visible', 0, ['id' => $ids[1]]);

        $coverage = scorecard_compute_band_coverage((int)$scorecard->id);

        $this->assertSame(3, $coverage['itemcount']);
        // No bands → full new range surfaces as one gap.
        $this->assertCount(1, $coverage['gaps']);
        $this->assertSame(['min' => 3, 'max' => 30], $coverage['gaps'][0]);
    }
}
