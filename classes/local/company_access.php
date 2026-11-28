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
     * Whether the user is refused everywhere because they hold the capability site-wide.
     *
     * Company categories are top-level, so the only context above a company is the system. A
     * non-admin holding the capability there (an accidental site-wide grant) would see every company,
     * so they get nothing instead (fail closed). Site admins are not affected.
     *
     * @param int|null $userid null for the current user
     * @return bool
     */
    public static function is_blocked_sitewide(?int $userid = null): bool {
        if (is_siteadmin($userid)) {
            return false;
        }
        return has_capability(self::CAPABILITY, \core\context\system::instance(), $userid);
    }

    /**
     * Ids of the course categories where the user holds the capability (site admins: all categories).
     *
     * Empty for a non-admin who holds the capability in the system context ({@see is_blocked_sitewide()}).
     *
     * @param int|null $userid null for the current user
     * @return int[]
     */
    public static function get_allowed_category_ids(?int $userid = null): array {
        if (self::is_blocked_sitewide($userid)) {
            return [];
        }
        [$categories] = get_user_capability_contexts(self::CAPABILITY, true, $userid);
        if (!$categories) {
            return [];
        }
        $ids = array_map(static fn($category): int => (int)$category->id, $categories);
        if (is_siteadmin($userid)) {
            return $ids;
        }
        return self::remove_misplaced_companies($ids);
    }

    /**
     * Remove configured company categories that are no longer top-level, and everything below them.
     *
     * A company moved under another category (e.g. Beta under Alpha) would otherwise be inherited by
     * the outer company's managers. Ordinary sub-categories of a company are not affected. This relies
     * on the saved setting: change company categories only on the settings page, never by admin preset
     * or CLI (docs/program-reports.md section 11.1, known limits).
     *
     * @param int[] $ids category ids
     * @return int[]
     */
    private static function remove_misplaced_companies(array $ids): array {
        global $DB;

        $saved = overview_service::get_saved_ids();
        if (!$saved || !$ids) {
            return $ids;
        }
        [$insql, $params] = $DB->get_in_or_equal($saved, SQL_PARAMS_NAMED);
        $misplaced = $DB->get_fieldset_select('course_categories', 'path', "id {$insql} AND parent <> 0", $params);
        if (!$misplaced) {
            return $ids;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $paths = $DB->get_records_select_menu('course_categories', "id {$insql}", $params, '', 'id, path');
        $kept = [];
        foreach ($ids as $id) {
            $path = $paths[$id] ?? null;
            if ($path === null) {
                continue;
            }
            foreach ($misplaced as $bad) {
                if ($path === $bad || str_starts_with($path, $bad . '/')) {
                    continue 2;
                }
            }
            $kept[] = $id;
        }
        return $kept;
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
