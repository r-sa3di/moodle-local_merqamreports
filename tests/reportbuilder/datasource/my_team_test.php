<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_merqamreports\reportbuilder\datasource;

use core\context;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_merqamreports\local\company_access;
use local_merqamreports\local\progress_service;
use local_merqamreports\local\team_access;

/**
 * Tests for the "My team" data source: a supervisor sees only their own current subordinates,
 * and only within their own company.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(my_team::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(team_access::class)]
final class my_team_test extends core_reportbuilder_testcase {
    /** @var \stdClass[] Users by short name */
    private array $users = [];
    /** @var \stdClass[] Cohorts by short name */
    private array $cohorts = [];
    /** @var \stdClass Alpha supervisors framework */
    private \stdClass $framework;

    /**
     * Two companies. Alpha: boss supervises s1 and s2, otherboss supervises s3. s1 also has a Beta
     * allocation. betaboss supervises b1 in Beta. Managers hold the company report capability in
     * their own category.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        progress_service::reset_caches();

        $gen = $this->getDataGenerator();
        /** @var \tool_muprog_generator $progen */
        $progen = $gen->get_plugin_generator('tool_muprog');
        /** @var \tool_murelation_generator $relgen */
        $relgen = $gen->get_plugin_generator('tool_murelation');

        $alpha = $gen->create_category(['name' => 'Company Alpha', 'idnumber' => 'ALPHA']);
        $beta = $gen->create_category(['name' => 'Company Beta', 'idnumber' => 'BETA']);
        $alphaprogram = $progen->create_program([
            'fullname' => 'Alpha program',
            'idnumber' => 'PA',
            'contextid' => context\coursecat::instance($alpha->id)->id,
        ]);
        $betaprogram = $progen->create_program([
            'fullname' => 'Beta program',
            'idnumber' => 'PB',
            'contextid' => context\coursecat::instance($beta->id)->id,
        ]);

        foreach (['boss', 'otherboss', 'betaboss', 's1', 's2', 's3', 'b1', 'loner'] as $name) {
            $this->users[$name] = $gen->create_user(['username' => $name]);
        }
        foreach (['alpham', 'alphal', 'betam', 'betal'] as $name) {
            $this->cohorts[$name] = $gen->create_cohort(['name' => $name, 'idnumber' => $name]);
        }
        $members = [
            'alpham' => ['boss', 'otherboss'],
            'alphal' => ['s1', 's2', 's3', 'loner'],
            'betam' => ['betaboss'],
            'betal' => ['b1'],
        ];
        foreach ($members as $cohort => $names) {
            foreach ($names as $name) {
                cohort_add_member($this->cohorts[$cohort]->id, $this->users[$name]->id);
            }
        }

        foreach (['s1', 's2', 's3', 'loner'] as $name) {
            $progen->create_program_allocation(['programid' => $alphaprogram->id, 'userid' => $this->users[$name]->id]);
        }
        foreach (['s1', 'b1'] as $name) {
            $progen->create_program_allocation(['programid' => $betaprogram->id, 'userid' => $this->users[$name]->id]);
        }

        $roleid = $gen->create_role(['shortname' => 'companymanagertest']);
        assign_capability(company_access::CAPABILITY, CAP_ALLOW, $roleid, context\system::instance()->id);
        foreach (['boss', 'otherboss'] as $name) {
            role_assign($roleid, $this->users[$name]->id, context\coursecat::instance($alpha->id)->id);
        }
        role_assign($roleid, $this->users['betaboss']->id, context\coursecat::instance($beta->id)->id);

        $this->framework = $relgen->create_framework([
            'uimode' => 'supervisors',
            'name' => 'Alpha line managers',
            'supervisorcohortid' => $this->cohorts['alpham']->id,
            'subordinatecohortid' => $this->cohorts['alphal']->id,
        ]);
        foreach (['s1' => 'boss', 's2' => 'boss', 's3' => 'otherboss'] as $sub => $boss) {
            $this->link($this->framework->id, $sub, $boss);
        }
        $betaframework = $relgen->create_framework([
            'uimode' => 'supervisors',
            'name' => 'Beta line managers',
            'supervisorcohortid' => $this->cohorts['betam']->id,
            'subordinatecohortid' => $this->cohorts['betal']->id,
        ]);
        $this->link($betaframework->id, 'b1', 'betaboss');
    }

    /**
     * Link a subordinate to a supervisor.
     *
     * @param int $frameworkid
     * @param string $sub
     * @param string $boss
     */
    private function link(int $frameworkid, string $sub, string $boss): void {
        $this->getDataGenerator()->get_plugin_generator('tool_murelation')->create_supervisor([
            'frameworkid' => $frameworkid,
            'subuserid' => $this->users[$sub]->id,
            'userid' => $this->users[$boss]->id,
        ]);
    }

    /**
     * Create a "My team" report listing usernames and program ID numbers.
     *
     * @return int report id
     */
    private function create_report(): int {
        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'My team', 'source' => my_team::class, 'default' => 0]);
        $rbgen->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'sortenabled' => 1,
        ]);
        $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'program:idnumber']);
        return (int)$report->get('id');
    }

    /**
     * Rows the given user sees, as "username/programidnumber".
     *
     * @param int $reportid
     * @param \stdClass $user
     * @param bool $resetcache reset the report instance cache first
     * @return string[]
     */
    private function seen_by(int $reportid, \stdClass $user, bool $resetcache = true): array {
        $this->setUser($user);
        if ($resetcache) {
            manager::reset_caches();
        }
        $rows = $this->get_custom_report_content($reportid);
        $names = array_map(static fn(array $row): string => implode('/', array_values($row)), $rows);
        sort($names);
        return $names;
    }

    /**
     * Test: each supervisor sees only their own people, and only in their own company.
     */
    public function test_supervisor_sees_only_own_team_in_own_company(): void {
        $reportid = $this->create_report();
        // The s1 Beta allocation must not appear for the Alpha boss.
        $this->assertSame(['s1/PA', 's2/PA'], $this->seen_by($reportid, $this->users['boss']));
        $this->assertSame(['s3/PA'], $this->seen_by($reportid, $this->users['otherboss']));
        $this->assertSame(['b1/PB'], $this->seen_by($reportid, $this->users['betaboss']));
    }

    /**
     * Test: identity switches on the same report without a cache reset (the schedule recipient pattern).
     */
    public function test_identity_switch_without_cache_reset(): void {
        $reportid = $this->create_report();
        $this->assertSame(['s1/PA', 's2/PA'], $this->seen_by($reportid, $this->users['boss'], false));
        $this->assertSame(['b1/PB'], $this->seen_by($reportid, $this->users['betaboss'], false));
        $this->assertSame(['s1/PA', 's2/PA'], $this->seen_by($reportid, $this->users['boss'], false));
    }

    /**
     * Test: people who supervise nobody, including site admins, see no rows.
     */
    public function test_no_team_no_rows(): void {
        $reportid = $this->create_report();
        $this->assertSame([], $this->seen_by($reportid, $this->users['loner']));
        $this->assertSame([], $this->seen_by($reportid, $this->users['s1']));
        $this->assertSame([], $this->seen_by($reportid, get_admin()));
    }

    /**
     * Test: a link left behind after the learner leaves the subordinate cohort no longer counts.
     */
    public function test_transferred_learner_drops_out(): void {
        $reportid = $this->create_report();
        cohort_remove_member($this->cohorts['alphal']->id, $this->users['s2']->id);
        $this->assertSame(['s1/PA'], $this->seen_by($reportid, $this->users['boss']));
    }

    /**
     * Test: a supervisor who leaves the supervisor cohort, or is suspended, sees no rows.
     */
    public function test_supervisor_removed_or_suspended(): void {
        global $DB;

        $reportid = $this->create_report();
        cohort_remove_member($this->cohorts['alpham']->id, $this->users['otherboss']->id);
        $this->assertSame([], $this->seen_by($reportid, $this->users['otherboss']));

        $DB->set_field('user', 'suspended', 1, ['id' => $this->users['boss']->id]);
        $this->assertSame([], $this->seen_by($reportid, $this->users['boss']));
    }

    /**
     * Test: deleting the supervisor link removes access.
     */
    public function test_removed_link_removes_access(): void {
        global $DB;

        $reportid = $this->create_report();
        $DB->delete_records('tool_murelation_subordinate', ['userid' => $this->users['s2']->id]);
        $this->assertSame(['s1/PA'], $this->seen_by($reportid, $this->users['boss']));
    }

    /**
     * Test: without the company capability, a supervisor sees no rows.
     */
    public function test_company_lock_still_applies(): void {
        $reportid = $this->create_report();
        role_unassign_all(['userid' => $this->users['otherboss']->id]);
        $this->assertSame([], $this->seen_by($reportid, $this->users['otherboss']));
    }

    /**
     * Test: a framework without both cohorts grants nothing (fail closed).
     */
    public function test_framework_without_cohorts_grants_nothing(): void {
        /** @var \tool_murelation_generator $relgen */
        $relgen = $this->getDataGenerator()->get_plugin_generator('tool_murelation');
        $open = $relgen->create_framework(['uimode' => 'supervisors', 'name' => 'No cohorts']);
        $this->link($open->id, 'loner', 'boss');

        $reportid = $this->create_report();
        $this->assertSame(['s1/PA', 's2/PA'], $this->seen_by($reportid, $this->users['boss']));
    }

    /**
     * Test: a learner linked to the same supervisor in two frameworks gives one row per program.
     */
    public function test_two_frameworks_one_row(): void {
        /** @var \tool_murelation_generator $relgen */
        $relgen = $this->getDataGenerator()->get_plugin_generator('tool_murelation');
        $second = $relgen->create_framework([
            'uimode' => 'supervisors',
            'name' => 'Mentors',
            'supervisorcohortid' => $this->cohorts['alpham']->id,
            'subordinatecohortid' => $this->cohorts['alphal']->id,
        ]);
        $this->link($second->id, 's1', 'boss');

        $reportid = $this->create_report();
        $this->assertSame(['s1/PA', 's2/PA'], $this->seen_by($reportid, $this->users['boss']));
    }

    /**
     * Test: the default report builds and runs for a supervisor.
     */
    public function test_default_report(): void {
        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Default', 'source' => my_team::class, 'default' => 1]);
        $this->setUser($this->users['boss']);
        manager::reset_caches();
        $this->assertCount(2, $this->get_custom_report_content((int)$report->get('id')));
    }

    /**
     * Test: every column works on its own, sorted where possible.
     */
    public function test_stress_columns(): void {
        // Run as a supervisor: by design the admin sees no rows in "My team".
        $this->setUser($this->users['boss']);
        $this->datasource_stress_test_columns(my_team::class);
    }

    /**
     * Test: every condition works.
     */
    public function test_stress_conditions(): void {
        $this->setUser($this->users['boss']);
        $this->datasource_stress_test_conditions(my_team::class, 'user:username');
    }
}
