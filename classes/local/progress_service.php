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

use tool_muprog\local\content\item;
use tool_muprog\local\content\set;
use tool_muprog\local\content\top;

/**
 * Required progress for report rows, the only place that depends on MuTMS internal content classes.
 *
 * Request-local cache only (no persistent cache): each program's structure is loaded once,
 * and its effective completions are loaded in one query, the first time a row of that program
 * is shown. Re-test this class on every MuTMS update (docs/program-reports.md, section 7).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_service {
    /** @var array programid => tree node, or null when the structure could not be loaded */
    private static array $trees = [];

    /** @var array programid => [allocationid => [itemid => true]] */
    private static array $completions = [];

    /**
     * Required progress of one allocation, as a percentage.
     *
     * @param int $programid
     * @param int $allocationid
     * @param int|null $timecompleted allocation completion date; a completed allocation is always 100 %
     * @return float|null null when the program structure is not available
     */
    public static function get_required_percentage(int $programid, int $allocationid, ?int $timecompleted): ?float {
        if ($timecompleted) {
            return 100.0;
        }
        $tree = self::get_tree($programid);
        if ($tree === null) {
            return null;
        }
        $completed = self::get_completions($programid)[$allocationid] ?? [];
        return 100.0 * progress_calculator::calculate($tree, $completed);
    }

    /**
     * Clear the request cache (used by tests and long-running scripts).
     */
    public static function reset_caches(): void {
        self::$trees = [];
        self::$completions = [];
    }

    /**
     * Program structure as a calculator tree.
     *
     * @param int $programid
     * @return array|null
     */
    private static function get_tree(int $programid): ?array {
        if (!array_key_exists($programid, self::$trees)) {
            try {
                self::$trees[$programid] = self::convert(top::load($programid));
            } catch (\coding_exception $e) {
                // Only the expected case: MuTMS reports a missing or malformed program structure
                // ("No program items found", "Missing top program item"). Anything else, such as a
                // changed MuTMS API, is a real error and must show up in CI and testing.
                debugging(
                    'local_merqamreports: cannot load program ' . $programid . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                self::$trees[$programid] = null;
            }
        }
        return self::$trees[$programid];
    }

    /**
     * Convert a MuTMS content item into a calculator node.
     *
     * @param item $item
     * @return array
     */
    private static function convert(item $item): array {
        if (!$item->is_set()) {
            return progress_calculator::node(
                (int)$item->get_id(),
                progress_calculator::TYPE_LEAF,
                (int)$item->get_points()
            );
        }
        if (!$item instanceof set) {
            throw new \coding_exception('Unexpected MuTMS set class: ' . get_class($item));
        }
        $children = [];
        foreach ($item->get_children() as $child) {
            $children[] = self::convert($child);
        }
        return progress_calculator::node(
            (int)$item->get_id(),
            progress_calculator::TYPE_SET,
            (int)$item->get_points(),
            $item->get_sequencetype(),
            $item->get_minprerequisites(),
            $item->get_minpoints(),
            $children
        );
    }

    /**
     * Effective item completions of all allocations of one program (completion date not in the future).
     *
     * @param int $programid
     * @return array allocationid => [itemid => true]
     */
    private static function get_completions(int $programid): array {
        global $DB;

        if (!array_key_exists($programid, self::$completions)) {
            $sql = "SELECT c.id, c.allocationid, c.itemid
                      FROM {tool_muprog_completion} c
                      JOIN {tool_muprog_allocation} a ON a.id = c.allocationid
                     WHERE a.programid = :programid AND c.timecompleted <= :now";
            $rs = $DB->get_recordset_sql($sql, ['programid' => $programid, 'now' => time()]);
            $map = [];
            foreach ($rs as $record) {
                $map[(int)$record->allocationid][(int)$record->itemid] = true;
            }
            $rs->close();
            self::$completions[$programid] = $map;
        }
        return self::$completions[$programid];
    }
}
