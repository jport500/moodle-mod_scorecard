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
 * Item authoring form for mod_scorecard.
 *
 * Standard moodleform — NOT dynamic_form. Submission is handled server-side
 * by manage.php via POST to a same-origin URL that carries action and itemid
 * as query params; no AJAX. Fields cover the SPEC §4.2 surface exposed in
 * MVP. The `required` field is reserved for v1.1 and not present here.
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
 * Add/edit form for a scorecard item.
 */
class item_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'editor',
            'prompt_editor',
            get_string('item:prompt', 'mod_scorecard'),
            null,
            ['maxfiles' => 0, 'noclean' => false]
        );
        $mform->setType('prompt_editor', PARAM_RAW);
        $mform->addRule('prompt_editor', null, 'required', null, 'client');
        $mform->addHelpButton('prompt_editor', 'item:prompt', 'mod_scorecard');

        $mform->addElement('text', 'lowlabel', get_string('item:lowlabel', 'mod_scorecard'), ['size' => 30]);
        $mform->setType('lowlabel', PARAM_TEXT);
        $mform->addHelpButton('lowlabel', 'item:lowlabel', 'mod_scorecard');

        $mform->addElement('text', 'highlabel', get_string('item:highlabel', 'mod_scorecard'), ['size' => 30]);
        $mform->setType('highlabel', PARAM_TEXT);
        $mform->addHelpButton('highlabel', 'item:highlabel', 'mod_scorecard');

        $mform->addElement('advcheckbox', 'visible', get_string('item:visible', 'mod_scorecard'));
        $mform->setDefault('visible', 1);
        $mform->addHelpButton('visible', 'item:visible', 'mod_scorecard');

        $this->add_action_buttons(true, get_string('item:save', 'mod_scorecard'));
    }

    /**
     * Validate form data.
     *
     * The required-rule on prompt_editor catches an entirely empty submission
     * client-side; this server-side check catches edge cases like a submission
     * containing only whitespace or HTML tags with no text content.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files (unused; maxfiles is 0).
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $prompt = (string)($data['prompt_editor']['text'] ?? '');
        if (trim(strip_tags($prompt)) === '') {
            $errors['prompt_editor'] = get_string('item:error:promptempty', 'mod_scorecard');
        }

        return $errors;
    }
}
