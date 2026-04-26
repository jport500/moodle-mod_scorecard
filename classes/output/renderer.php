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
 * Plugin renderer for mod_scorecard.
 *
 * Centralises the visual treatment of item and band rows so the manage
 * screen and the Phase 4 reports detail can share rendering helpers. The
 * private deleted_marker() helper produces the "(deleted)" badge plus
 * strikethrough that both render_item_row and render_band_row apply to
 * their primary text. Pattern follows mod_quiz's edit_renderer.php
 * inline-badge approach (line 1056+) rather than a Mustache partial.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\output;

use html_writer;
use moodle_url;
use plugin_renderer_base;
use stdClass;

/**
 * Plugin renderer for mod_scorecard.
 */
class renderer extends plugin_renderer_base {
    /**
     * Wrap a row's primary text with strikethrough + (deleted) badge if soft-deleted.
     *
     * Single source of truth shared by render_item_row and render_band_row so
     * the visual treatment of soft-deleted rows stays consistent. Reusable at
     * Phase 4 for any list rendering soft-deleted records.
     *
     * @param string $primarytext Already-formatted HTML or escaped plain string.
     * @param bool $deleted True when the row is soft-deleted.
     * @return string The primary text either unchanged, or wrapped in <s>
     *                with a (deleted) badge appended.
     */
    private function deleted_marker(string $primarytext, bool $deleted): string {
        if (!$deleted) {
            return $primarytext;
        }
        return html_writer::tag('s', $primarytext, ['class' => 'text-muted']) .
            html_writer::span(
                get_string('badge:deleted', 'mod_scorecard'),
                'badge bg-secondary text-white ms-2'
            );
    }

    /**
     * Render a single item row in the manage screen.
     *
     * Soft-deleted items get strikethrough + (deleted) badge via the shared
     * deleted_marker helper; action links are suppressed. Hidden (visible=0)
     * items get an additional (hidden) badge. Move-up and move-down links
     * are emitted only when a non-deleted neighbour exists on that side.
     *
     * @param stdClass $item Row from {scorecard_items}.
     * @param moodle_url|null $manageurl Manage page URL for action links.
     *                                   Pass null for read-only contexts (Phase 4 reports).
     * @param bool $canmoveup True if a non-deleted neighbour exists above.
     * @param bool $canmovedown True if a non-deleted neighbour exists below.
     * @return string Rendered HTML for the row.
     */
    public function render_item_row(
        stdClass $item,
        ?moodle_url $manageurl,
        bool $canmoveup,
        bool $canmovedown
    ): string {
        $deleted = !empty($item->deleted);
        $hidden = empty($item->visible);

        $promptdisplay = format_text($item->prompt, (int)$item->promptformat);
        $promptdisplay = $this->deleted_marker($promptdisplay, $deleted);

        $hiddenbadge = '';
        if (!$deleted && $hidden) {
            $hiddenbadge = html_writer::span(
                get_string('badge:hidden', 'mod_scorecard'),
                'badge bg-warning text-dark ms-2'
            );
        }

        $anchors = '';
        if (!empty($item->lowlabel) || !empty($item->highlabel)) {
            $low = format_string((string)($item->lowlabel ?? ''));
            $high = format_string((string)($item->highlabel ?? ''));
            $anchors = html_writer::div(
                $low . ' — ' . $high,
                'small text-muted mt-1'
            );
        }

        $actions = '';
        if (!$deleted && $manageurl !== null) {
            $sesskey = sesskey();
            $links = [];
            if ($canmoveup) {
                $links[] = html_writer::link(
                    new moodle_url($manageurl, ['action' => 'moveup', 'itemid' => $item->id, 'sesskey' => $sesskey]),
                    $this->pix_icon('t/up', get_string('item:moveup', 'mod_scorecard'))
                );
            }
            if ($canmovedown) {
                $links[] = html_writer::link(
                    new moodle_url($manageurl, ['action' => 'movedown', 'itemid' => $item->id, 'sesskey' => $sesskey]),
                    $this->pix_icon('t/down', get_string('item:movedown', 'mod_scorecard'))
                );
            }
            $links[] = html_writer::link(
                new moodle_url($manageurl, ['action' => 'edit', 'itemid' => $item->id]),
                $this->pix_icon('t/edit', get_string('item:edit', 'mod_scorecard'))
            );
            $links[] = html_writer::link(
                new moodle_url($manageurl, ['action' => 'delete', 'itemid' => $item->id, 'sesskey' => $sesskey]),
                $this->pix_icon('t/delete', get_string('item:delete', 'mod_scorecard'))
            );
            $actions = html_writer::div(implode(' ', $links), 'item-actions ms-3');
        }

        $body = html_writer::div($promptdisplay . $hiddenbadge . $anchors, 'item-body flex-grow-1');

        return html_writer::div(
            $body . $actions,
            'item-row d-flex flex-row justify-content-between align-items-start py-2 border-bottom'
        );
    }

    /**
     * Render the items list with empty-state and "Add an item" button.
     *
     * Move-up/down enablement is computed once over the non-deleted set sorted
     * by sortorder, then read per item by id. Soft-deleted items appear in
     * the rendered list at their original position but with no actions.
     *
     * @param array $items Item rows from {scorecard_items}, keyed by id.
     * @param moodle_url $manageurl Manage page URL for action links.
     * @return string Rendered HTML.
     */
    public function render_items_list(array $items, moodle_url $manageurl): string {
        $nondeleted = [];
        foreach ($items as $item) {
            if (empty($item->deleted)) {
                $nondeleted[] = $item;
            }
        }
        usort($nondeleted, function (stdClass $a, stdClass $b): int {
            return (int)$a->sortorder - (int)$b->sortorder;
        });

        $position = [];
        foreach ($nondeleted as $idx => $item) {
            $position[$item->id] = $idx;
        }
        $lastidx = count($nondeleted) - 1;

        $rows = [];
        foreach ($items as $item) {
            $idx = $position[$item->id] ?? null;
            $canmoveup = $idx !== null && $idx > 0;
            $canmovedown = $idx !== null && $idx < $lastidx;
            $rows[] = $this->render_item_row($item, $manageurl, $canmoveup, $canmovedown);
        }

        $body = $rows
            ? implode('', $rows)
            : html_writer::div(get_string('manage:items:empty', 'mod_scorecard'), 'text-muted py-2');

        $addbutton = html_writer::div(
            html_writer::link(
                new moodle_url($manageurl, ['action' => 'add']),
                get_string('item:add', 'mod_scorecard'),
                ['class' => 'btn btn-primary']
            ),
            'mt-3'
        );

        return $body . $addbutton;
    }

    /**
     * Render a single band row in the manage screen.
     *
     * Soft-deleted bands get strikethrough + (deleted) badge on the label via
     * the shared deleted_marker helper; action links are suppressed. Bands
     * have no hidden state (no visible flag in the schema) and no move-up /
     * move-down — they display by minscore ASC.
     *
     * @param stdClass $band Row from {scorecard_bands}.
     * @param moodle_url|null $manageurl Manage page URL for action links.
     *                                   Pass null for read-only contexts (Phase 4 reports).
     * @return string Rendered HTML for the row.
     */
    public function render_band_row(stdClass $band, ?moodle_url $manageurl): string {
        $deleted = !empty($band->deleted);

        $label = format_string((string)$band->label);
        $labeldisplay = $this->deleted_marker($label, $deleted);

        $range = html_writer::span(
            (int)$band->minscore . '–' . (int)$band->maxscore,
            'small text-muted ms-2'
        );

        $message = '';
        if (!empty($band->message)) {
            $message = html_writer::div(
                format_text($band->message, (int)$band->messageformat),
                'small text-muted mt-1'
            );
        }

        $actions = '';
        if (!$deleted && $manageurl !== null) {
            $sesskey = sesskey();
            $links = [
                html_writer::link(
                    new moodle_url($manageurl, ['action' => 'edit', 'bandid' => $band->id]),
                    $this->pix_icon('t/edit', get_string('band:edit', 'mod_scorecard'))
                ),
                html_writer::link(
                    new moodle_url($manageurl, ['action' => 'delete', 'bandid' => $band->id, 'sesskey' => $sesskey]),
                    $this->pix_icon('t/delete', get_string('band:delete', 'mod_scorecard'))
                ),
            ];
            $actions = html_writer::div(implode(' ', $links), 'item-actions ms-3');
        }

        $body = html_writer::div(
            html_writer::div($labeldisplay . $range, 'band-label-row') . $message,
            'item-body flex-grow-1'
        );

        return html_writer::div(
            $body . $actions,
            'item-row d-flex flex-row justify-content-between align-items-start py-2 border-bottom'
        );
    }

    /**
     * Render the bands list with empty-state and "Add a band" button.
     *
     * Bands display by minscore ASC (natural numeric order). Soft-deleted bands
     * appear at their natural minscore position with the (deleted) badge.
     *
     * @param array $bands Band rows from {scorecard_bands}, keyed by id.
     * @param moodle_url $manageurl Manage page URL for action links.
     * @return string Rendered HTML.
     */
    public function render_bands_list(array $bands, moodle_url $manageurl): string {
        $sorted = array_values($bands);
        usort($sorted, function (stdClass $a, stdClass $b): int {
            return (int)$a->minscore - (int)$b->minscore;
        });

        $rows = [];
        foreach ($sorted as $band) {
            $rows[] = $this->render_band_row($band, $manageurl);
        }

        $body = $rows
            ? implode('', $rows)
            : html_writer::div(get_string('manage:bands:empty', 'mod_scorecard'), 'text-muted py-2');

        $addbutton = html_writer::div(
            html_writer::link(
                new moodle_url($manageurl, ['action' => 'add']),
                get_string('band:add', 'mod_scorecard'),
                ['class' => 'btn btn-primary']
            ),
            'mt-3'
        );

        return $body . $addbutton;
    }
}
