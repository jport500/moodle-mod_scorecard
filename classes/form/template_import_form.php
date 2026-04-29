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
 * Template import form for mod_scorecard.
 *
 * Phase 6.5b — operator-facing UI for JSON template upload onto an existing
 * empty scorecard. Operator already created the scorecard via standard "Add
 * an activity" workflow; this form populates items + bands.
 *
 * No section selector at this form (Q-reversal-3): the scorecard already
 * exists in its course section per the standard add-activity flow; the
 * imported items/bands attach to the existing scorecard, not a new one.
 *
 * Endpoint at template_import.php receives the form, extracts the file,
 * orchestrates validate + populate via scorecard_template_import_handle()
 * in locallib.php.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Upload form for mod_scorecard JSON templates (populate-existing path).
 */
class template_import_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'filepicker',
            'templatefile',
            get_string('template:import:fileupload', 'mod_scorecard'),
            null,
            [
                'accepted_types' => ['.json'],
                'maxbytes' => 1024 * 1024,
            ]
        );
        $mform->addRule('templatefile', null, 'required', null, 'client');
        $mform->addHelpButton('templatefile', 'template:import:fileupload', 'mod_scorecard');

        $this->add_action_buttons(true, get_string('template:import:submit', 'mod_scorecard'));
    }

    /**
     * Validate form submission.
     *
     * Defensive check that a file was uploaded. Content-level validation
     * (JSON parseability, schema correctness, empty-state precondition)
     * happens at the endpoint via scorecard_template_import_handle — keeping
     * content validation out of the form lets the endpoint surface
     * structured errors with paths, which the form's flat validation array
     * can't represent cleanly.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files (filepicker drafts go through draft area).
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($data['templatefile'])) {
            $errors['templatefile'] = get_string('required');
            return $errors;
        }
        $draftfiles = $this->get_draft_files('templatefile');
        if (!is_array($draftfiles) || count($draftfiles) < 1) {
            $errors['templatefile'] = get_string('required');
        }

        return $errors;
    }
}
