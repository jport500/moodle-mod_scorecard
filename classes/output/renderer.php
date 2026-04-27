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
     * Render the learner submission form.
     *
     * One fieldset per item (prompt as legend), radio inputs from scalemin to
     * scalemax inclusive with name="response[itemid]" so PHP's $_POST arrives
     * as an associative array keyed by item id. Anchor labels render in spans
     * adjacent to the leftmost / rightmost radios; aria-describedby on those
     * radios pairs anchor text with the value for screen readers (SPEC §10.1
     * accessibility floor). Item iteration order matches the manage screen
     * (sortorder ASC) so a teacher debugging "why does my form look wrong"
     * sees the same order in both places.
     *
     * Form posts to submit.php (clean URL, cmid in body as a hidden field —
     * not in the query string). Sesskey hidden field added by Moodle's
     * \html_writer ::input or directly. On validation failure, the submit
     * handler re-renders this method with $preselected (itemid → value, to
     * mark the matching radio checked) and $errors (itemid → error string,
     * surfaced inline above the radio row of the offending fieldset). Both
     * default null for the first render.
     *
     * @param \stdClass $scorecard Scorecard row (scalemin, scalemax, lowlabel, highlabel).
     * @param array $items Visible non-deleted items keyed by id, sorted by sortorder ASC.
     * @param int $cmid Course module id (target of submit.php's coursemodule_from_id lookup).
     * @param array|null $preselected Optional itemid → value to pre-check the matching radio.
     * @param array|null $errors Optional itemid → error string for inline per-fieldset errors.
     * @return string Rendered HTML form.
     */
    public function render_learner_form(
        \stdClass $scorecard,
        array $items,
        int $cmid,
        ?array $preselected = null,
        ?array $errors = null
    ): string {
        $scalemin = (int)$scorecard->scalemin;
        $scalemax = (int)$scorecard->scalemax;
        $globallow = (string)($scorecard->lowlabel ?? '');
        $globalhigh = (string)($scorecard->highlabel ?? '');
        $preselected = $preselected ?? [];
        $errors = $errors ?? [];

        $fieldsets = [];
        foreach ($items as $item) {
            $fieldsets[] = $this->render_learner_form_item(
                $item,
                $scalemin,
                $scalemax,
                $globallow,
                $globalhigh,
                $preselected[$item->id] ?? null,
                $errors[$item->id] ?? null
            );
        }

        $hidden = html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'cmid',
            'value' => $cmid,
        ]) . html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);

        $submit = html_writer::tag(
            'button',
            get_string('submit:button', 'mod_scorecard'),
            ['type' => 'submit', 'class' => 'btn btn-primary mt-3']
        );

        return html_writer::tag(
            'form',
            $hidden . implode('', $fieldsets) . $submit,
            [
                'method' => 'post',
                'action' => (new moodle_url('/mod/scorecard/submit.php'))->out(false),
                'class' => 'scorecard-learner-form',
            ]
        );
    }

    /**
     * Render a single item fieldset for the learner form.
     *
     * Split out from render_learner_form for readability and to keep the
     * fieldset-level accessibility markup in one place: legend = prompt,
     * anchor spans hold ids referenced by aria-describedby on the leftmost
     * and rightmost radios, error notice rendered above the radio row when
     * present.
     *
     * @param \stdClass $item Item row (id, prompt, promptformat, lowlabel, highlabel).
     * @param int $scalemin Inclusive minimum radio value.
     * @param int $scalemax Inclusive maximum radio value.
     * @param string $globallow Activity-level low anchor (used when item-level is empty).
     * @param string $globalhigh Activity-level high anchor (used when item-level is empty).
     * @param int|string|null $selectedvalue Value to mark as checked, or null for none.
     * @param string|null $error Error string to render above the radio row, or null.
     * @return string Rendered HTML fieldset.
     */
    private function render_learner_form_item(
        \stdClass $item,
        int $scalemin,
        int $scalemax,
        string $globallow,
        string $globalhigh,
        $selectedvalue,
        ?string $error
    ): string {
        $itemid = (int)$item->id;
        $low = $item->lowlabel !== null && $item->lowlabel !== '' ? (string)$item->lowlabel : $globallow;
        $high = $item->highlabel !== null && $item->highlabel !== '' ? (string)$item->highlabel : $globalhigh;

        $lowanchorid = "scorecard-anchor-low-{$itemid}";
        $highanchorid = "scorecard-anchor-high-{$itemid}";

        $legend = html_writer::tag(
            'legend',
            format_text($item->prompt, (int)$item->promptformat),
            ['class' => 'scorecard-item-prompt']
        );

        $errorblock = '';
        if ($error !== null && $error !== '') {
            $errorblock = html_writer::div(
                s($error),
                'scorecard-item-error alert alert-danger py-1 px-2',
                ['role' => 'alert']
            );
        }

        $lowanchor = $low !== '' ? html_writer::span(
            format_string($low),
            'scorecard-anchor scorecard-anchor-low',
            ['id' => $lowanchorid]
        ) : '';
        $highanchor = $high !== '' ? html_writer::span(
            format_string($high),
            'scorecard-anchor scorecard-anchor-high',
            ['id' => $highanchorid]
        ) : '';

        $radios = [];
        for ($value = $scalemin; $value <= $scalemax; $value++) {
            // Negative values use an "n"-prefix in the radio id so the resulting
            // id (e.g. "scorecard-r-7-n3") avoids the "--" sequence that would
            // otherwise appear with raw negative integers ("scorecard-r-7--3").
            // Double-dash is legal in HTML ids but trips some CSS selector
            // implementations and looks like a typo to readers.
            $radioid = "scorecard-r-{$itemid}-" . ($value < 0 ? 'n' . abs($value) : (string)$value);
            $attrs = [
                'type' => 'radio',
                'name' => "response[{$itemid}]",
                'value' => (string)$value,
                'id' => $radioid,
                'class' => 'scorecard-radio-input',
            ];
            if ($selectedvalue !== null && (int)$selectedvalue === $value) {
                $attrs['checked'] = 'checked';
            }
            $describedby = [];
            if ($value === $scalemin && $lowanchor !== '') {
                $describedby[] = $lowanchorid;
            }
            if ($value === $scalemax && $highanchor !== '') {
                $describedby[] = $highanchorid;
            }
            if ($describedby) {
                $attrs['aria-describedby'] = implode(' ', $describedby);
            }
            $radios[] = html_writer::tag(
                'label',
                html_writer::empty_tag('input', $attrs) .
                    html_writer::span((string)$value, 'scorecard-radio-value'),
                ['class' => 'scorecard-radio-label', 'for' => $radioid]
            );
        }

        $radiorow = html_writer::div(
            $lowanchor . html_writer::div(implode('', $radios), 'scorecard-radio-group') . $highanchor,
            'scorecard-radio-row'
        );

        return html_writer::tag(
            'fieldset',
            $legend . $errorblock . $radiorow,
            [
                'class' => 'scorecard-item-fieldset',
                'data-itemid' => (string)$itemid,
            ]
        );
    }

    /**
     * Render the empty-state notice when the scorecard has no visible items.
     *
     * Used by view.php's submit-capable branch when scorecard_get_visible_items()
     * returns an empty array — typically because the teacher has not yet
     * authored any items, or all items are hidden as drafts.
     *
     * Site admins (and other users who satisfy both :submit and :manage) hit
     * view.php's submit branch first by capability ordering, so this method
     * surfaces a "manage scorecard" affordance when $canmanage is true. Without
     * the affordance, an admin creating a fresh scorecard would land on a
     * dead-end "isn't ready yet" notice with no path to the authoring screen.
     * The manager-only branch on view.php (no :submit capability) renders its
     * own copy + plain link and does not call this method.
     *
     * @param bool $canmanage True when the viewer also has mod/scorecard:manage.
     *                        Default false preserves the pre-fix learner-only behavior.
     * @param int|null $cmid Course module id; required when $canmanage is true so
     *                       the button can link to manage.php. Ignored otherwise.
     * @return string Rendered HTML notice.
     */
    public function render_learner_no_items(bool $canmanage = false, ?int $cmid = null): string {
        $body = get_string('view:noitems_learner', 'mod_scorecard');

        if ($canmanage && $cmid !== null) {
            $manageurl = new moodle_url('/mod/scorecard/manage.php', [
                'id' => $cmid,
                'tab' => 'items',
            ]);
            $body .= html_writer::div(
                html_writer::link(
                    $manageurl,
                    get_string('view:manageitemslink', 'mod_scorecard'),
                    ['class' => 'btn btn-primary']
                ),
                'mt-3'
            );
        }

        return html_writer::div(
            $body,
            'scorecard-noitems alert alert-info'
        );
    }

    /**
     * Render the placeholder shown when an attempt exists but the result
     * surface is not built yet.
     *
     * Render the learner result page: snapshotted score, band heading + message,
     * optional percentage, optional item summary.
     *
     * Reads ONLY from the snapshotted columns on the attempt row
     * (totalscore, maxscore, percentage, bandid, bandlabelsnapshot,
     * bandmessagesnapshot, bandmessageformatsnapshot). Never JOINs to live
     * bands -- SPEC §11.2 requires the result to remain stable as bands are
     * later edited or deleted.
     *
     * Conditional rendering:
     * - Headline always renders.
     * - Percentage renders only when $scorecard->showpercentage is truthy,
     *   rounded to integer for display.
     * - Band heading renders only when bandlabelsnapshot is non-empty.
     *   (On fallback, bandlabelsnapshot is null per the 3.2 contract.)
     * - Band message body renders only when bandmessagesnapshot is non-empty.
     *   The "matched band with empty message" case (label heading without
     *   body) is intentional UX, not a fallback fallthrough.
     * - Item summary renders only when $scorecard->showitemsummary is truthy.
     *   Wrapped in a collapsed <details> element.
     *
     * Item summary: $items contains the union of itemids referenced by the
     * attempt's response rows (audit-honest: includes items soft-deleted
     * between submit and revisit, rendered with the deleted_marker
     * strikethrough+badge so the learner sees what they actually answered).
     *
     * @param \stdClass $scorecard Scorecard config row (showpercentage, showitemsummary read).
     * @param \stdClass $attempt Attempt row with snapshotted scoring fields.
     * @param array $items Items keyed by id, including soft-deleted; sorted internally by sortorder.
     * @param array $responses Map of itemid => int response value (matches the items array).
     * @return string Rendered HTML.
     */
    public function render_result_page(
        \stdClass $scorecard,
        \stdClass $attempt,
        array $items,
        array $responses
    ): string {
        $headline = html_writer::tag(
            'h3',
            get_string('result:headline', 'mod_scorecard', (object)[
                'totalscore' => (int)$attempt->totalscore,
                'maxscore' => (int)$attempt->maxscore,
            ]),
            ['class' => 'scorecard-result-headline']
        );

        $percentageblock = '';
        if (!empty($scorecard->showpercentage)) {
            // Default round() in PHP rounds half away from zero, which is
            // intentional here: 66.5 displays as 67, matching operator expectation.
            $rounded = (int)round((float)$attempt->percentage);
            $percentageblock = html_writer::div(
                get_string('result:percentage', 'mod_scorecard', $rounded),
                'scorecard-result-percentage text-muted'
            );
        }

        $bandblock = '';
        if (!empty($attempt->bandlabelsnapshot)) {
            $bandblock .= html_writer::tag(
                'h4',
                format_string((string)$attempt->bandlabelsnapshot),
                ['class' => 'scorecard-result-band-label']
            );
        }
        if (!empty($attempt->bandmessagesnapshot)) {
            $bandblock .= html_writer::div(
                format_text(
                    (string)$attempt->bandmessagesnapshot,
                    (int)$attempt->bandmessageformatsnapshot
                ),
                'scorecard-result-band-message'
            );
        }

        $summaryblock = '';
        if (!empty($scorecard->showitemsummary)) {
            $sorted = array_values($items);
            usort($sorted, function (\stdClass $a, \stdClass $b): int {
                return (int)$a->sortorder - (int)$b->sortorder;
            });

            $rows = [];
            foreach ($sorted as $item) {
                $itemid = (int)$item->id;
                if (!array_key_exists($itemid, $responses)) {
                    continue;
                }
                $promptdisplay = format_text((string)$item->prompt, (int)$item->promptformat);
                $promptdisplay = $this->deleted_marker(
                    $promptdisplay,
                    !empty($item->deleted)
                );
                $rows[] = html_writer::div(
                    html_writer::div($promptdisplay, 'scorecard-result-item-prompt') .
                    html_writer::div(
                        get_string(
                            'result:item_value',
                            'mod_scorecard',
                            (int)$responses[$itemid]
                        ),
                        'scorecard-result-item-value text-muted'
                    ),
                    'scorecard-result-item'
                );
            }

            if ($rows) {
                $summaryblock = html_writer::tag(
                    'details',
                    html_writer::tag('summary', get_string('result:itemsummary_heading', 'mod_scorecard')) .
                    html_writer::div(implode('', $rows), 'scorecard-result-items'),
                    ['class' => 'scorecard-result-summary mt-3']
                );
            }
        }

        return html_writer::div(
            $headline . $percentageblock . $bandblock . $summaryblock,
            'scorecard-result-page'
        );
    }

    /**
     * Render a compact previous-attempt summary above the form on retake.
     *
     * Shown by view.php when allowretakes is on AND the user already has an
     * attempt, immediately above render_learner_form. Reads only snapshotted
     * fields on the attempt (timecreated, totalscore, maxscore,
     * bandlabelsnapshot) so a band edit between submit and retake does not
     * shift the displayed summary -- matches SPEC section 11.2's snapshot
     * stability rule for the result page.
     *
     * Bandlabelsnapshot is null on the fallback path; the noband lang string
     * substitutes in that case so the format reads cleanly without an empty
     * tail segment.
     *
     * @param \stdClass $attempt Attempt row (timecreated, totalscore, maxscore, bandlabelsnapshot).
     * @return string Rendered HTML.
     */
    public function render_previous_attempt_callout(\stdClass $attempt): string {
        $bandlabel = !empty($attempt->bandlabelsnapshot)
            ? format_string((string)$attempt->bandlabelsnapshot)
            : get_string('retake:previousattempt:noband', 'mod_scorecard');

        $body = get_string(
            'retake:previousattempt:format',
            'mod_scorecard',
            (object)[
                'date' => userdate((int)$attempt->timecreated),
                'totalscore' => (int)$attempt->totalscore,
                'maxscore' => (int)$attempt->maxscore,
                'band' => $bandlabel,
            ]
        );

        return html_writer::div(
            html_writer::tag(
                'strong',
                get_string('retake:previousattempt:headline', 'mod_scorecard')
            ) . ' &mdash; ' . $body,
            'scorecard-previous-attempt-callout alert alert-info'
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
