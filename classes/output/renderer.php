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
 * Each method builds an associative context array and delegates the markup
 * to a Mustache template under templates/. Action-link clusters that compose
 * pix_icon() output are pre-rendered in PHP and passed into the templates as
 * raw HTML for the row-level layouts -- the icon helper is a Moodle output
 * API, not author-written markup.
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
     * Render a single item row in the manage screen.
     *
     * @param stdClass $item Row from {scorecard_items}.
     * @param moodle_url|null $manageurl Manage page URL for action links;
     *                                   null in read-only contexts (Phase 4 reports).
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

        $hasanchors = (!empty($item->lowlabel) || !empty($item->highlabel));

        $hasactions = !$deleted && $manageurl !== null;
        $actionshtml = $hasactions
            ? $this->build_item_action_cluster($item, $manageurl, $canmoveup, $canmovedown)
            : '';

        return $this->render_from_template('mod_scorecard/item_row', [
            'prompt' => format_text($item->prompt, (int)$item->promptformat),
            'deleted' => $deleted,
            'deletedbadge' => get_string('badge:deleted', 'mod_scorecard'),
            'showhiddenbadge' => !$deleted && $hidden,
            'hiddenbadge' => get_string('badge:hidden', 'mod_scorecard'),
            'hasanchors' => $hasanchors,
            'anchorlow' => $hasanchors ? format_string((string)($item->lowlabel ?? '')) : '',
            'anchorhigh' => $hasanchors ? format_string((string)($item->highlabel ?? '')) : '',
            'hasactions' => $hasactions,
            'actionshtml' => $actionshtml,
        ]);
    }

    /**
     * Compose the pix-icon action-link cluster for a manage-screen item row.
     *
     * Uses Moodle's pix_icon() output API (not author-written markup) so the
     * cluster is built in PHP and passed into the row template as raw HTML.
     *
     * @param stdClass $item
     * @param moodle_url $manageurl
     * @param bool $canmoveup
     * @param bool $canmovedown
     * @return string
     */
    private function build_item_action_cluster(
        stdClass $item,
        moodle_url $manageurl,
        bool $canmoveup,
        bool $canmovedown
    ): string {
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
        return implode(' ', $links);
    }

    /**
     * Render the items list with empty-state and "Add an item" button.
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

        $rowshtml = '';
        foreach ($items as $item) {
            $idx = $position[$item->id] ?? null;
            $canmoveup = $idx !== null && $idx > 0;
            $canmovedown = $idx !== null && $idx < $lastidx;
            $rowshtml .= $this->render_item_row($item, $manageurl, $canmoveup, $canmovedown);
        }

        return $this->render_from_template('mod_scorecard/items_list', [
            'hasrows' => $rowshtml !== '',
            'rowshtml' => $rowshtml,
            'emptylabel' => get_string('manage:items:empty', 'mod_scorecard'),
            'addurl' => (new moodle_url($manageurl, ['action' => 'add']))->out(false),
            'addlabel' => get_string('item:add', 'mod_scorecard'),
        ]);
    }

    /**
     * Render a single band row in the manage screen.
     *
     * @param stdClass $band Row from {scorecard_bands}.
     * @param moodle_url|null $manageurl Manage page URL for action links;
     *                                   null in read-only contexts (Phase 4 reports).
     * @return string Rendered HTML for the row.
     */
    public function render_band_row(stdClass $band, ?moodle_url $manageurl): string {
        $deleted = !empty($band->deleted);
        $hasmessage = !empty($band->message);

        $hasactions = !$deleted && $manageurl !== null;
        $actionshtml = $hasactions
            ? $this->build_band_action_cluster($band, $manageurl)
            : '';

        return $this->render_from_template('mod_scorecard/band_row', [
            'label' => format_string((string)$band->label),
            'deleted' => $deleted,
            'deletedbadge' => get_string('badge:deleted', 'mod_scorecard'),
            'rangetext' => (int)$band->minscore . '–' . (int)$band->maxscore,
            'hasmessage' => $hasmessage,
            'messagehtml' => $hasmessage
                ? format_text($band->message, (int)$band->messageformat)
                : '',
            'hasactions' => $hasactions,
            'actionshtml' => $actionshtml,
        ]);
    }

    /**
     * Compose the pix-icon action-link cluster for a manage-screen band row.
     *
     * @param stdClass $band
     * @param moodle_url $manageurl
     * @return string
     */
    private function build_band_action_cluster(stdClass $band, moodle_url $manageurl): string {
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
        return implode(' ', $links);
    }

    /**
     * Render the bands list with empty-state and "Add a band" button.
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

        $rowshtml = '';
        foreach ($sorted as $band) {
            $rowshtml .= $this->render_band_row($band, $manageurl);
        }

        return $this->render_from_template('mod_scorecard/bands_list', [
            'hasrows' => $rowshtml !== '',
            'rowshtml' => $rowshtml,
            'emptylabel' => get_string('manage:bands:empty', 'mod_scorecard'),
            'addurl' => (new moodle_url($manageurl, ['action' => 'add']))->out(false),
            'addlabel' => get_string('band:add', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the validation errors block on the import page.
     *
     * @param array $errors List of `['path' => string, 'code' => string,
     *                       'message' => string]` entries from the validator.
     * @return string Rendered HTML.
     */
    public function render_template_validation_errors(array $errors): string {
        $rows = [];
        foreach ($errors as $err) {
            $path = (string)($err['path'] ?? '');
            $rows[] = [
                'haspath' => $path !== '',
                'path' => $path,
                'message' => get_string((string)($err['message'] ?? ''), 'mod_scorecard'),
            ];
        }
        return $this->render_from_template('mod_scorecard/template_validation_errors', [
            'heading' => get_string('template:import:errors:heading', 'mod_scorecard'),
            'intro' => get_string('template:import:errors:intro', 'mod_scorecard'),
            'errors' => $rows,
        ]);
    }

    /**
     * Render the validation warnings block.
     *
     * @param array $warnings List of `['path' => string, 'code' => string,
     *                        'message' => string]` entries from the validator.
     * @param bool $requireconfirmation True before import; false after.
     * @return string Rendered HTML.
     */
    public function render_template_validation_warnings(array $warnings, bool $requireconfirmation): string {
        $rows = [];
        foreach ($warnings as $w) {
            $path = (string)($w['path'] ?? '');
            $rows[] = [
                'haspath' => $path !== '',
                'path' => $path,
                'message' => get_string((string)($w['message'] ?? ''), 'mod_scorecard'),
            ];
        }
        return $this->render_from_template('mod_scorecard/template_validation_warnings', [
            'heading' => get_string('template:import:warnings:heading', 'mod_scorecard'),
            'intro' => get_string('template:import:warnings:intro', 'mod_scorecard'),
            'warnings' => $rows,
            'requireconfirmation' => $requireconfirmation,
            'confirmtext' => get_string('template:import:warnings:confirm', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the "Import template" affordance for the empty-state manage screen.
     *
     * @param int $cmid Course module id; the link target's cmid parameter.
     * @return string Rendered HTML.
     */
    public function render_template_import_affordance(int $cmid): string {
        return $this->render_from_template('mod_scorecard/template_import_affordance', [
            'importurl' => (new moodle_url('/mod/scorecard/template_import.php', ['cmid' => $cmid]))->out(false),
            'label' => get_string('template:import:empty:button', 'mod_scorecard'),
            'tooltip' => get_string('template:import:empty:tooltip', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the warnings-state confirmation form for the import endpoint.
     *
     * @param int $cmid Course module id; preserved across the round-trip.
     * @param string $rawjson Raw JSON content from the original upload;
     *                         base64-encoded into a hidden field.
     * @return string Rendered HTML form.
     */
    public function render_template_warnings_confirmation_form(int $cmid, string $rawjson): string {
        return $this->render_from_template('mod_scorecard/template_warnings_confirmation_form', [
            'action' => (new moodle_url('/mod/scorecard/template_import.php'))->out(false),
            'cmid' => $cmid,
            'pendingjson' => base64_encode($rawjson),
            'sesskey' => sesskey(),
            'cancelurl' => (new moodle_url('/mod/scorecard/manage.php', ['id' => $cmid]))->out(false),
            'confirmlabel' => get_string('template:import:warnings:confirmbutton', 'mod_scorecard'),
            'cancellabel' => get_string('template:import:warnings:cancellabel', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the "Export template" affordance shown above the manage tabs.
     *
     * @param int $cmid Course module id; the link target's id parameter.
     * @return string Rendered HTML.
     */
    public function render_template_export_affordance(int $cmid): string {
        return $this->render_from_template('mod_scorecard/template_export_affordance', [
            'exporturl' => (new moodle_url('/mod/scorecard/template_export.php', ['id' => $cmid]))->out(false),
            'label' => get_string('template:export:button', 'mod_scorecard'),
            'tooltip' => get_string('template:export:tooltip', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the persistent "Manage scorecard" affordance for view.php.
     *
     * @param int $cmid Course module id; the link target's id parameter.
     * @return string Rendered HTML.
     */
    public function render_manage_affordance(int $cmid): string {
        return $this->render_from_template('mod_scorecard/manage_affordance', [
            'manageurl' => (new moodle_url('/mod/scorecard/manage.php', [
                'id' => $cmid,
                'tab' => 'items',
            ]))->out(false),
            'label' => get_string('view:manage_affordance', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the manager-only "no items yet" notice for view.php.
     *
     * Used when the viewer holds :manage but not :submit. Wraps the inner
     * template fragment in $OUTPUT->box() so the manager surface matches the
     * generalbox treatment used elsewhere on view.php.
     *
     * @param int $cmid Course module id.
     * @return string Rendered HTML.
     */
    public function render_manager_no_items(int $cmid): string {
        $manageurl = new moodle_url('/mod/scorecard/manage.php', ['id' => $cmid]);
        $inner = $this->render_from_template('mod_scorecard/manager_no_items_link', [
            'body' => get_string('view:noitems_manager', 'mod_scorecard'),
            'manageurl' => $manageurl->out(false),
            'linklabel' => get_string('view:manageitemslink', 'mod_scorecard'),
        ]);
        return $this->output->box($inner, 'generalbox');
    }

    /**
     * Render the "Export CSV" action button for the report page.
     *
     * @param int $cmid Course module id.
     * @return string Rendered HTML.
     */
    public function render_report_export_button(int $cmid): string {
        return $this->render_from_template('mod_scorecard/report_export_button', [
            'exporturl' => (new moodle_url('/mod/scorecard/export.php', ['id' => $cmid]))->out(false),
            'label' => get_string('report:export:button', 'mod_scorecard'),
        ]);
    }

    /**
     * Render the learner submission form.
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
            $fieldsets[] = [
                'html' => $this->render_learner_form_item(
                    $item,
                    $scalemin,
                    $scalemax,
                    $globallow,
                    $globalhigh,
                    $preselected[$item->id] ?? null,
                    $errors[$item->id] ?? null
                ),
            ];
        }

        return $this->render_from_template('mod_scorecard/learner_form', [
            'action' => (new moodle_url('/mod/scorecard/submit.php'))->out(false),
            'cmid' => $cmid,
            'sesskey' => sesskey(),
            'fieldsets' => $fieldsets,
            'submitlabel' => get_string('submit:button', 'mod_scorecard'),
        ]);
    }

    /**
     * Render a single item fieldset for the learner form.
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
        $haslowanchor = $low !== '';
        $hashighanchor = $high !== '';

        $radios = [];
        for ($value = $scalemin; $value <= $scalemax; $value++) {
            // Negative values use an "n"-prefix in the radio id so the resulting
            // id (e.g. "scorecard-r-7-n3") avoids the "--" sequence that would
            // otherwise appear with raw negative integers.
            $radioid = "scorecard-r-{$itemid}-" . ($value < 0 ? 'n' . abs($value) : (string)$value);
            $describedby = '';
            if ($value === $scalemin && $haslowanchor) {
                $describedby = $lowanchorid;
            }
            if ($value === $scalemax && $hashighanchor) {
                $describedby = $describedby !== ''
                    ? $describedby . ' ' . $highanchorid
                    : $highanchorid;
            }
            $radios[] = [
                'id' => $radioid,
                'name' => "response[{$itemid}]",
                'value' => $value,
                'checked' => ($selectedvalue !== null && (int)$selectedvalue === $value),
                'hasdescribedby' => $describedby !== '',
                'describedby' => $describedby,
            ];
        }

        return $this->render_from_template('mod_scorecard/learner_form_item', [
            'itemid' => $itemid,
            'prompthtml' => format_text($item->prompt, (int)$item->promptformat),
            'haserror' => ($error !== null && $error !== ''),
            'errortext' => (string)$error,
            'haslowanchor' => $haslowanchor,
            'lowanchorid' => $lowanchorid,
            'lowtext' => $haslowanchor ? format_string($low) : '',
            'hashighanchor' => $hashighanchor,
            'highanchorid' => $highanchorid,
            'hightext' => $hashighanchor ? format_string($high) : '',
            'radios' => $radios,
        ]);
    }

    /**
     * Render the empty-state notice when the scorecard has no visible items.
     *
     * @param bool $canmanage True when the viewer also has mod/scorecard:manage.
     * @param int|null $cmid Course module id; required when $canmanage is true.
     * @return string Rendered HTML notice.
     */
    public function render_learner_no_items(bool $canmanage = false, ?int $cmid = null): string {
        $showbutton = $canmanage && $cmid !== null;
        return $this->render_from_template('mod_scorecard/learner_no_items', [
            'body' => get_string('view:noitems_learner', 'mod_scorecard'),
            'showmanagebutton' => $showbutton,
            'manageurl' => $showbutton
                ? (new moodle_url('/mod/scorecard/manage.php', ['id' => $cmid, 'tab' => 'items']))->out(false)
                : '',
            'managelabel' => $showbutton
                ? get_string('view:manageitemslink', 'mod_scorecard')
                : '',
        ]);
    }

    /**
     * Render the learner result page: snapshotted score, band heading +
     * message, optional percentage, optional item summary.
     *
     * Reads ONLY from the snapshotted columns on the attempt row -- never
     * JOINs to live bands -- per SPEC §11.2 result stability rule.
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
        $headline = get_string('result:headline', 'mod_scorecard', (object)[
            'totalscore' => (int)$attempt->totalscore,
            'maxscore' => (int)$attempt->maxscore,
        ]);

        $showpercentage = !empty($scorecard->showpercentage);
        $percentage = '';
        if ($showpercentage) {
            // Default round() rounds half away from zero, matching operator
            // expectation: 66.5 displays as 67.
            $rounded = (int)round((float)$attempt->percentage);
            $percentage = get_string('result:percentage', 'mod_scorecard', $rounded);
        }

        $hasbandlabel = !empty($attempt->bandlabelsnapshot);
        $bandlabel = $hasbandlabel ? format_string((string)$attempt->bandlabelsnapshot) : '';
        $hasbandmessage = !empty($attempt->bandmessagesnapshot);
        $bandmessage = $hasbandmessage
            ? format_text(
                (string)$attempt->bandmessagesnapshot,
                (int)$attempt->bandmessageformatsnapshot
            )
            : '';

        $summaryitems = [];
        $showsummary = false;
        $summaryheading = '';
        if (!empty($scorecard->showitemsummary)) {
            $sorted = array_values($items);
            usort($sorted, function (\stdClass $a, \stdClass $b): int {
                return (int)$a->sortorder - (int)$b->sortorder;
            });
            foreach ($sorted as $item) {
                $itemid = (int)$item->id;
                if (!array_key_exists($itemid, $responses)) {
                    continue;
                }
                $promptdisplay = format_text((string)$item->prompt, (int)$item->promptformat);
                if (!empty($item->deleted)) {
                    // Inline marker matches the html_writer composition the
                    // tests pin (single <s ...> with adjacent badge span).
                    $promptdisplay = '<s class="text-muted">' . $promptdisplay . '</s>'
                        . '<span class="badge bg-secondary text-white ms-2">'
                        . get_string('badge:deleted', 'mod_scorecard')
                        . '</span>';
                }
                $summaryitems[] = [
                    'prompt' => $promptdisplay,
                    'value' => get_string(
                        'result:item_value',
                        'mod_scorecard',
                        (int)$responses[$itemid]
                    ),
                ];
            }
            if ($summaryitems) {
                $showsummary = true;
                $summaryheading = get_string('result:itemsummary_heading', 'mod_scorecard');
            }
        }

        return $this->render_from_template('mod_scorecard/result_page', [
            'headline' => $headline,
            'showpercentage' => $showpercentage,
            'percentage' => $percentage,
            'hasbandlabel' => $hasbandlabel,
            'bandlabel' => $bandlabel,
            'hasbandmessage' => $hasbandmessage,
            'bandmessage' => $bandmessage,
            'showsummary' => $showsummary,
            'summaryheading' => $summaryheading,
            'items' => $summaryitems,
        ]);
    }

    /**
     * Render a compact previous-attempt summary above the form on retake.
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

        return $this->render_from_template('mod_scorecard/previous_attempt_callout', [
            'headline' => get_string('retake:previousattempt:headline', 'mod_scorecard'),
            'body' => $body,
        ]);
    }

    /**
     * Render the friendly "no attempts yet" notice on report.php.
     *
     * @param bool $filtered True when a group filter is active and produced an empty result.
     * @return string Rendered HTML notice.
     */
    public function render_report_empty_state(bool $filtered = false): string {
        $key = $filtered ? 'report:empty:filtered' : 'report:empty';
        return $this->render_from_template('mod_scorecard/report_empty_state', [
            'body' => get_string($key, 'mod_scorecard'),
        ]);
    }

    /**
     * Render the expandable per-attempt detail block for the report table.
     *
     * Soft-deleted items render with a `[deleted]` prefix and de-emphasised
     * styling (text-muted + fst-italic). Out-of-range responses (outside
     * [scalemin, scalemax]) get a red suffix flag for audit honesty.
     *
     * @param \stdClass $scorecard Scorecard config row (scalemin/scalemax read).
     * @param \stdClass $attempt Attempt row (currently passed for future use).
     * @param array $responses Response rows from scorecard_get_attempt_responses;
     *                         each row has responsevalue plus joined item fields.
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

        $rows = [];
        foreach ($responses as $row) {
            $value = (int)$row->responsevalue;
            $isdeleted = !empty($row->deleted);
            $outofrange = ($value < $scalemin || $value > $scalemax);

            // The format_string() call strips block-level tags so the prompt
            // composes cleanly inside <strong>. Reports are an audit-context label.
            $promptdisplay = format_string((string)($row->prompt ?? ''));
            if ($isdeleted) {
                $promptdisplay = get_string('report:detail:deletedprefix', 'mod_scorecard') . $promptdisplay;
            }

            $rows[] = [
                'isdeleted' => $isdeleted,
                'prompthtml' => $promptdisplay,
                'responsecopy' => get_string('report:detail:response', 'mod_scorecard', (object)[
                    'value' => $value,
                    'scalemax' => $scalemax,
                ]),
                'outofrange' => $outofrange,
                'outofrangetext' => $outofrange
                    ? get_string('report:detail:outofrange', 'mod_scorecard', (object)[
                        'min' => $scalemin,
                        'max' => $scalemax,
                    ])
                    : '',
            ];
        }

        return $this->render_from_template('mod_scorecard/attempt_detail', [
            'summary' => get_string('report:detail:summary', 'mod_scorecard', count($responses)),
            'hasrows' => $rows !== [],
            'rows' => $rows,
        ]);
    }
}
