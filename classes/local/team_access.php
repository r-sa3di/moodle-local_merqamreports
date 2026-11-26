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
 * Reads MuTMS Supervisors and teams (tool_murelation). A supervisor link counts only when:
 * - its framework is in Supervisors mode (Teams-mode teams don't grant access);
 * - the framework restricts both sides by cohort (frameworks without both cohorts fail closed);
 * - the supervisor is **currently** in the framework's supervisor cohort and the learner in its
 *   subordinate cohort (a link left behind after a transfer stops counting);
 * - the viewer's account is active (not suspended).
 * The "My team" data source also keeps the company lock ({@see company_access}).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class team_access {
    /** @var int Lowest tool_murelation version this code was written and tested for. */
    public const MIN_VERSION = 2026111753;

    /** @var int Same value as \tool_murelation\local\framework::UIMODE_SUPERVISORS. */
    public const UIMODE_SUPERVISORS = 1;

    /**
     * Whether tool_murelation is present and installed at a supported version.
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (\core_component::get_component_directory('tool_murelation') === null) {
            return false;
        }
        $version = get_config('tool_murelation', 'version');
        return $version !== false && (int)$version >= self::MIN_VERSION;
    }

    /**
     * SQL condition: the learner in the given field is a current subordinate of the user.
     *
     * Uses EXISTS, so a learner linked to the same supervisor in several frameworks still gives one
     * row. Returns "1 = 0" (no rows) when tool_murelation is unavailable, there is no user, or the
     * user is suspended.
     *
     * @param string $useridfield SQL field with the learner's user id
     * @param int|null $supervisorid null for the current user
     * @return array [sql, params]
     */
    public static function get_subordinate_condition(string $useridfield, ?int $supervisorid = null): array {
        global $DB, $USER;

        $supervisorid = $supervisorid ?? (int)($USER->id ?? 0);
        if (!$supervisorid || !self::is_available()) {
            return ['1 = 0', []];
        }
        if ($DB->get_field('user', 'suspended', ['id' => $supervisorid, 'deleted' => 0]) !== '0') {
            return ['1 = 0', []];
        }

        [$sub, $sup, $fw, $scm, $lcm] = database::generate_aliases(5);
        [$pviewer, $pmode] = database::generate_param_names(2);

        $sql = "EXISTS (
                    SELECT 1
                      FROM {tool_murelation_subordinate} {$sub}
                      JOIN {tool_murelation_supervisor} {$sup}
                           ON {$sup}.id = {$sub}.supervisorid AND {$sup}.frameworkid = {$sub}.frameworkid
                      JOIN {tool_murelation_framework} {$fw}
                           ON {$fw}.id = {$sub}.frameworkid AND {$fw}.uimode = :{$pmode}
                      JOIN {cohort_members} {$scm}
                           ON {$scm}.cohortid = {$fw}.supervisorcohortid AND {$scm}.userid = {$sup}.userid
                      JOIN {cohort_members} {$lcm}
                           ON {$lcm}.cohortid = {$fw}.subordinatecohortid AND {$lcm}.userid = {$sub}.userid
                     WHERE {$sub}.userid = {$useridfield} AND {$sup}.userid = :{$pviewer}
                )";

        return [$sql, [$pviewer => $supervisorid, $pmode => self::UIMODE_SUPERVISORS]];
    }
}
