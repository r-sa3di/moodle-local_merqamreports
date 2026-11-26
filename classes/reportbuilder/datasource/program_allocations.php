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

use core_course\reportbuilder\local\entities\course_category;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use local_merqamreports\local\company_access;
use local_merqamreports\reportbuilder\local\entities\allocation;
use local_merqamreports\reportbuilder\local\entities\certificate;
use local_merqamreports\reportbuilder\local\entities\program;

/**
 * Program allocations data source: one row per learner per program (MuTMS).
 *
 * Company separation (docs/program-reports.md section 4.2, "lock 2"): base conditions, applied to
 * every use of this source (screen, downloads, schedules), keep only released programs that live
 * in a course category where the viewer holds local/merqamreports:viewprogramreports. Programs at
 * site level never appear. A viewer without the capability gets no rows. Report conditions
 * (Course category = the company) remain "lock 1".
 *
 * Report Builder creates one instance of this source per report and user, so the conditions are
 * worked out for the user viewing the report, or for each recipient of a schedule sent
 * "as Schedule recipient".
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program_allocations extends datasource {
    #[\Override]
    public static function get_name(): string {
        return get_string('datasource_programallocations', 'local_merqamreports');
    }

    #[\Override]
    protected function initialise(): void {
        // Allocation is the main table: one row per learner and program (MuTMS allows one allocation per pair).
        $allocationentity = new allocation();
        $a = $allocationentity->get_table_alias('tool_muprog_allocation');
        $this->set_main_table('tool_muprog_allocation', $a);

        // Program, its context and its course category: inner joins, one row each. A program whose
        // context is not a course category (e.g. site level) is dropped here.
        $programentity = new program();
        $p = $programentity->get_table_alias('tool_muprog_program');
        $allocationentity->set_table_alias('tool_muprog_program', $p);
        $pctx = database::generate_alias();

        $categoryentity = new course_category();
        $cc = $categoryentity->get_table_alias('course_categories');

        $this->add_join("JOIN {tool_muprog_program} {$p} ON {$p}.id = {$a}.programid");
        $this->add_join("JOIN {context} {$pctx} ON {$pctx}.id = {$p}.contextid
                              AND {$pctx}.contextlevel = " . CONTEXT_COURSECAT);
        $this->add_join("JOIN {course_categories} {$cc} ON {$cc}.id = {$pctx}.instanceid");

        // The learner.
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->add_join("JOIN {user} {$u} ON {$u}.id = {$a}.userid");

        // Base conditions: who may see which rows, released programs only, existing users only.
        $this->add_access_conditions("{$pctx}.instanceid", "{$a}.userid");
        $this->add_base_condition_sql("{$p}.draft = 0");
        $this->add_base_condition_sql("{$u}.deleted = 0");

        // Entities.
        $this->add_entity($allocationentity);
        $this->add_entity($programentity);
        $this->add_entity($categoryentity);
        $this->add_entity($userentity);

        $certificateentity = new certificate();
        $certificateentity->add_joins($certificateentity->get_issue_joins("{$a}.id"));
        $this->add_entity($certificateentity);

        // Report elements. Filters whose option lists come from the database without this source's
        // company limit are left out, so filter controls can't show other companies' names:
        // the category select (kept as a condition, set by the admin) and the user select.
        $this->add_all_from_entity($allocationentity);
        $this->add_all_from_entity($programentity);
        $this->add_all_from_entity($certificateentity);

        $this->add_columns_from_entity($categoryentity);
        $this->add_filters_from_entity($categoryentity, [], ['name']);
        $this->add_conditions_from_entity($categoryentity);

        $this->add_columns_from_entity($userentity);
        $this->add_filters_from_entity($userentity, [], ['userselect']);
        $this->add_conditions_from_entity($userentity, [], ['userselect']);
    }

    /**
     * Add the base conditions that decide which rows the viewer may see.
     *
     * Company program reports (this source): only programs in course categories where the viewer
     * holds local/merqamreports:viewprogramreports ("lock 2"). Sources extending this one replace
     * the rule, e.g. "My team" limits rows to the viewer's own subordinates.
     *
     * @param string $categoryidfield SQL field with the program's course category id
     * @param string $useridfield SQL field with the learner's user id
     */
    protected function add_access_conditions(string $categoryidfield, string $useridfield): void {
        [$companysql, $companyparams] = company_access::get_category_condition($categoryidfield);
        $this->add_base_condition_sql($companysql, $companyparams);
    }

    #[\Override]
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'program:fullname',
            'allocation:status',
            'allocation:requiredprogress',
            'allocation:timedue',
            'allocation:daysoverdue',
            'allocation:timecompleted',
            'certificate:code',
        ];
    }

    #[\Override]
    public function get_default_column_sorting(): array {
        return [
            'allocation:status' => SORT_ASC,
            'user:fullname' => SORT_ASC,
        ];
    }

    #[\Override]
    public function get_default_filters(): array {
        return [
            'program:fullname',
            'allocation:status',
            'allocation:timedue',
            'allocation:timecompleted',
            'user:fullname',
        ];
    }

    #[\Override]
    public function get_default_conditions(): array {
        // The company condition: the admin picks the company's category when creating the report.
        return [
            'course_category:name',
        ];
    }
}
