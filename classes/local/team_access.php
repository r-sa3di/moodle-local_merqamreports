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

namespace local_merqamreports\local;

use core_reportbuilder\local\helpers\database;

/**
 * Team access for the "My team" report: which learners the current user supervises.
 *
 * Reads MuTMS Supervisors and teams (tool_murelation): a subordinate position points to a supervisor
 * position, whose user is the supervisor. Works for Supervisors and Teams frameworks alike.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class team_access {
    /**
     * Whether tool_murelation is installed.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return \core_component::get_component_directory('tool_murelation') !== null;
    }

    /**
     * SQL condition: the learner in the given field is a subordinate of the user.
     *
     * Uses EXISTS, so a learner supervised by the same person in several frameworks still gives one
     * row. Returns "1 = 0" (no rows) when tool_murelation is missing or there is no user.
     *
     * @param string $useridfield SQL field with the learner's user id
     * @param int|null $supervisorid null for the current user
     * @return array [sql, params]
     */
    public static function get_subordinate_condition(string $useridfield, ?int $supervisorid = null): array {
        global $USER;

        $supervisorid = $supervisorid ?? (int)($USER->id ?? 0);
        if (!$supervisorid || !self::is_available()) {
            return ['1 = 0', []];
        }

        $sub = database::generate_alias();
        $sup = database::generate_alias();
        $param = database::generate_param_name();

        $sql = "EXISTS (
                    SELECT 1
                      FROM {tool_murelation_subordinate} {$sub}
                      JOIN {tool_murelation_supervisor} {$sup} ON {$sup}.id = {$sub}.supervisorid
                     WHERE {$sub}.userid = {$useridfield} AND {$sup}.userid = :{$param}
                )";

        return [$sql, [$param => $supervisorid]];
    }
}
