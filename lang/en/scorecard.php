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
 * English language strings for mod_scorecard.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowretakes'] = 'Allow retakes';
$string['allowretakes_help'] = 'If enabled, learners can submit multiple attempts. All attempts are retained for reporting.';
$string['error:minmaxinvalid'] = 'Maximum scale value must be greater than minimum scale value.';
$string['fallbackmessage'] = 'Fallback message';
$string['fallbackmessage_default'] = '<p>Your score is outside the configured result ranges.</p><p>Please contact your facilitator if you have questions about your result.</p>';
$string['fallbackmessage_help'] = 'Shown to the learner when their total score does not match any defined result band. Pre-populated at activity creation; edit to customise per scorecard.';
$string['grade'] = 'Maximum grade';
$string['grade_help'] = 'The maximum grade written to the gradebook when gradebook integration is enabled. A value of 0 means the maximum will be derived from the item count and scale at first save (Phase 5a).';
$string['gradeenabled'] = 'Send score to gradebook';
$string['gradeenabled_help'] = 'If enabled, the learner\'s total score is written to the Moodle gradebook as the raw grade.';
$string['gradeheader'] = 'Gradebook integration';
$string['highlabel'] = 'High anchor label';
$string['highlabel_default'] = 'High';
$string['highlabel_help'] = 'Optional label displayed at the maximum end of the rating scale (e.g., "Strongly agree"). Item-level anchors override this.';
$string['lowlabel'] = 'Low anchor label';
$string['lowlabel_default'] = 'Low';
$string['lowlabel_help'] = 'Optional label displayed at the minimum end of the rating scale (e.g., "Strongly disagree"). Item-level anchors override this.';
$string['manage:bands:empty'] = 'No result bands yet. Learners will see the fallback message for any score.';
$string['manage:heading'] = 'Manage scorecard';
$string['manage:items:empty'] = 'No items yet.';
$string['manage:nomanagecapability'] = 'You do not have permission to manage this scorecard.';
$string['manage:reports:phase4placeholder'] = 'Reports are not yet available. This tab will show submitted attempts and offer CSV export in a future release.';
$string['manage:tab:bands'] = 'Bands';
$string['manage:tab:items'] = 'Items';
$string['manage:tab:reports'] = 'Reports';
$string['modulename'] = 'Scorecard';
$string['modulename_help'] = 'The Scorecard activity lets a learner answer scored prompts, calculates a total score, displays a result band and interpretation, and optionally writes the score to the gradebook. Use Scorecard for self-assessments, readiness checks, coaching tools, and similar professional training workflows.';
$string['modulenameplural'] = 'Scorecards';
$string['pluginadministration'] = 'Scorecard administration';
$string['pluginname'] = 'Scorecard';
$string['privacy:metadata:scorecard_attempts'] = 'Records of submitted scorecard attempts, one row per user per submission.';
$string['privacy:metadata:scorecard_attempts:bandlabelsnapshot'] = 'The label of the result band the user matched, captured at submission time so historical results remain stable if bands are later edited.';
$string['privacy:metadata:scorecard_attempts:bandmessagesnapshot'] = 'The interpretive message of the result band the user matched, captured at submission time.';
$string['privacy:metadata:scorecard_attempts:maxscore'] = 'The maximum possible score at the time of submission, snapshotted onto the attempt.';
$string['privacy:metadata:scorecard_attempts:percentage'] = 'The percentage score (totalscore divided by maxscore) at the time of submission.';
$string['privacy:metadata:scorecard_attempts:timecreated'] = 'The time the attempt was submitted.';
$string['privacy:metadata:scorecard_attempts:totalscore'] = 'The total score the user received on this attempt.';
$string['privacy:metadata:scorecard_attempts:userid'] = 'The ID of the user who submitted the attempt.';
$string['privacy:metadata:scorecard_responses'] = 'Per-item responses recorded within a scorecard attempt.';
$string['privacy:metadata:scorecard_responses:attemptid'] = 'Reference to the parent attempt; included so per-item responses can be exported in the context of the attempt they belong to.';
$string['privacy:metadata:scorecard_responses:responsevalue'] = 'The numeric value the user selected for the corresponding item.';
$string['privacy:metadata:scorecard_responses:timecreated'] = 'The time the response was recorded.';
$string['resultheader'] = 'Result and submission';
$string['scaleheader'] = 'Rating scale';
$string['scalemax'] = 'Maximum scale value';
$string['scalemax_help'] = 'The maximum numeric value learners can choose for each item. Recommended practical maximum: 20.';
$string['scalemin'] = 'Minimum scale value';
$string['scalemin_help'] = 'The minimum numeric value learners can choose for each item. Negative values are allowed (e.g., -5 to +5 for risk-style scales) but document the implication for percentage calculations.';
$string['scorecard:addinstance'] = 'Add a new Scorecard activity';
$string['scorecard:export'] = 'Export Scorecard responses';
$string['scorecard:manage'] = 'Manage Scorecard items and bands';
$string['scorecard:submit'] = 'Submit responses to a Scorecard';
$string['scorecard:view'] = 'View Scorecard activity';
$string['scorecard:viewreports'] = 'View Scorecard reports';
$string['showitemsummary'] = 'Show item summary on result';
$string['showitemsummary_help'] = 'If enabled, the learner sees a collapsible per-item response summary on the result screen.';
$string['showpercentage'] = 'Show percentage on result';
$string['showpercentage_help'] = 'If enabled, the learner sees a percentage alongside their raw score. Off by default because percentages on bounded scales (where the minimum possible score is rarely zero) can be misleading.';
$string['showresult'] = 'Show result immediately';
$string['showresult_help'] = 'If enabled, the learner sees their score and result band immediately after submission. If disabled, only a confirmation is shown.';
$string['view:manageitemslink'] = 'Add items and result bands';
$string['view:noitems_learner'] = 'This scorecard isn\'t ready yet. Please check back later.';
$string['view:noitems_manager'] = 'No items configured yet. Add scored prompts and result bands from the manage page.';
