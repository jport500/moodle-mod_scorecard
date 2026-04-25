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
 * Activity settings form for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * mod_scorecard activity settings form.
 */
class mod_scorecard_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        // General header (name + intro).
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Rating scale settings.
        $mform->addElement('header', 'scaleheader', get_string('scaleheader', 'mod_scorecard'));
        $mform->setExpanded('scaleheader');

        $mform->addElement('text', 'scalemin', get_string('scalemin', 'mod_scorecard'), ['size' => 6]);
        $mform->setType('scalemin', PARAM_INT);
        $mform->setDefault('scalemin', 1);
        $mform->addHelpButton('scalemin', 'scalemin', 'mod_scorecard');

        $mform->addElement('text', 'scalemax', get_string('scalemax', 'mod_scorecard'), ['size' => 6]);
        $mform->setType('scalemax', PARAM_INT);
        $mform->setDefault('scalemax', 10);
        $mform->addHelpButton('scalemax', 'scalemax', 'mod_scorecard');

        // The 'displaystyle' field is locked to 'radio' in MVP. Schema
        // reserves 'buttons', 'dropdown', 'slider' for v1.1; the field
        // is hidden in the form per spec §4.1.
        $mform->addElement('hidden', 'displaystyle', 'radio');
        $mform->setType('displaystyle', PARAM_ALPHA);

        $mform->addElement('text', 'lowlabel', get_string('lowlabel', 'mod_scorecard'), ['size' => 30]);
        $mform->setType('lowlabel', PARAM_TEXT);
        $mform->setDefault('lowlabel', get_string('lowlabel_default', 'mod_scorecard'));
        $mform->addHelpButton('lowlabel', 'lowlabel', 'mod_scorecard');

        $mform->addElement('text', 'highlabel', get_string('highlabel', 'mod_scorecard'), ['size' => 30]);
        $mform->setType('highlabel', PARAM_TEXT);
        $mform->setDefault('highlabel', get_string('highlabel_default', 'mod_scorecard'));
        $mform->addHelpButton('highlabel', 'highlabel', 'mod_scorecard');

        // Result and submission settings.
        $mform->addElement('header', 'resultheader', get_string('resultheader', 'mod_scorecard'));
        $mform->setExpanded('resultheader');

        $mform->addElement('advcheckbox', 'allowretakes', get_string('allowretakes', 'mod_scorecard'));
        $mform->addHelpButton('allowretakes', 'allowretakes', 'mod_scorecard');
        $mform->setDefault('allowretakes', 0);

        $mform->addElement('advcheckbox', 'showresult', get_string('showresult', 'mod_scorecard'));
        $mform->addHelpButton('showresult', 'showresult', 'mod_scorecard');
        $mform->setDefault('showresult', 1);

        $mform->addElement('advcheckbox', 'showpercentage', get_string('showpercentage', 'mod_scorecard'));
        $mform->addHelpButton('showpercentage', 'showpercentage', 'mod_scorecard');
        $mform->setDefault('showpercentage', 0);

        $mform->addElement('advcheckbox', 'showitemsummary', get_string('showitemsummary', 'mod_scorecard'));
        $mform->addHelpButton('showitemsummary', 'showitemsummary', 'mod_scorecard');
        $mform->setDefault('showitemsummary', 1);

        $mform->addElement(
            'editor',
            'fallbackmessage_editor',
            get_string('fallbackmessage', 'mod_scorecard'),
            null,
            ['maxfiles' => 0, 'noclean' => false]
        );
        $mform->setType('fallbackmessage_editor', PARAM_RAW);
        $mform->addHelpButton('fallbackmessage_editor', 'fallbackmessage', 'mod_scorecard');

        // Gradebook integration.
        $mform->addElement('header', 'gradeheader', get_string('gradeheader', 'mod_scorecard'));

        $mform->addElement('advcheckbox', 'gradeenabled', get_string('gradeenabled', 'mod_scorecard'));
        $mform->addHelpButton('gradeenabled', 'gradeenabled', 'mod_scorecard');
        $mform->setDefault('gradeenabled', 0);

        $mform->addElement('text', 'grade', get_string('grade', 'mod_scorecard'), ['size' => 6]);
        $mform->setType('grade', PARAM_INT);
        $mform->setDefault('grade', 0);
        $mform->addHelpButton('grade', 'grade', 'mod_scorecard');
        $mform->disabledIf('grade', 'gradeenabled', 'notchecked');

        // Standard course-module elements (visibility, group mode, completion, etc.)
        // and standard buttons.
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Pre-populate fallbackmessage at activity-creation time only.
     *
     * Per spec §4.1.1 (Decision v0.2): the default fallback message is shipped
     * as a language string and pre-populated into the form field at activity
     * creation, so the teacher sees and can edit it from first save. Render-time
     * fallback to the lang string is invisible during authoring and is rejected
     * by the spec.
     *
     * Trigger: $this->_instance is empty (new instance) — NOT "fallbackmessage
     * is empty in defaults". The latter would re-fill the field on every edit
     * if a teacher had intentionally cleared it, defeating per-instance
     * customisation.
     *
     * @param array $defaultvalues Defaults array, mutated in place.
     */
    public function data_preprocessing(&$defaultvalues) {
        if (empty($this->_instance)) {
            $defaultvalues['fallbackmessage_editor'] = [
                'text' => get_string('fallbackmessage_default', 'mod_scorecard'),
                'format' => FORMAT_HTML,
            ];
        } else {
            $defaultvalues['fallbackmessage_editor'] = [
                'text' => $defaultvalues['fallbackmessage'] ?? '',
                'format' => $defaultvalues['fallbackmessageformat'] ?? FORMAT_HTML,
            ];
        }
    }

    /**
     * Validate form data.
     *
     * Spec §12: scalemin must be strictly less than scalemax.
     *
     * @param array $data
     * @param array $files
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (isset($data['scalemin'], $data['scalemax']) && (int)$data['scalemin'] >= (int)$data['scalemax']) {
            $errors['scalemax'] = get_string('error:minmaxinvalid', 'mod_scorecard');
        }

        return $errors;
    }
}
