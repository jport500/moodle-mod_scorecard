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
 * JSON template export endpoint for mod_scorecard.
 *
 * Phase 6.1: operator clicks "Export template" on manage.php → this endpoint
 * streams a download of the template envelope per SPEC §9.6.
 *
 * Composition:
 *  1. Capability gate: require_capability('mod/scorecard:manage') BEFORE any
 *     data fetch. Templates are author-side affordances — same capability
 *     that gates manage.php items/bands authoring per SPEC §9.6.
 *  2. Helper: scorecard_template_export() returns the nested array; this
 *     endpoint json_encodes with JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
 *     | JSON_UNESCAPED_UNICODE for an operator-readable artifact.
 *  3. Filename: clean_filename(format_string($scorecard->name)) per SPEC §9.6
 *     literal — format_string strips HTML defensively before slug. Empty-
 *     string fallback emits the lang string 'template:filename:fallback'
 *     (no name prefix — leading hyphen would look broken in operator
 *     downloads).
 *  4. No sesskey required: read-only export, capability-gated. Matches
 *     Phase 4 export.php (CSV) precedent — CSRF concerns apply to mutating
 *     actions, not read-only ones.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('scorecard', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$scorecard = $DB->get_record('scorecard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scorecard:manage', $context);

$template = scorecard_template_export((int)$scorecard->id);

$json = json_encode(
    $template,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

// Filename per SPEC §9.6: clean_filename(format_string($scorecard->name)).
// format_string strips HTML defensively; clean_filename slugifies for
// filesystem safety. Empty-string fallback per Q10 sub-disposition: a
// leading hyphen on "-template.json" would look broken to operators, so
// emit a generic name when the slug resolves to empty.
$slug = clean_filename(format_string((string)$scorecard->name));
$filename = $slug !== ''
    ? $slug . '-template.json'
    : get_string('template:filename:fallback', 'mod_scorecard');

send_file(
    $json,
    $filename,
    0,
    0,
    true,
    true,
    'application/json; charset=utf-8'
);
