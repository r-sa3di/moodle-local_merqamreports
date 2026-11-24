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

use core\context;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use stdClass;

/**
 * Program entity (MuTMS tool_muprog_program).
 *
 * There is deliberately no "select a program" filter: its option list would show the names of
 * other companies' programs. Text filters only.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class program extends base {
    #[\Override]
    protected function get_default_tables(): array {
        return ['tool_muprog_program'];
    }

    #[\Override]
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_program', 'local_merqamreports');
    }

    #[\Override]
    protected function get_available_columns(): array {
        $p = $this->get_table_alias('tool_muprog_program');

        $columns[] = (new column(
            'fullname',
            new lang_string('program_name', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$p}.fullname")
            ->add_field("{$p}.contextid")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $value, stdClass $row): string {
                if ($value === null) {
                    return '';
                }
                $context = context::instance_by_id((int)$row->contextid, IGNORE_MISSING);
                return format_string($value, true, ['context' => $context ?: \core\context\system::instance()]);
            });

        $columns[] = (new column(
            'idnumber',
            new lang_string('program_idnumber', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$p}.idnumber")
            ->set_is_sortable(true)
            ->add_callback(static fn(?string $value): string => s($value ?? ''));

        $columns[] = (new column(
            'archived',
            new lang_string('program_archived', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$p}.archived")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']);

        return $columns;
    }

    #[\Override]
    protected function get_available_filters(): array {
        $p = $this->get_table_alias('tool_muprog_program');

        $filters[] = (new filter(
            text::class,
            'fullname',
            new lang_string('program_name', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$p}.fullname"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            text::class,
            'idnumber',
            new lang_string('program_idnumber', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$p}.idnumber"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            boolean_select::class,
            'archived',
            new lang_string('program_archived', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$p}.archived"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
