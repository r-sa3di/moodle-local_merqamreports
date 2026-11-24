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
 * Company separation: which course categories the current user may see in program reports.
 *
 * This is "lock 2" in docs/program-reports.md section 4.2. It relies only on Moodle's own
 * get_user_capability_contexts(), which respects inheritance, overrides and prohibitions.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class company_access {
    /** @var string The capability that opens a company's program data. */
    public const CAPABILITY = 'local/merqamreports:viewprogramreports';

    /**
     * Ids of the course categories where the user holds the capability (site admins: all categories).
     *
     * @param int|null $userid null for the current user
     * @return int[]
     */
    public static function get_allowed_category_ids(?int $userid = null): array {
        [$categories] = get_user_capability_contexts(self::CAPABILITY, true, $userid);
        if (!$categories) {
            return [];
        }
        return array_map(static fn($category): int => (int)$category->id, $categories);
    }

    /**
     * SQL condition limiting a category id field to the allowed categories.
     *
     * Returns "1 = 0" (no rows at all) when the user holds the capability nowhere.
     *
     * @param string $categoryidfield SQL field holding a course category id
     * @param int|null $userid null for the current user
     * @return array [sql, params]
     */
    public static function get_category_condition(string $categoryidfield, ?int $userid = null): array {
        global $DB;

        $ids = self::get_allowed_category_ids($userid);
        if (!$ids) {
            return ['1 = 0', []];
        }
        $prefix = database::generate_param_name('_');
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, $prefix);
        return ["{$categoryidfield} {$insql}", $params];
    }
}
