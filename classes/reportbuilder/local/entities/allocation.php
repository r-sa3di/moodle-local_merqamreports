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
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_merqamreports\local\progress_service;
use local_merqamreports\local\status;
use stdClass;

/**
 * Program allocation entity (MuTMS tool_muprog_allocation): one learner in one program.
 *
 * Needs the program table joined by the data source under the alias set with
 * set_table_alias('tool_muprog_program', ...).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allocation extends base {
    #[\Override]
    protected function get_default_tables(): array {
        return [
            'tool_muprog_allocation',
            'tool_muprog_program',
            'tool_muprog_source',
        ];
    }

    #[\Override]
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_allocation', 'local_merqamreports');
    }

    /**
     * Join for the allocation source (how the learner was allocated).
     *
     * @return string
     */
    public function get_source_join(): string {
        $a = $this->get_table_alias('tool_muprog_allocation');
        $s = $this->get_table_alias('tool_muprog_source');
        return "LEFT JOIN {tool_muprog_source} {$s} ON {$s}.id = {$a}.sourceid";
    }

    #[\Override]
    protected function get_available_columns(): array {
        $a = $this->get_table_alias('tool_muprog_allocation');
        $p = $this->get_table_alias('tool_muprog_program');
        $s = $this->get_table_alias('tool_muprog_source');

        // Status: a number from SQL, sortable (overdue first), shown with our own labels.
        [$statussql, $statusparams] = status::get_sql($a, $p);
        $columns[] = (new column(
            'status',
            new lang_string('allocation_status', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($statussql, 'status', $statusparams)
            ->set_is_sortable(true)
            ->set_disabled_aggregation_all()
            ->add_callback(static fn(?int $value): string => status::get_label($value));

        // Required progress: worked out per row, shown and downloaded, not sortable or filterable.
        $columns[] = (new column(
            'requiredprogress',
            new lang_string('allocation_requiredprogress', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$a}.id", 'allocationid')
            ->add_field("{$a}.programid")
            ->add_field("{$a}.timecompleted")
            ->set_is_sortable(false)
            ->set_disabled_aggregation_all()
            ->add_callback(static function ($value, stdClass $row): string {
                if (empty($row->allocationid)) {
                    return '';
                }
                $percentage = progress_service::get_required_percentage(
                    (int)$row->programid,
                    (int)$row->allocationid,
                    $row->timecompleted ? (int)$row->timecompleted : null
                );
                return $percentage === null ? '' : format::percent(round($percentage));
            });

        // MuTMS's own progress (completed items / all items), kept for comparison.
        $columns[] = (new column(
            'mutmsprogress',
            new lang_string('allocation_mutmsprogress', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field("CASE WHEN {$p}.itemscount > 0
                              THEN 100.0 * {$a}.itemscompleted / {$p}.itemscount
                              ELSE NULL END", 'mutmsprogress')
            ->set_is_sortable(true)
            ->add_callback(static fn(?float $value): string => $value === null ? '' : format::percent(round($value)));

        // Dates.
        $dates = [
            'timeallocated' => 'allocation_timeallocated',
            'timestart' => 'allocation_timestart',
            'timedue' => 'allocation_timedue',
            'timeend' => 'allocation_timeend',
            'timecompleted' => 'allocation_timecompleted',
        ];
        foreach ($dates as $field => $stringid) {
            $columns[] = (new column(
                $field,
                new lang_string($stringid, 'local_merqamreports'),
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_TIMESTAMP)
                ->add_field("{$a}.{$field}")
                ->set_is_sortable(true)
                ->add_callback([format::class, 'userdate'], get_string('strftimedatetimeshort', 'langconfig'));
        }

        // Days overdue: only for the Overdue status, otherwise empty.
        [$statussql2, $statusparams2] = status::get_sql($a, $p);
        $columns[] = (new column(
            'daysoverdue',
            new lang_string('allocation_daysoverdue', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$a}.timedue", 'timedue')
            ->add_field($statussql2, 'status', $statusparams2)
            ->set_is_sortable(false)
            ->set_disabled_aggregation_all()
            ->add_callback(static function ($value, stdClass $row): string {
                if ((int)($row->status ?? 0) !== status::OVERDUE || empty($row->timedue)) {
                    return '';
                }
                $days = (int)floor((time() - (int)$row->timedue) / DAYSECS);
                return (string)max(1, $days);
            });

        // Allocation source: how the learner was allocated.
        $columns[] = (new column(
            'source',
            new lang_string('allocation_source', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_source_join())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$s}.type")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $value): string {
                if ($value === null || $value === '') {
                    return '';
                }
                $stringid = 'source_' . $value;
                if (get_string_manager()->string_exists($stringid, 'local_merqamreports')) {
                    return get_string($stringid, 'local_merqamreports');
                }
                return s($value);
            });

        $columns[] = (new column(
            'archived',
            new lang_string('allocation_archived', 'local_merqamreports'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$a}.archived")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']);

        return $columns;
    }

    #[\Override]
    protected function get_available_filters(): array {
        $a = $this->get_table_alias('tool_muprog_allocation');
        $p = $this->get_table_alias('tool_muprog_program');

        [$statussql, $statusparams] = status::get_sql($a, $p);
        $filters[] = (new filter(
            select::class,
            'status',
            new lang_string('allocation_status', 'local_merqamreports'),
            $this->get_entity_name(),
            $statussql,
            $statusparams
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static fn(): array => status::get_labels());

        $dates = [
            'timeallocated' => 'allocation_timeallocated',
            'timestart' => 'allocation_timestart',
            'timedue' => 'allocation_timedue',
            'timeend' => 'allocation_timeend',
            'timecompleted' => 'allocation_timecompleted',
        ];
        foreach ($dates as $field => $stringid) {
            $filters[] = (new filter(
                date::class,
                $field,
                new lang_string($stringid, 'local_merqamreports'),
                $this->get_entity_name(),
                "{$a}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        $filters[] = (new filter(
            boolean_select::class,
            'archived',
            new lang_string('allocation_archived', 'local_merqamreports'),
            $this->get_entity_name(),
            "{$a}.archived"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
