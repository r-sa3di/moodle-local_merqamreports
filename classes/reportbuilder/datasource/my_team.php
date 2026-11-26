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

use local_merqamreports\local\team_access;

/**
 * "My team" data source: the program allocations of the viewer's own subordinates.
 *
 * Same rows and columns as {@see program_allocations}, with a different access rule: only learners
 * whose supervisor (MuTMS Supervisors and teams, tool_murelation) is the person viewing the report,
 * in any framework. Everybody else, site admins included, gets no rows. One report can therefore be
 * shared with the managers of every company: each manager sees only their own people.
 *
 * Available only when tool_murelation is installed.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class my_team extends program_allocations {
    #[\Override]
    public static function get_name(): string {
        return get_string('datasource_myteam', 'local_merqamreports');
    }

    #[\Override]
    public static function is_available(): bool {
        return team_access::is_available();
    }

    #[\Override]
    protected function add_access_conditions(string $categoryidfield, string $useridfield): void {
        [$teamsql, $teamparams] = team_access::get_subordinate_condition($useridfield);
        $this->add_base_condition_sql($teamsql, $teamparams);
    }

    #[\Override]
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'user:department',
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
    public function get_default_conditions(): array {
        // No company condition: the team rule already limits the rows to the viewer's people.
        return [];
    }
}
