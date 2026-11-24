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

namespace local_merqamreports\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;

/**
 * Program certificate entity: the certificate issued for a program allocation, if any.
 *
 * Joined through a sub-query that returns at most one issue per allocation (the latest one from
 * the tool_muprog component), because MuTMS does not enforce one issue per allocation in the
 * database. So this entity never repeats allocation rows.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate extends base {
    #[\Override]
    protected function get_default_tables(): array {
        return ['tool_certificate_issues'];
    }

    #[\Override]
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_certificate', 'local_merqamreports');
    }

    /**
     * Joins from an allocation id field to its latest program certificate issue.
     *
     * @param string $allocationidfield SQL field holding tool_muprog_allocation.id
     * @return string[]
     */
    public function get_issue_joins(string $allocationidfield): array {
        $ci = $this->get_table_alias('tool_certificate_issues');
        $latest = database::generate_alias();
        $innerci = database::generate_alias();
        $innerti = database::generate_alias();

        return [
            "LEFT JOIN (
                SELECT {$innerci}.allocationid, MAX({$innerci}.issueid) AS issueid
                  FROM {tool_muprog_cert_issue} {$innerci}
                  JOIN {tool_certificate_issues} {$innerti}
                       ON {$innerti}.id = {$innerci}.issueid AND {$innerti}.component = 'tool_muprog'
              GROUP BY {$innerci}.allocationid
             ) {$latest} ON {$latest}.allocationid = {$allocationidfield}",
            "LEFT JOIN {tool_certificate_issues} {$ci} ON {$ci}.id = {$latest}.issueid",
        ];
    }

    #[\Override]
    protected function get_available_columns(): array {
        $ci = $this->get_table_alias('tool_certificate_issues');
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');

        $columns[] = (new column(
            'code',
            new lang_string('certificate_code', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$ci}.code")
            ->set_is_sortable(true)
            ->add_callback(static fn(?string $value): string => s($value ?? ''));

        $columns[] = (new column(
            'timecreated',
            new lang_string('certificate_timecreated', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$ci}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate'], $dateformat);

        $columns[] = (new column(
            'expires',
            new lang_string('certificate_expires', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$ci}.expires")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate'], $dateformat);

        $columns[] = (new column(
            'archived',
            new lang_string('certificate_archived', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$ci}.archived")
            ->set_is_sortable(true)
            ->add_callback(static fn(?bool $value): string => $value === null ? '' : format::boolean_as_text($value));

        return $columns;
    }

    #[\Override]
    protected function get_available_filters(): array {
        $ci = $this->get_table_alias('tool_certificate_issues');

        $filters[] = (new filter(
            text::class,
            'code',
            new lang_string('certificate_code', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$ci}.code"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('certificate_timecreated', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$ci}.timecreated"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            boolean_select::class,
            'archived',
            new lang_string('certificate_archived', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$ci}.archived"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
