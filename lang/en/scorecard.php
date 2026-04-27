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
$string['badge:deleted'] = 'deleted';
$string['badge:hidden'] = 'hidden';
$string['band:add'] = 'Add a band';
$string['band:confirm:harddelete'] = 'Permanently delete this band? It cannot be undone.';
$string['band:confirm:softdelete'] = 'Hide this band from learners? Historical attempts already reference this band, so it will be hidden but not removed.';
$string['band:delete'] = 'Delete';
$string['band:edit'] = 'Edit';
$string['band:error:labelempty'] = 'Label cannot be empty.';
$string['band:error:minmaxinvalid'] = 'Maximum score must be greater than or equal to minimum score.';
$string['band:error:overlap'] = 'This range overlaps with band "{$a->otherlabel}" ({$a->othermin}–{$a->othermax}) on {$a->min}–{$a->max}.';
$string['band:heading:add'] = 'Add a new band';
$string['band:heading:edit'] = 'Edit band';
$string['band:label'] = 'Label';
$string['band:label_help'] = 'Short result label shown on the learner result screen (e.g., "Strong" or "Concerning").';
$string['band:maxscore'] = 'Maximum score (inclusive)';
$string['band:maxscore_help'] = 'Inclusive upper bound of the score range that triggers this band.';
$string['band:message'] = 'Result message';
$string['band:message_help'] = 'Optional learner-facing interpretation shown on the result screen alongside the band label.';
$string['band:minscore'] = 'Minimum score (inclusive)';
$string['band:minscore_help'] = 'Inclusive lower bound of the score range that triggers this band.';
$string['band:notify:added'] = 'Band added.';
$string['band:notify:deleted'] = 'Band deleted.';
$string['band:notify:updated'] = 'Band updated.';
$string['band:save'] = 'Save band';
$string['band:warning:gaps'] = 'Uncovered score ranges: {$a}. Add a band covering these ranges, or rely on the fallback message.';
$string['error:minmaxinvalid'] = 'Maximum scale value must be greater than minimum scale value.';
$string['error:scalechangeblocked'] = 'Scale values cannot be changed once the scorecard has attempts. Duplicate the activity if you need a different scale.';
$string['event:attempt_submitted'] = 'Scorecard attempt submitted';
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
$string['item:add'] = 'Add an item';
$string['item:confirm:harddelete'] = 'Permanently delete this item? It cannot be undone.';
$string['item:confirm:softdelete'] = 'Hide this item from learners? Historical attempts already reference this item, so it will be hidden but not removed.';
$string['item:delete'] = 'Delete';
$string['item:edit'] = 'Edit';
$string['item:error:promptempty'] = 'Prompt cannot be empty.';
$string['item:heading:add'] = 'Add a new item';
$string['item:heading:edit'] = 'Edit item';
$string['item:highlabel'] = 'High anchor (optional)';
$string['item:highlabel_help'] = 'Optional label displayed at the maximum end of this item\'s rating scale. Overrides the activity-level high anchor when set.';
$string['item:lowlabel'] = 'Low anchor (optional)';
$string['item:lowlabel_help'] = 'Optional label displayed at the minimum end of this item\'s rating scale. Overrides the activity-level low anchor when set.';
$string['item:movedown'] = 'Move down';
$string['item:moveup'] = 'Move up';
$string['item:notify:added'] = 'Item added.';
$string['item:notify:added_with_attempts'] = 'Item added. Existing attempts will not include this new item; their max score will appear lower than current attempts.';
$string['item:notify:deleted'] = 'Item deleted.';
$string['item:notify:updated'] = 'Item updated.';
$string['item:prompt'] = 'Prompt';
$string['item:prompt_help'] = 'The question or statement learners respond to on this item\'s rating scale.';
$string['item:save'] = 'Save item';
$string['item:visible'] = 'Visible to learners';
$string['item:visible_help'] = 'If unchecked, this item is treated as a draft and is not shown to learners. Drafted items are skipped during scoring.';
$string['lowlabel'] = 'Low anchor label';
$string['lowlabel_default'] = 'Low';
$string['lowlabel_help'] = 'Optional label displayed at the minimum end of the rating scale (e.g., "Strongly disagree"). Item-level anchors override this.';
$string['manage:bands:empty'] = 'No result bands yet. Learners will see the fallback message for any score.';
$string['manage:bands:noitemsyet'] = 'Add items first. Bands are not useful until you have items to score.';
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
$string['result:headline'] = 'Your score: {$a->totalscore} out of {$a->maxscore}';
$string['result:hidden'] = 'You have submitted this scorecard. The result is not shown to learners on this activity. Contact your facilitator if you need to know your score.';
$string['result:item_value'] = 'Your response: {$a}';
$string['result:itemsummary_heading'] = 'Item summary';
$string['result:percentage'] = '{$a}%';
$string['resultheader'] = 'Result and submission';
$string['retake:previousattempt:format'] = 'Submitted {$a->date}: {$a->totalscore} / {$a->maxscore} · {$a->band}';
$string['retake:previousattempt:headline'] = 'Previous attempt';
$string['retake:previousattempt:noband'] = 'No band match';
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
$string['submit:back'] = 'Back to scorecard';
$string['submit:button'] = 'Submit scorecard';
$string['submit:error:invaliditem'] = 'Your submission contained items that do not belong to this scorecard. Please reload the page and try again.';
$string['submit:error:missing'] = 'Please answer this item.';
$string['submit:error:noitems'] = 'This scorecard has no scorable items right now. Please contact your facilitator.';
$string['submit:error:outofrange'] = 'The selected response is outside the allowed range for this scorecard.';
$string['submit:notify:duplicate'] = 'You have already submitted this scorecard.';
$string['view:manage_affordance'] = 'Manage scorecard';
$string['view:manageitemslink'] = 'Add items and result bands';
$string['view:noitems_learner'] = 'This scorecard isn\'t ready yet. Please check back later.';
$string['view:noitems_manager'] = 'No items configured yet. Add scored prompts and result bands from the manage page.';
