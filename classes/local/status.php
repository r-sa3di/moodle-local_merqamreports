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
 * Allocation status as a number worked out in SQL, with labels from our own strings.
 *
 * The order of checks matches \tool_muprog\local\allocation::get_completion_status().
 * The numbers double as the sort order: overdue first, archived last.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status {
    /** @var int Due date passed, not completed. */
    public const OVERDUE = 1;
    /** @var int Started, not due, not completed. */
    public const OPEN = 2;
    /** @var int Start date in the future. */
    public const NOTSTARTED = 3;
    /** @var int End date passed without completion. */
    public const ENDED = 4;
    /** @var int Completed. */
    public const COMPLETED = 5;
    /** @var int Program or allocation archived, completed before. */
    public const ARCHIVEDCOMPLETED = 6;
    /** @var int Program or allocation archived, not completed. */
    public const ARCHIVED = 7;

    /**
     * SQL expression returning the status number.
     *
     * Every call uses new parameter names, so the expression can appear several times in one query.
     *
     * @param string $allocationalias alias of tool_muprog_allocation
     * @param string $programalias alias of tool_muprog_program
     * @param int|null $now the moment to judge the status at; null for now (time())
     * @return array [sql, params]
     */
    public static function get_sql(string $allocationalias, string $programalias, ?int $now = null): array {
        [$now1, $now2, $now3] = database::generate_param_names(3);
        $now = $now ?? time();
        $a = $allocationalias;
        $p = $programalias;

        $sql = "CASE
                    WHEN ({$p}.archived = 1 OR {$a}.archived = 1) AND {$a}.timecompleted IS NOT NULL
                        THEN " . self::ARCHIVEDCOMPLETED . "
                    WHEN {$p}.archived = 1 OR {$a}.archived = 1
                        THEN " . self::ARCHIVED . "
                    WHEN {$a}.timecompleted IS NOT NULL
                        THEN " . self::COMPLETED . "
                    WHEN {$a}.timestart > :{$now1}
                        THEN " . self::NOTSTARTED . "
                    WHEN {$a}.timeend IS NOT NULL AND {$a}.timeend < :{$now2}
                        THEN " . self::ENDED . "
                    WHEN {$a}.timedue IS NOT NULL AND {$a}.timedue < :{$now3}
                        THEN " . self::OVERDUE . "
                    ELSE " . self::OPEN . "
                END";

        return [$sql, [$now1 => $now, $now2 => $now, $now3 => $now]];
    }

    /**
     * Status labels, in sort order.
     *
     * @return string[] status number => label
     */
    public static function get_labels(): array {
        return [
            self::OVERDUE => get_string('status_overdue', 'local_merqamreports'),
            self::OPEN => get_string('status_open', 'local_merqamreports'),
            self::NOTSTARTED => get_string('status_notstarted', 'local_merqamreports'),
            self::ENDED => get_string('status_ended', 'local_merqamreports'),
            self::COMPLETED => get_string('status_completed', 'local_merqamreports'),
            self::ARCHIVEDCOMPLETED => get_string('status_archivedcompleted', 'local_merqamreports'),
            self::ARCHIVED => get_string('status_archived', 'local_merqamreports'),
        ];
    }

    /**
     * Label of one status number.
     *
     * @param int|null $status
     * @return string
     */
    public static function get_label(?int $status): string {
        if ($status === null) {
            return '';
        }
        return self::get_labels()[$status] ?? '';
    }
}
