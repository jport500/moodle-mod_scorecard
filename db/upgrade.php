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
 * Upgrade steps for mod_scorecard.
 *
 * IMPORTANT architectural note: changes to db/access.php archetype defaults
 * for EXISTING capabilities do NOT auto-propagate via update_capabilities() —
 * Moodle deliberately preserves admin customizations on upgrade. Only NEW
 * capabilities (first-time install for the plugin) get full archetype
 * propagation. Archetype changes to existing caps need explicit
 * assign_capability() steps in this file, keyed off the version savepoint.
 *
 * Future maintainers: do not "consolidate" away upgrade steps on the
 * assumption that access.php handles propagation. It only does so on fresh
 * install. Existing deployments running an older version stamp depend on
 * the upgrade savepoints here to receive archetype corrections.
 *
 * @package    mod_scorecard
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run mod_scorecard upgrade steps from the given old version.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool Always true on success.
 */
function xmldb_scorecard_upgrade(int $oldversion): bool {
    global $DB, $CFG;

    if ($oldversion < 2026042602) {
        // Phase 1 access.php correction shipped with version 2026042602:
        // editingteacher was missing from mod/scorecard:view's archetypes,
        // so the default Moodle "Teacher" role (archetype shortname
        // `editingteacher`) could not see scorecard activity cards in the
        // course outline.
        //
        // update_capabilities('mod_scorecard') runs automatically on every
        // plugin upgrade BUT does NOT re-propagate archetype rows for
        // capabilities that already exist in mdl_capabilities -- this is
        // intentional Moodle behavior to preserve site-admin overrides on
        // existing role-cap rows. So fixing access.php alone does not
        // populate the missing row on existing deployments; an explicit
        // assign_capability() pass over editingteacher-archetype roles is
        // required, which is what this savepoint does.
        require_once($CFG->libdir . '/accesslib.php');

        $editingteacherroles = $DB->get_records('role', ['archetype' => 'editingteacher']);
        $systemcontext = context_system::instance();
        foreach ($editingteacherroles as $role) {
            // Idempotent: skip if an explicit row already exists. Preserves
            // any deliberate admin override (e.g. CAP_PREVENT) that an
            // operator may have set on a custom editingteacher-cloned role
            // before this fix landed.
            $existing = $DB->get_record('role_capabilities', [
                'roleid' => $role->id,
                'capability' => 'mod/scorecard:view',
                'contextid' => $systemcontext->id,
            ]);
            if (!$existing) {
                assign_capability(
                    'mod/scorecard:view',
                    CAP_ALLOW,
                    $role->id,
                    $systemcontext->id
                );
            }
        }

        upgrade_mod_savepoint(true, 2026042602, 'scorecard');
    }

    if ($oldversion < 2026042701) {
        // Phase 5a.4: add completionsubmit column to mdl_scorecard for the
        // custom completion rule (SPEC §9.3 "complete when learner submits
        // at least one attempt"). Existing scorecards default to 0
        // (no auto-complete-on-submit) — operators see the new checkbox in
        // the activity edit form and explicitly opt in. New scorecards
        // default to 1 via mod_form (operator-friendly for self-assessments
        // where the natural completion criterion is "they submitted").
        $dbman = $DB->get_manager();
        $table = new xmldb_table('scorecard');
        $field = new xmldb_field(
            'completionsubmit',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'grade'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026042701, 'scorecard');
    }

    if ($oldversion < 2026042702) {
        // Phase 5a.5 originally attempted a synchronous backfill iteration
        // here (per the kickoff Q4 disposition: immediate over cron-deferred,
        // justified by LMS Light's small deployment scale). The body would
        // iterate scorecards with gradeenabled=1 and call
        // scorecard_update_grades on each, propagating existing v0.4.x
        // attempts to gradebook entries.
        //
        // That approach is technically infeasible in Moodle 5.x. Moodle's
        // grade_update internally calls grade_item->is_locked, which calls
        // course_module_instance_pending_deletion, which calls
        // get_fast_modinfo, which calls upgrade_ensure_not_running and
        // throws "cannot be executed during upgrade" when invoked from a
        // savepoint context. PHPUnit upgrade-path tests do not replicate
        // this guard and so passed against the broken implementation; the
        // failure surfaced only when the upgrade was applied in DDEV.
        //
        // The fallback path is lifecycle hooks: scorecard_update_instance
        // from Phase 5a.1 calls scorecard_update_grades whenever an
        // operator edits and saves a scorecard, which creates the grade
        // item and propagates user grades. For the typical v0.4.x
        // deployment, gradeenabled defaulted to 0 and was an unused toggle
        // pre-5a.1, so the lifecycle-hook fallback covers the realistic
        // upgrade scenarios. Rare deployments with gradeenabled=1
        // scorecards from v0.4.x require operator remediation (edit + save
        // the affected activity once post-upgrade); see CHANGES.md v0.5.0
        // ### Operator action for the operator-facing narrative.
        //
        // v1.x revisit candidate: a cron-deferred adhoc task
        // (\mod_scorecard\task\backfill_grades) queued during upgrade and
        // run by the next cron tick, which would execute scorecard_update_grades
        // outside upgrade context. Worth implementing if customer scale
        // demands automated propagation rather than per-activity operator
        // remediation.
        upgrade_mod_savepoint(true, 2026042702, 'scorecard');
    }

    return true;
}
