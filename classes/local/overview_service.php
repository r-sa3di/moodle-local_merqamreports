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

use core\context;

/**
 * Numbers for the "Company overview" page (docs/program-reports.md section 11).
 *
 * A company is exactly one configured, top-level course category. The overview counts the
 * allocations of released programs placed directly in that category, for non-deleted learners,
 * without archived programs or allocations: the "Program allocations" rows with the fixed
 * condition "Course category = company category", minus the two archived statuses.
 *
 * Every failure (no company, a wrong or tampered category, a "Log in as" session) gives the same
 * permission error, before anything about the requested category is looked up.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overview_service {
    /** @var string Config name of the company categories setting (comma-separated ids). */
    public const SETTING = 'companyroots';

    /** @var int Days ahead counted in "Due in the next 30 days". */
    public const DUESOON_DAYS = 30;

    /** @var int[] Statuses the overview can contain, in display order (archived ones are excluded). */
    public const STATUSES = [status::OVERDUE, status::OPEN, status::NOTSTARTED, status::ENDED, status::COMPLETED];

    /**
     * The one permission error used for every refusal.
     *
     * @return \required_capability_exception
     */
    public static function denied(): \required_capability_exception {
        return new \required_capability_exception(
            context\system::instance(),
            company_access::CAPABILITY,
            'nopermissions',
            ''
        );
    }

    /**
     * Refuse "Log in as" sessions: the system-level check is not reliable inside them.
     *
     * @throws \required_capability_exception
     */
    public static function require_real_session(): void {
        if (\core\session\manager::is_loggedinas()) {
            throw self::denied();
        }
    }

    /**
     * Ids saved in the company categories setting, as saved (not checked).
     *
     * @return int[]
     */
    public static function get_saved_ids(): array {
        return self::parse_ids((string)get_config('local_merqamreports', self::SETTING));
    }

    /**
     * Ids from a comma-separated config value (anything that isn't a plain number is ignored).
     *
     * @param string $raw
     * @return int[]
     */
    public static function parse_ids(string $raw): array {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            // Positive ids only, and only when the number survives the cast unchanged (no overflow, no "007").
            if ($part !== '' && ctype_digit($part) && (string)(int)$part === $part && (int)$part > 0) {
                $ids[] = (int)$part;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Saved company categories that still exist and are still top-level, in category order.
     *
     * @return \stdClass[] id => record (id, name, sortorder)
     */
    public static function get_valid_companies(): array {
        global $DB;

        $ids = self::get_saved_ids();
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        return $DB->get_records_select(
            'course_categories',
            "id {$insql} AND parent = 0",
            $params,
            'sortorder',
            'id, name, sortorder'
        );
    }

    /**
     * Saved ids that are no longer valid company categories (deleted, or moved below another category).
     *
     * @return int[]
     */
    public static function get_invalid_saved_ids(): array {
        return array_values(array_diff(self::get_saved_ids(), array_keys(self::get_valid_companies())));
    }

    /**
     * Companies the user may open: valid company categories where they hold the capability.
     *
     * Two checks, both needed: the category is in company_access's allowed list (which is empty for a
     * non-admin holding the capability site-wide) and has_capability() passes in its context.
     *
     * @param int|null $userid null for the current user
     * @return string[] category id => formatted name, in category order
     */
    public static function get_company_options(?int $userid = null): array {
        $allowed = company_access::get_allowed_category_ids($userid);
        if (!$allowed) {
            return [];
        }
        $options = [];
        foreach (self::get_valid_companies() as $category) {
            $id = (int)$category->id;
            if (!in_array($id, $allowed, true)) {
                continue;
            }
            $categorycontext = context\coursecat::instance($id);
            if (!has_capability(company_access::CAPABILITY, $categorycontext, $userid)) {
                continue;
            }
            // Plain text (escape off): the selector, heading and charts escape it themselves.
            $options[$id] = format_string($category->name, true, ['context' => $categorycontext, 'escape' => false]);
        }
        return $options;
    }

    /**
     * The raw "category" request value, exactly as sent ($_POST first, then $_GET, like optional_param()).
     *
     * A deliberate exception to Moodle's "use optional_param()" rule: the cleaning functions can change a
     * value (PARAM_INT casts "5abc" to 5, PARAM_RAW's fix_utf8() drops invalid bytes), so a malformed value
     * could become an allowed id. Here the exact value is only compared, as a string, with the allowed ids
     * in {@see resolve_requested_category()}; it never reaches SQL or output, and anything else is refused.
     *
     * @return mixed null when absent
     */
    public static function get_request_value(): mixed {
        return $_POST['category'] ?? $_GET['category'] ?? null;
    }

    /**
     * The company selector. It prints option labels as HTML, so it gets escaped copies of the plain names.
     *
     * @param string[] $options from {@see get_company_options()} (plain text)
     * @param int|null $chosen the selected company, if any
     * @return \single_select
     */
    public static function get_selector(array $options, ?int $chosen): \single_select {
        // Not s(): it keeps numeric references such as "&#38;", so they would show as "&". Escape everything once.
        $escape = static fn(string $name): string => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
        $select = new \single_select(
            new \moodle_url('/local/merqamreports/overview.php'),
            'category',
            array_map($escape, $options),
            $chosen ?? ''
        );
        $select->set_label(get_string('overview_company', 'local_merqamreports'));
        return $select;
    }

    /**
     * Decide which company to show from the request.
     *
     * @param int[] $optionids ids from {@see get_company_options()}
     * @param mixed $raw the raw request value; null when the parameter is absent
     * @return int|null the company to show; null = show only the selector (several options, no choice)
     * @throws \required_capability_exception for anything else
     */
    public static function resolve_requested_category(array $optionids, mixed $raw): ?int {
        if ($raw === null) {
            if (count($optionids) === 1) {
                return (int)reset($optionids);
            }
            if (count($optionids) > 1) {
                return null;
            }
            throw self::denied();
        }
        // Compare the exact string with the allowed ids, before any cast or lookup: no array, no
        // "5abc", "5\n", "007", "-5" or oversized number can match.
        if (!is_string($raw) || !in_array($raw, array_map('strval', $optionids), true)) {
            throw self::denied();
        }
        return (int)$raw;
    }

    /**
     * Everything the page needs, decided before any output.
     *
     * The summary query runs only when one company is chosen: with several options and no choice
     * (the admin's first visit) or with nothing configured, 'summary' is null.
     *
     * @param mixed $raw the raw request value of "category"; null when absent
     * @param int $now the moment to judge statuses at
     * @param int|null $userid null for the current user
     * @return array with keys options, notconfigured (bool), chosen (int|null), summary (array|null)
     * @throws \required_capability_exception
     */
    public static function prepare_page(mixed $raw, int $now, ?int $userid = null): array {
        $options = self::get_company_options($userid);
        $notconfigured = !$options && is_siteadmin($userid) && $raw === null;
        $chosen = $notconfigured ? null : self::resolve_requested_category(array_keys($options), $raw);
        return [
            'options' => $options,
            'notconfigured' => $notconfigured,
            'chosen' => $chosen,
            'summary' => $chosen === null ? null : self::get_company_summary($chosen, $now, $userid),
        ];
    }

    /**
     * The overview numbers of one company.
     *
     * @param int $categoryid a company category id (checked again here)
     * @param int $now the moment to judge statuses and the due window at
     * @param int|null $userid null for the current user
     * @return array with keys total, completed, overdue, duesoon, certificates, completionrate (float|null),
     *               statuses (status => count) and programs (list of [id, name, statuses])
     * @throws \required_capability_exception when the user may not open this company
     */
    public static function get_company_summary(int $categoryid, int $now, ?int $userid = null): array {
        global $DB;

        if (!array_key_exists($categoryid, self::get_company_options($userid))) {
            throw self::denied();
        }

        [$statussql, $statusparams] = status::get_sql('a', 'p', $now);
        [$companysql, $companyparams] = company_access::get_category_condition('ctx.instanceid', $userid);

        $sql = "SELECT x.programid, x.status, COUNT(1) AS total, SUM(x.duesoon) AS duesoon, SUM(x.hascert) AS certificates
                  FROM (
                        SELECT p.id AS programid,
                               {$statussql} AS status,
                               CASE WHEN a.timedue > :duefrom AND a.timedue <= :dueto THEN 1 ELSE 0 END AS duesoon,
                               CASE WHEN EXISTS (
                                        SELECT 1
                                          FROM {tool_muprog_cert_issue} pci
                                          JOIN {tool_certificate_issues} ti
                                               ON ti.id = pci.issueid AND ti.component = :component
                                         WHERE pci.allocationid = a.id
                                    ) THEN 1 ELSE 0 END AS hascert
                          FROM {tool_muprog_allocation} a
                          JOIN {tool_muprog_program} p ON p.id = a.programid
                          JOIN {context} ctx ON ctx.id = p.contextid AND ctx.contextlevel = :catlevel
                          JOIN {user} u ON u.id = a.userid
                         WHERE ctx.instanceid = :categoryid
                               AND {$companysql}
                               AND p.draft = 0
                               AND p.archived = 0
                               AND a.archived = 0
                               AND u.deleted = 0
                       ) x
              GROUP BY x.programid, x.status";
        $params = $statusparams + $companyparams + [
            'duefrom' => $now,
            'dueto' => $now + self::DUESOON_DAYS * DAYSECS,
            'component' => 'tool_muprog',
            'catlevel' => CONTEXT_COURSECAT,
            'categoryid' => $categoryid,
        ];

        $summary = [
            'total' => 0,
            'completed' => 0,
            'overdue' => 0,
            'duesoon' => 0,
            'certificates' => 0,
            'completionrate' => null,
            'statuses' => array_fill_keys(self::STATUSES, 0),
            'programs' => [],
        ];
        $perprogram = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $programid = (int)$row->programid;
            $rowstatus = (int)$row->status;
            $count = (int)$row->total;
            if (!array_key_exists($rowstatus, $summary['statuses'])) {
                continue; // Archived statuses can't occur (excluded above); kept out to be safe.
            }
            $summary['total'] += $count;
            $summary['statuses'][$rowstatus] += $count;
            $summary['certificates'] += (int)$row->certificates;
            if ($rowstatus === status::OPEN) {
                $summary['duesoon'] += (int)$row->duesoon;
            }
            $perprogram[$programid] ??= array_fill_keys(self::STATUSES, 0);
            $perprogram[$programid][$rowstatus] += $count;
        }
        $rs->close();

        $summary['completed'] = $summary['statuses'][status::COMPLETED];
        $summary['overdue'] = $summary['statuses'][status::OVERDUE];
        if ($summary['total'] > 0) {
            $summary['completionrate'] = 100 * $summary['completed'] / $summary['total'];
        }

        if ($perprogram) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($perprogram), SQL_PARAMS_NAMED);
            $programs = $DB->get_records_select(
                'tool_muprog_program',
                "id {$insql}",
                $inparams,
                'fullname, id',
                'id, fullname, contextid'
            );
            foreach ($programs as $program) {
                $summary['programs'][] = [
                    'id' => (int)$program->id,
                    'name' => format_string($program->fullname, true, [
                        'context' => context::instance_by_id($program->contextid),
                        'escape' => false, // Plain text: the charts and their data table escape it.
                    ]),
                    'statuses' => $perprogram[(int)$program->id],
                ];
            }
        }

        return $summary;
    }
}
