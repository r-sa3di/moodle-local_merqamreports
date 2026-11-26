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
use local_merqamreports\local\progress_service;
use local_merqamreports\local\team_access;

/**
 * Tests for the "My team" data source: a supervisor sees only their own subordinates.
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
    /** @var \stdClass Supervisors framework */
    private \stdClass $framework;

    /**
     * Company Alpha with one program; boss supervises s1 and s2, otherboss supervises s3.
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
        $program = $progen->create_program([
            'fullname' => 'Alpha program',
            'idnumber' => 'PA',
            'contextid' => context\coursecat::instance($alpha->id)->id,
        ]);
        $siteprogram = $progen->create_program(['fullname' => 'Site program', 'idnumber' => 'PX']);

        foreach (['boss', 'otherboss', 's1', 's2', 's3', 'loner'] as $name) {
            $this->users[$name] = $gen->create_user(['username' => $name]);
        }
        foreach (['s1', 's2', 's3', 'loner', 'boss'] as $name) {
            $progen->create_program_allocation(['programid' => $program->id, 'userid' => $this->users[$name]->id]);
        }
        $progen->create_program_allocation(['programid' => $siteprogram->id, 'userid' => $this->users['s1']->id]);

        $this->framework = $relgen->create_framework(['uimode' => 'supervisors', 'name' => 'Line managers']);
        foreach (['s1' => 'boss', 's2' => 'boss', 's3' => 'otherboss'] as $sub => $boss) {
            $relgen->create_supervisor([
                'frameworkid' => $this->framework->id,
                'subuserid' => $this->users[$sub]->id,
                'userid' => $this->users[$boss]->id,
            ]);
        }
    }

    /**
     * Create a "My team" report listing usernames.
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
        return (int)$report->get('id');
    }

    /**
     * Usernames the given user sees in the report.
     *
     * @param int $reportid
     * @param \stdClass $user
     * @return string[]
     */
    private function seen_by(int $reportid, \stdClass $user): array {
        $this->setUser($user);
        manager::reset_caches();
        $rows = $this->get_custom_report_content($reportid);
        $names = array_map(static fn(array $row): string => (string)reset($row), $rows);
        sort($names);
        return $names;
    }

    /**
     * Test: each supervisor sees only their own subordinates, and site-level programs never appear.
     */
    public function test_supervisor_sees_only_own_team(): void {
        $reportid = $this->create_report();
        $this->assertSame(['s1', 's2'], $this->seen_by($reportid, $this->users['boss']));
        $this->assertSame(['s3'], $this->seen_by($reportid, $this->users['otherboss']));
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
     * Test: a learner supervised by the same person in two frameworks still gives one row per program.
     */
    public function test_two_frameworks_one_row(): void {
        /** @var \tool_murelation_generator $relgen */
        $relgen = $this->getDataGenerator()->get_plugin_generator('tool_murelation');
        $second = $relgen->create_framework(['uimode' => 'supervisors', 'name' => 'Mentors']);
        $relgen->create_supervisor([
            'frameworkid' => $second->id,
            'subuserid' => $this->users['s1']->id,
            'userid' => $this->users['boss']->id,
        ]);

        $reportid = $this->create_report();
        $this->assertSame(['s1', 's2'], $this->seen_by($reportid, $this->users['boss']));
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
