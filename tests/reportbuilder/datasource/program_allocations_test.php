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

/**
 * Tests for the program allocations data source: company separation, rows and the smoke tests.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(program_allocations::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(company_access::class)]
final class program_allocations_test extends core_reportbuilder_testcase {
    /** @var \stdClass Company Alpha category */
    private \stdClass $alpha;
    /** @var \stdClass Company Beta category */
    private \stdClass $beta;
    /** @var \stdClass Sub-category of Alpha */
    private \stdClass $alphasub;
    /** @var int Role holding the report capability */
    private int $roleid;

    /**
     * Two companies, a sub-category, a program in each plus one at site level, one learner in each.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        progress_service::reset_caches();

        $gen = $this->getDataGenerator();
        /** @var \tool_muprog_generator $progen */
        $progen = $gen->get_plugin_generator('tool_muprog');

        $this->alpha = $gen->create_category(['name' => 'Company Alpha', 'idnumber' => 'ALPHA']);
        $this->beta = $gen->create_category(['name' => 'Company Beta', 'idnumber' => 'BETA']);
        $this->alphasub = $gen->create_category(['name' => 'Alpha sub', 'idnumber' => 'ALPHASUB',
            'parent' => $this->alpha->id]);

        $programs = [
            'alpha' => $progen->create_program(['fullname' => 'Alpha program', 'idnumber' => 'PA',
                'contextid' => context\coursecat::instance($this->alpha->id)->id]),
            'beta' => $progen->create_program(['fullname' => 'Beta program', 'idnumber' => 'PB',
                'contextid' => context\coursecat::instance($this->beta->id)->id]),
            'sub' => $progen->create_program(['fullname' => 'Sub program', 'idnumber' => 'PS',
                'contextid' => context\coursecat::instance($this->alphasub->id)->id]),
            'site' => $progen->create_program(['fullname' => 'Site program', 'idnumber' => 'PX']),
        ];
        foreach ($programs as $key => $program) {
            $user = $gen->create_user(['username' => 'learner_' . $key]);
            $progen->create_program_allocation(['programid' => $program->id, 'userid' => $user->id]);
        }

        $this->roleid = $gen->create_role(['shortname' => 'companymanagertest']);
        assign_capability(company_access::CAPABILITY, CAP_ALLOW, $this->roleid, context\system::instance()->id);
    }

    /**
     * Create a report on our source listing the learners' usernames.
     *
     * @return int report id
     */
    private function create_username_report(): int {
        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Programs', 'source' => program_allocations::class, 'default' => 0]);
        $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username',
            'sortenabled' => 1]);
        return (int)$report->get('id');
    }

    /**
     * Usernames the given user sees in the report.
     *
     * @param int $reportid
     * @param \stdClass $user
     * @return string[]
     */
    private function usernames_seen_by(int $reportid, \stdClass $user): array {
        $this->setUser($user);
        manager::reset_caches();
        $rows = $this->get_custom_report_content($reportid);
        $names = array_map(static fn(array $row): string => (string)reset($row), $rows);
        sort($names);
        return $names;
    }

    /**
     * Test: the admin sees every company, never the site-level program.
     */
    public function test_admin_sees_all_categories_but_not_site_programs(): void {
        $reportid = $this->create_username_report();
        $this->assertSame(['learner_alpha', 'learner_beta', 'learner_sub'],
            $this->usernames_seen_by($reportid, get_admin()));
    }

    /**
     * Test: a manager sees only their company, sub-categories included.
     */
    public function test_manager_sees_only_own_company(): void {
        $reportid = $this->create_username_report();
        $gen = $this->getDataGenerator();

        $alphamanager = $gen->create_user();
        role_assign($this->roleid, $alphamanager->id, context\coursecat::instance($this->alpha->id)->id);
        $this->assertSame(['learner_alpha', 'learner_sub'], $this->usernames_seen_by($reportid, $alphamanager));

        $betamanager = $gen->create_user();
        role_assign($this->roleid, $betamanager->id, context\coursecat::instance($this->beta->id)->id);
        $this->assertSame(['learner_beta'], $this->usernames_seen_by($reportid, $betamanager));

        $both = $gen->create_user();
        role_assign($this->roleid, $both->id, context\coursecat::instance($this->alpha->id)->id);
        role_assign($this->roleid, $both->id, context\coursecat::instance($this->beta->id)->id);
        $this->assertSame(['learner_alpha', 'learner_beta', 'learner_sub'], $this->usernames_seen_by($reportid, $both));
    }

    /**
     * Test: without the capability there are no rows at all; a prohibit in a sub-category is respected.
     */
    public function test_no_capability_no_rows(): void {
        $reportid = $this->create_username_report();
        $gen = $this->getDataGenerator();

        $nobody = $gen->create_user();
        $this->assertSame([], $this->usernames_seen_by($reportid, $nobody));

        $alphamanager = $gen->create_user();
        role_assign($this->roleid, $alphamanager->id, context\coursecat::instance($this->alpha->id)->id);
        role_change_permission($this->roleid, context\coursecat::instance($this->alphasub->id),
            company_access::CAPABILITY, CAP_PROHIBIT);
        $this->assertSame(['learner_alpha'], $this->usernames_seen_by($reportid, $alphamanager));
    }

    /**
     * Test: an overdue allocation shows the Overdue status and days overdue.
     */
    public function test_overdue_status(): void {
        global $DB;

        $now = time();
        $program = $DB->get_record('tool_muprog_program', ['idnumber' => 'PA'], '*', MUST_EXIST);
        $user = $this->getDataGenerator()->create_user(['username' => 'learner_late']);
        $this->getDataGenerator()->get_plugin_generator('tool_muprog')->create_program_allocation([
            'programid' => $program->id,
            'userid' => $user->id,
            'timeallocated' => $now - 10 * DAYSECS,
            'timestart' => $now - 10 * DAYSECS,
            'timedue' => $now - DAYSECS - HOURSECS,
        ]);

        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Status', 'source' => program_allocations::class, 'default' => 0]);
        $reportid = (int)$report->get('id');
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'allocation:status']);
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'allocation:daysoverdue']);

        $rows = array_map('array_values', $this->get_custom_report_content($reportid));
        $byuser = array_column($rows, null, 0);
        $this->assertSame([get_string('status_overdue', 'local_merqamreports'), '1'],
            array_slice($byuser['learner_late'], 1));
        $this->assertSame([get_string('status_open', 'local_merqamreports'), ''],
            array_slice($byuser['learner_alpha'], 1));
    }

    /**
     * Test: two certificate issues mapped to one allocation still give one row, with the latest code.
     */
    public function test_one_row_per_allocation_with_certificates(): void {
        global $DB;

        $allocation = $DB->get_record_sql("SELECT a.* FROM {tool_muprog_allocation} a
            JOIN {tool_muprog_program} p ON p.id = a.programid WHERE p.idnumber = 'PA'", [], MUST_EXIST);
        foreach (['CODE1', 'CODE2'] as $code) {
            $issueid = $DB->insert_record('tool_certificate_issues', (object)[
                'userid' => $allocation->userid,
                'templateid' => 0,
                'code' => $code,
                'emailed' => 0,
                'timecreated' => time(),
                'expires' => 0,
                'data' => '{}',
                'component' => 'tool_muprog',
                'courseid' => null,
                'archived' => 0,
            ]);
            $DB->insert_record('tool_muprog_cert_issue', (object)[
                'programid' => $allocation->programid,
                'allocationid' => $allocation->id,
                'timecompleted' => time(),
                'issueid' => $issueid,
                'timecreated' => time(),
            ]);
        }

        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Certs', 'source' => program_allocations::class, 'default' => 0]);
        $reportid = (int)$report->get('id');
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        $rbgen->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'certificate:code']);

        $rows = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertCount(3, $rows);
        $byuser = array_column($rows, 1, 0);
        $this->assertSame('CODE2', $byuser['learner_alpha']);
        $this->assertSame('', $byuser['learner_beta']);
    }

    /**
     * Test: the default report (columns, sorting, conditions) builds and runs.
     */
    public function test_default_report(): void {
        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Default', 'source' => program_allocations::class, 'default' => 1]);
        $rows = $this->get_custom_report_content((int)$report->get('id'));
        $this->assertCount(3, $rows);
    }

    /**
     * Test: every column works on its own, sorted where possible.
     */
    public function test_stress_columns(): void {
        $this->datasource_stress_test_columns(program_allocations::class);
    }

    /**
     * Test: every column works with every aggregation it allows.
     */
    public function test_stress_columns_aggregation(): void {
        $this->datasource_stress_test_columns_aggregation(program_allocations::class);
    }

    /**
     * Test: every condition works.
     */
    public function test_stress_conditions(): void {
        $this->datasource_stress_test_conditions(program_allocations::class, 'user:username');
    }
}
