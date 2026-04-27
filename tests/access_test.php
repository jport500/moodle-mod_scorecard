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
 * Behavior-level regression test for SPEC §9.1's role × capability matrix.
 *
 * Created in response to a Phase 1 access.php authoring oversight surfaced
 * during Phase 4.1 walkthrough: mod/scorecard:view's archetypes array was
 * missing 'editingteacher', so the default Moodle "Teacher" role (display
 * name "Teacher", archetype shortname `editingteacher`) could not see the
 * activity card in the course outline. SPEC §9.1's row had the same
 * omission -- both source and code were corrected together.
 *
 * Phase 1-3 walkthroughs missed it because every prior walkthrough used the
 * site admin (`is_siteadmin()` short-circuits has_capability()), and PHPUnit
 * tests created users via the data generator without exercising role-level
 * inheritance. Phase 4 was the first phase to exercise a non-admin authoring
 * role end-to-end.
 *
 * This test asserts every (capability, role) pair in SPEC §9.1 grants
 * has_capability=true at course context. Negative assertions ("student must
 * NOT have :manage") are deferred to followup #17 in Phase 5b alongside the
 * privacy provider work.
 *
 * The test exists to catch SPEC §9.1 mismatches against actual capability
 * propagation -- not just the editingteacher case that motivated it. The
 * all-roles × all-caps matrix shape IS the protection: when this test was
 * first authored, the editingteacher fix was the known bug, but running the
 * matrix surfaced a second SPEC mismatch on `:addinstance` (coursecreator
 * never actually got the cap because `clonepermissionsfrom` dominates over
 * the archetypes list). Future SPEC §9.1 edits should expect this test to
 * catch any gap where the table claims a role gets a cap but Moodle's
 * propagation rules do not actually grant it.
 *
 * @package    mod_scorecard
 * @category   test
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_scorecard;

use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

/**
 * SPEC §9.1 capability matrix tests.
 */
#[CoversNothing]
final class access_test extends \advanced_testcase {
    /**
     * Every (capability, role) pair in SPEC §9.1 grants the capability when
     * the role is assigned at the course context.
     *
     * Course-context assertion is sufficient for module-level capabilities
     * because Moodle's context inheritance grants module caps at the course
     * context for users with a course-level role assignment. No course module
     * fixture is required -- the assertion is "would this user have the cap
     * if they were on a module in this course?" which is what report.php,
     * view.php, manage.php, and submit.php each call has_capability() to
     * answer at runtime.
     *
     * Fresh user per (cap, role) pair to avoid cross-iteration state
     * contamination from accumulated role assignments on a single user.
     * ~22 user creations is acceptable overhead for one test method.
     */
    public function test_spec_section_9_1_role_capabilities_match(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        // SPEC §9.1 capability matrix. Every cell here MUST resolve to
        // has_capability=true at course context. Source of truth: docs/SPEC.md.
        $expected = [
            'mod/scorecard:addinstance' => ['manager', 'editingteacher'],
            'mod/scorecard:view'        => ['student', 'teacher', 'editingteacher', 'manager'],
            'mod/scorecard:submit'      => ['student'],
            'mod/scorecard:manage'      => ['editingteacher', 'manager'],
            'mod/scorecard:viewreports' => ['teacher', 'editingteacher', 'manager'],
            'mod/scorecard:export'      => ['teacher', 'editingteacher', 'manager'],
        ];

        foreach ($expected as $capability => $roles) {
            foreach ($roles as $roleshortname) {
                $user = $this->getDataGenerator()->create_user();
                $this->getDataGenerator()->enrol_user(
                    $user->id,
                    $course->id,
                    $roleshortname
                );
                $this->assertTrue(
                    has_capability($capability, $coursecontext, $user),
                    "Role '{$roleshortname}' should have '{$capability}' per SPEC §9.1"
                );
            }
        }
    }

    /**
     * The 2026042602 upgrade step restores mod/scorecard:view to the
     * editingteacher archetype on existing deployments.
     *
     * Covers the upgrade-from-broken-baseline path: fresh installs are
     * exercised by test_spec_section_9_1_role_capabilities_match above;
     * this test pins the OTHER deployment surface, where access.php has
     * been fixed but role_capabilities still reflects the buggy version.
     * Without an explicit upgrade step, Moodle's update_capabilities()
     * preserves the missing row (admin-customization preservation rule).
     *
     * Direct function call rather than triggering full upgrade machinery
     * via upgrade_plugins() — faster and avoids dependencies on upgrade
     * infrastructure PHPUnit handles differently. Standard Moodle pattern
     * for unit-testing upgrade steps.
     */
    public function test_upgrade_step_restores_editingteacher_view_cap(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/scorecard/db/upgrade.php');

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');

        $editingteacherrole = $DB->get_record(
            'role',
            ['shortname' => 'editingteacher'],
            '*',
            MUST_EXIST
        );
        $systemcontext = \context_system::instance();

        // Simulate broken pre-fix state: rewind the recorded plugin version
        // (so upgrade_mod_savepoint can advance it again) and drop the
        // editingteacher cap row.
        set_config('version', 2026042601, 'mod_scorecard');
        $DB->delete_records('role_capabilities', [
            'roleid' => $editingteacherrole->id,
            'capability' => 'mod/scorecard:view',
            'contextid' => $systemcontext->id,
        ]);
        accesslib_clear_all_caches_for_unit_testing();

        // Pre-condition assertion: the cap is gone for this user.
        $this->assertFalse(
            has_capability('mod/scorecard:view', $coursecontext, $user),
            'Pre-condition: editingteacher should not have :view after the simulated break'
        );

        // Run the upgrade step from the buggy version stamp.
        xmldb_scorecard_upgrade(2026042601);
        accesslib_clear_all_caches_for_unit_testing();

        // The cap is restored.
        $this->assertTrue(
            has_capability('mod/scorecard:view', $coursecontext, $user),
            'After upgrade: editingteacher should have :view restored'
        );

        // The restored row exists at system context (where assign_capability put it).
        $this->assertTrue(
            $DB->record_exists('role_capabilities', [
                'roleid' => $editingteacherrole->id,
                'capability' => 'mod/scorecard:view',
                'contextid' => $systemcontext->id,
            ]),
            'After upgrade: an explicit role_capabilities row exists for editingteacher × :view'
        );
    }

    /**
     * The upgrade step is idempotent: re-running does not duplicate rows
     * and does not overwrite an admin's explicit cap override (e.g. a
     * site that intentionally set CAP_PREVENT on a custom editingteacher-
     * cloned role).
     */
    public function test_upgrade_step_preserves_existing_cap_row(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/scorecard/db/upgrade.php');

        $editingteacherrole = $DB->get_record(
            'role',
            ['shortname' => 'editingteacher'],
            '*',
            MUST_EXIST
        );
        $systemcontext = \context_system::instance();

        // Set an admin-style override: CAP_PREVENT on the cap. This is the
        // shape of a deliberate customization the upgrade must not clobber.
        // Rewind the recorded version so upgrade_mod_savepoint can advance.
        set_config('version', 2026042601, 'mod_scorecard');
        $DB->delete_records('role_capabilities', [
            'roleid' => $editingteacherrole->id,
            'capability' => 'mod/scorecard:view',
            'contextid' => $systemcontext->id,
        ]);
        $DB->insert_record('role_capabilities', (object)[
            'contextid' => $systemcontext->id,
            'roleid' => $editingteacherrole->id,
            'capability' => 'mod/scorecard:view',
            'permission' => CAP_PREVENT,
            'timemodified' => time(),
            'modifierid' => 0,
        ]);
        accesslib_clear_all_caches_for_unit_testing();

        xmldb_scorecard_upgrade(2026042601);
        accesslib_clear_all_caches_for_unit_testing();

        // The CAP_PREVENT row is preserved -- no new CAP_ALLOW row inserted.
        $rows = $DB->get_records('role_capabilities', [
            'roleid' => $editingteacherrole->id,
            'capability' => 'mod/scorecard:view',
            'contextid' => $systemcontext->id,
        ]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame((int)CAP_PREVENT, (int)$row->permission);
    }
}
