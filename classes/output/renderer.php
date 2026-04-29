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
     * Render the validation errors block on the import page.
     *
     * Phase 6.5: operator-readable list of fatal validation errors from
     * scorecard_template_validate. Each entry shows the dot-separated path
     * (so operator knows which field failed) and the lang-resolved message.
     * Block import with this rendering — the operator must fix the source
     * template externally and re-upload.
     *
     * @param array $errors List of `['path' => string, 'code' => string,
     *                       'message' => string]` entries from the validator.
     * @return string Rendered HTML.
     */
    public function render_template_validation_errors(array $errors): string {
        $items = [];
        foreach ($errors as $err) {
            $path = (string)($err['path'] ?? '');
            $msg = get_string((string)($err['message'] ?? ''), 'mod_scorecard');
            $body = $path !== ''
                ? html_writer::tag('code', s($path)) . ': ' . s($msg)
                : s($msg);
            $items[] = html_writer::tag('li', $body);
        }
        $list = html_writer::tag('ul', implode('', $items), ['class' => 'mb-0']);

        $heading = html_writer::tag(
            'h4',
            get_string('template:import:errors:heading', 'mod_scorecard'),
            ['class' => 'alert-heading']
        );
        $intro = html_writer::tag(
            'p',
            get_string('template:import:errors:intro', 'mod_scorecard')
        );

        return html_writer::div(
            $heading . $intro . $list,
            'alert alert-danger mb-3',
            ['role' => 'alert']
        );
    }

    /**
     * Render the validation warnings block.
     *
     * Phase 6.5: warnings are non-blocking by design (cross-version mismatch,
     * unknown fields ignored). Two display modes:
     * - $requireconfirmation = true (pre-import) — surfaces a "I understand
     *   these warnings" notice; operator's actual confirmation comes from
     *   the form's confirmwarnings hidden field, which the form manipulates
     *   via re-render. The notice instructs the operator to resubmit with
     *   the form below to acknowledge.
     * - $requireconfirmation = false (post-import success page) — shows the
     *   warnings as informational documentation of what was ignored on import.
     *
     * @param array $warnings List of `['path' => string, 'code' => string,
     *                        'message' => string]` entries from the validator.
     * @param bool $requireconfirmation True before import; false after.
     * @return string Rendered HTML.
     */
    public function render_template_validation_warnings(array $warnings, bool $requireconfirmation): string {
        $items = [];
        foreach ($warnings as $w) {
            $path = (string)($w['path'] ?? '');
            $msg = get_string((string)($w['message'] ?? ''), 'mod_scorecard');
            $body = $path !== ''
                ? html_writer::tag('code', s($path)) . ': ' . s($msg)
                : s($msg);
            $items[] = html_writer::tag('li', $body);
        }
        $list = html_writer::tag('ul', implode('', $items), ['class' => 'mb-2']);

        $heading = html_writer::tag(
            'h4',
            get_string('template:import:warnings:heading', 'mod_scorecard'),
            ['class' => 'alert-heading']
        );
        $intro = html_writer::tag(
            'p',
            get_string('template:import:warnings:intro', 'mod_scorecard')
        );

        $confirm = '';
        if ($requireconfirmation) {
            $confirm = html_writer::tag(
                'p',
                html_writer::tag(
                    'strong',
                    get_string('template:import:warnings:confirm', 'mod_scorecard')
                ),
                ['class' => 'mb-0 mt-2']
            );
        }

        return html_writer::div(
            $heading . $intro . $list . $confirm,
            'alert alert-warning mb-3',
            ['role' => 'alert']
        );
    }

    /**
     * Render the "Import template" affordance shown above the manage tabs
     * for an empty scorecard.
     *
     * Phase 6.5b: visibility is controlled by the caller (manage.php checks
     * item + band counts before invoking this method). When the scorecard
     * has any content, the affordance is suppressed entirely per Q-rework-2 —
     * import is meaningful only on the empty state.
     *
     * Styled identically to the export affordance (outline-secondary
     * btn-sm) so both authoring affordances read as sibling controls when
     * present together. (In practice they are mutually exclusive: empty
     * scorecards show only Import; populated scorecards show only Export.)
     *
     * @param int $cmid Course module id; the link target's cmid parameter.
     * @return string Rendered HTML.
     */
    public function render_template_import_affordance(int $cmid): string {
        $importurl = new moodle_url('/mod/scorecard/template_import.php', ['cmid' => $cmid]);
        return html_writer::div(
            html_writer::link(
                $importurl,
                get_string('template:import:empty:button', 'mod_scorecard'),
                [
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'title' => get_string('template:import:empty:tooltip', 'mod_scorecard'),
                ]
            ),
            'scorecard-template-import-affordance mb-3'
        );
    }

    /**
     * Render the warnings-state confirmation form.
     *
     * Phase 6.5b Q-rework-5: when the orchestration helper returns
     * `state='warnings'`, the endpoint surfaces this form below the warnings
     * block. Operator clicks "Yes, import anyway" to acknowledge and proceed;
     * the form posts back to the same endpoint with `confirmwarnings=1` and
     * the original JSON content base64-encoded in a hidden field, avoiding
     * a re-upload round-trip.
     *
     * Templates are capped at 1 MB by the form (filepicker maxbytes); base64
     * encoding adds ~33% overhead so the worst-case hidden-field payload is
     * ~1.3 MB, well within Moodle's default form input size. If a future
     * scaling question surfaces, course-correct to $SESSION-stored
     * intermediate state or re-upload-required-after-warnings flow.
     *
     * sesskey field defends the confirmation surface from CSRF — confirmation
     * is a "yes proceed with this side-effect" action and warrants the same
     * CSRF discipline as the items / bands authoring forms.
     *
     * @param int $cmid Course module id; preserved across the round-trip.
     * @param string $rawjson Raw JSON content from the original upload;
     *                         base64-encoded into a hidden field.
     * @return string Rendered HTML form.
     */
    public function render_template_warnings_confirmation_form(int $cmid, string $rawjson): string {
        $action = (new moodle_url('/mod/scorecard/template_import.php'))->out(false);
        $cancelurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cmid]);

        $hidden = html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'cmid',
            'value' => (string)$cmid,
        ]) . html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'pendingjson',
            'value' => base64_encode($rawjson),
        ]) . html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'confirmwarnings',
            'value' => '1',
        ]) . html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);

        $confirmbutton = html_writer::tag(
            'button',
            get_string('template:import:warnings:confirmbutton', 'mod_scorecard'),
            ['type' => 'submit', 'class' => 'btn btn-warning']
        );

        $cancellink = html_writer::link(
            $cancelurl,
            get_string('template:import:warnings:cancellabel', 'mod_scorecard'),
            ['class' => 'btn btn-link ms-2']
        );

        return html_writer::tag(
            'form',
            $hidden . html_writer::div(
                $confirmbutton . $cancellink,
                'scorecard-template-warnings-actions'
            ),
            [
                'method' => 'post',
                'action' => $action,
                'class' => 'scorecard-template-warnings-confirmation mb-3',
            ]
        );
    }

    /**
     * Render the "Export template" affordance shown above the manage tabs.
     *
     * Phase 6.1: top-level operation surfaced from any tab on manage.php
     * because templates encompass items + bands + settings together. Placed
     * above the tab tree so the affordance reads as a scorecard-level action,
     * not an items- or bands-only one. Styled outline-secondary to match the
     * "Manage scorecard" affordance on view.php (sibling small-secondary
     * action class) — neither competes with the primary authoring buttons
     * inside each tab.
     *
     * @param int $cmid Course module id; the link target's id parameter.
     * @return string Rendered HTML.
     */
    public function render_template_export_affordance(int $cmid): string {
        $exporturl = new moodle_url('/mod/scorecard/template_export.php', ['id' => $cmid]);
        return html_writer::div(
            html_writer::link(
                $exporturl,
                get_string('template:export:button', 'mod_scorecard'),
                [
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'title' => get_string('template:export:tooltip', 'mod_scorecard'),
                ]
            ),
            'scorecard-template-export-affordance mb-3'
        );
    }

    /**
     * Render the persistent "Manage scorecard" affordance for view.php.
     *
     * view.php's capability-ordered branching routes the submit-cap branch
     * before the manage-cap branch. Site admins (and editing-teachers who
     * also satisfy :submit) hit the learner branch and -- without an affordance
     * here -- have no path back to authoring on the populated form, the result
     * page, the retake callout, or the empty state. This single helper covers
     * every learner-facing render path: view.php emits it ONCE above the entire
     * submit-capable block when the viewer also has :manage, so the affordance
     * is consistent regardless of which leaf renders below.
     *
     * Styled secondary/outline so it does not compete visually with the primary
     * learner action (Submit button on the form, expand-detail summary on the
     * result page). The empty-state retains its inline directive button as well
     * (belt + suspenders for fresh activities; the directive copy "Add items and
     * result bands" is more actionable when there is literally nothing to score).
     *
     * @param int $cmid Course module id; the link target's id parameter.
     * @return string Rendered HTML.
     */
    public function render_manage_affordance(int $cmid): string {
        $manageurl = new moodle_url('/mod/scorecard/manage.php', [
            'id' => $cmid,
            'tab' => 'items',
        ]);
        return html_writer::div(
            html_writer::link(
                $manageurl,
                get_string('view:manage_affordance', 'mod_scorecard'),
                ['class' => 'btn btn-outline-secondary btn-sm']
            ),
            'scorecard-manage-affordance mb-3'
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
     * Render the friendly "no attempts yet" notice.
     *
     * Used by report.php when scorecard_get_attempts() returns an empty array.
     * Distinct from the manage screen's items / bands empty states because the
     * call to action here is "wait for learners," not "add more authoring."
     *
     * Phase 4.3 added the $filtered flag for the group-filter empty case: when
     * the operator selects a specific group and that group has no attempts, the
     * generic "no attempts yet" copy reads slightly wrong (the scorecard may
     * have plenty of attempts, just none in that group). The selector above
     * the notice already shows the active group name, so the filtered copy
     * stays generic ("No attempts in the selected group.") rather than
     * duplicating the group name in the message body.
     *
     * @param bool $filtered True when a group filter is active and produced an
     *                       empty result. Default false preserves the pre-4.3
     *                       single-arg call shape.
     * @return string Rendered HTML notice.
     */
    public function render_report_empty_state(bool $filtered = false): string {
        $key = $filtered ? 'report:empty:filtered' : 'report:empty';
        return html_writer::div(
            get_string($key, 'mod_scorecard'),
            'scorecard-report-empty alert alert-info'
        );
    }

    /**
     * Render the expandable per-attempt detail block for the report table.
     *
     * Native HTML5 `<details>` element wraps a list of per-item response prose.
     * Keyboard-accessible (Enter/Space toggles), screen-reader-friendly, no JS,
     * no ARIA additions needed (Phase 4 kickoff Q3 + pre-flag #2). One `<p>` per
     * response inside a `<div>` body; summary copy reports the response count.
     *
     * Item prompt is read live from `{scorecard_items}` (joined by the helper),
     * NOT from a snapshot column on `{scorecard_responses}`. SPEC §11.2's snapshot
     * rule applies to scoring (totalscore, maxscore, percentage) and band display
     * (bandlabelsnapshot, bandmessagesnapshot, bandmessageformatsnapshot), not to
     * item prompt text. This matches Phase 3.4's result-page behavior — both
     * surfaces show current prompt text alongside snapshotted scoring/band values.
     * If a teacher edits an item's prompt after submissions exist, both the result
     * page AND the report's detail rows reflect the new prompt. A schema change
     * to add a per-response prompt snapshot is a v1.x enhancement (followup #21);
     * not pursued here because the audit-fidelity gap is small in practice and
     * uniformity with the result page outweighs it.
     *
     * Soft-deleted items (item.deleted = 1) render with a `[deleted]` prefix on
     * the prompt and the whole line de-emphasized via Bootstrap utility classes
     * (text-muted + fst-italic). The deleted_marker helper used elsewhere wraps
     * primary text in `<s>` strikethrough; that doesn't compose well with the
     * prose-with-bold-label shape here, so the visual treatment is rolled inline
     * specifically for this context.
     *
     * Out-of-range responses (responsevalue outside [scalemin, scalemax]) get
     * a red suffix flag. SPEC §4.5 + scorecard_scale_change_allowed() block scale
     * changes once attempts exist, so the only sources of out-of-range values are
     * direct DB tampering or backup/restore mismatches. Defensive flagging is
     * still valuable for audit. Closes followup #14.
     *
     * @param \stdClass $scorecard Scorecard config row -- scalemin / scalemax
     *                             read for the out-of-range comparison and the
     *                             "of {scalemax}" suffix in the response copy.
     * @param \stdClass $attempt Attempt row (currently passed for future use; the
     *                           4.2 implementation does not read fields off it,
     *                           but accepting it keeps the signature stable as
     *                           4.4 / 4.5 evolve the detail block).
     * @param array $responses Response rows from scorecard_get_attempt_responses;
     *                         each row has responsevalue plus joined item fields
     *                         (prompt, promptformat, deleted, sortorder). Already
     *                         ordered by sortorder ASC.
     * @return string Rendered HTML.
     */
    public function render_attempt_detail(
        \stdClass $scorecard,
        \stdClass $attempt,
        array $responses
    ): string {
        unset($attempt);

        $scalemin = (int)$scorecard->scalemin;
        $scalemax = (int)$scorecard->scalemax;
        $count = count($responses);

        $summary = html_writer::tag(
            'summary',
            get_string('report:detail:summary', 'mod_scorecard', $count)
        );

        $rowshtml = '';
        foreach ($responses as $row) {
            $value = (int)$row->responsevalue;
            $isdeleted = !empty($row->deleted);
            $outofrange = ($value < $scalemin || $value > $scalemax);

            // The format_string call strips block-level tags so the prompt
            // composes cleanly inside <strong>. Reports are an audit-context
            // label; rich formatting belongs on the learner-facing result page.
            $promptdisplay = format_string((string)($row->prompt ?? ''));
            if ($isdeleted) {
                $promptdisplay = get_string('report:detail:deletedprefix', 'mod_scorecard') . $promptdisplay;
            }
            $prompthtml = html_writer::tag('strong', $promptdisplay);

            $responsecopy = get_string('report:detail:response', 'mod_scorecard', (object)[
                'value' => $value,
                'scalemax' => $scalemax,
            ]);

            $rangesuffix = '';
            if ($outofrange) {
                $rangesuffix = html_writer::span(
                    get_string('report:detail:outofrange', 'mod_scorecard', (object)[
                        'min' => $scalemin,
                        'max' => $scalemax,
                    ]),
                    'text-danger'
                );
            }

            $itemclass = 'scorecard-attempt-detail-item';
            if ($isdeleted) {
                $itemclass .= ' is-deleted text-muted fst-italic';
            }

            $rowshtml .= html_writer::tag(
                'p',
                $prompthtml . ': ' . $responsecopy . $rangesuffix,
                ['class' => $itemclass]
            );
        }

        $body = $rowshtml === ''
            ? ''
            : html_writer::div($rowshtml, 'scorecard-attempt-detail-body');

        return html_writer::tag(
            'details',
            $summary . $body,
            ['class' => 'scorecard-attempt-detail']
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
