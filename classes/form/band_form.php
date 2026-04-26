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
 * Result-band authoring form for mod_scorecard.
 *
 * Standard moodleform — NOT dynamic_form. Submission is handled server-side
 * by manage.php; no AJAX. Fields cover the SPEC §4.3 surface exposed in MVP.
 *
 * Validation surfaces three rules:
 * - Label non-empty (single-band self-consistency).
 * - minscore ≤ maxscore (single-band self-consistency).
 * - No overlap with sibling bands (cross-band, blocks save). Computed by
 *   scorecard_compute_band_coverage() in locallib.php; the form passes the
 *   proposed band values plus the current band id (when editing) to suppress
 *   self-overlap false positives.
 *
 * Gap detection is NOT a form-level error — gaps surface as a warning above
 * the bands list on manage.php's default Bands-tab render (per SPEC §4.3
 * "Gaps in coverage: Warning (not error)").
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\form;

use moodleform;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/scorecard/locallib.php');

/**
 * Add/edit form for a result band.
 */
class band_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'text',
            'minscore',
            get_string('band:minscore', 'mod_scorecard'),
            ['size' => 6, 'inputmode' => 'numeric']
        );
        $mform->setType('minscore', PARAM_INT);
        $mform->addRule('minscore', null, 'required', null, 'client');
        $mform->addRule('minscore', null, 'numeric', null, 'client');
        $mform->addHelpButton('minscore', 'band:minscore', 'mod_scorecard');

        $mform->addElement(
            'text',
            'maxscore',
            get_string('band:maxscore', 'mod_scorecard'),
            ['size' => 6, 'inputmode' => 'numeric']
        );
        $mform->setType('maxscore', PARAM_INT);
        $mform->addRule('maxscore', null, 'required', null, 'client');
        $mform->addRule('maxscore', null, 'numeric', null, 'client');
        $mform->addHelpButton('maxscore', 'band:maxscore', 'mod_scorecard');

        $mform->addElement(
            'text',
            'label',
            get_string('band:label', 'mod_scorecard'),
            ['size' => 30]
        );
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', null, 'required', null, 'client');
        $mform->addRule('label', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('label', 'band:label', 'mod_scorecard');

        $mform->addElement(
            'editor',
            'message_editor',
            get_string('band:message', 'mod_scorecard'),
            null,
            ['maxfiles' => 0, 'noclean' => false]
        );
        $mform->setType('message_editor', PARAM_RAW);
        $mform->addHelpButton('message_editor', 'band:message', 'mod_scorecard');

        $this->add_action_buttons(true, get_string('band:save', 'mod_scorecard'));
    }

    /**
     * Validate form data.
     *
     * Layered checks: self-consistency (label, minscore ≤ maxscore) runs first;
     * cross-band overlap detection runs only when self-consistency passes,
     * because overlap detection requires a well-formed proposed range.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files (unused; maxfiles is 0).
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $label = trim((string)($data['label'] ?? ''));
        if ($label === '') {
            $errors['label'] = get_string('band:error:labelempty', 'mod_scorecard');
        }

        $hasrange = isset($data['minscore'], $data['maxscore']);
        if ($hasrange && (int)$data['maxscore'] < (int)$data['minscore']) {
            $errors['maxscore'] = get_string('band:error:minmaxinvalid', 'mod_scorecard');
        }

        // Cross-band overlap check: only when this band is well-formed.
        if ($hasrange && empty($errors['maxscore']) && $label !== '') {
            $scorecardid = (int)$this->_customdata['scorecardid'];
            $excludebandid = $this->_customdata['bandid'] ?? null;

            $proposed = (object)[
                'label' => $label,
                'minscore' => (int)$data['minscore'],
                'maxscore' => (int)$data['maxscore'],
            ];

            $coverage = scorecard_compute_band_coverage(
                $scorecardid,
                $excludebandid,
                $proposed
            );

            $messages = [];
            foreach ($coverage['overlaps'] as $o) {
                // Only surface overlaps that involve the proposed band (id=0).
                // Pre-existing overlaps between other bands are not this save's
                // problem; they will surface separately when those bands edit.
                if ($o->a_id === 0) {
                    $other = (object)[
                        'min' => $o->overlap_min,
                        'max' => $o->overlap_max,
                        'otherlabel' => $o->b_label,
                        'othermin' => $o->b_min,
                        'othermax' => $o->b_max,
                    ];
                } else if ($o->b_id === 0) {
                    $other = (object)[
                        'min' => $o->overlap_min,
                        'max' => $o->overlap_max,
                        'otherlabel' => $o->a_label,
                        'othermin' => $o->a_min,
                        'othermax' => $o->a_max,
                    ];
                } else {
                    continue;
                }
                $messages[] = get_string('band:error:overlap', 'mod_scorecard', $other);
            }

            if (!empty($messages)) {
                $errors['maxscore'] = implode("\n", $messages);
            }
        }

        return $errors;
    }
}
