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

namespace local_merqamreports\output;

use local_merqamreports\local\overview_service;
use local_merqamreports\local\status;
use local_merqamreports\reportbuilder\local\entities\allocation;

/**
 * The "Company overview" page content: tiles and the two charts of one company.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overview implements \renderable, \templatable {
    /** @var string[] Colour of each status, the same in both charts (canon palette, red for Overdue). */
    public const COLOURS = [
        status::OVERDUE => '#C0392B',
        status::OPEN => '#011D5A',
        status::NOTSTARTED => '#8A94A6',
        status::ENDED => '#B7791F',
        status::COMPLETED => '#049151',
    ];

    /**
     * Constructor.
     *
     * @param string $companyname formatted company name
     * @param array $summary from {@see overview_service::get_company_summary()}
     */
    public function __construct(
        public readonly string $companyname,
        public readonly array $summary,
    ) {
    }

    /**
     * Doughnut chart: allocations by status.
     *
     * @return \core\chart_pie
     */
    public function get_status_chart(): \core\chart_pie {
        $labels = status::get_labels();
        $chart = new \core\chart_pie();
        $chart->set_doughnut(true);
        $chart->set_title(get_string('chart_status', 'local_merqamreports'));
        $values = [];
        $names = [];
        $colours = [];
        foreach (overview_service::STATUSES as $code) {
            $values[] = $this->summary['statuses'][$code];
            $names[] = $labels[$code];
            $colours[] = self::COLOURS[$code];
        }
        $series = new \core\chart_series(get_string('chart_allocations', 'local_merqamreports'), $values);
        $series->set_colors($colours);
        $chart->add_series($series);
        $chart->set_labels($names);
        return $chart;
    }

    /**
     * Stacked horizontal bars: status per program.
     *
     * @return \core\chart_bar
     */
    public function get_programs_chart(): \core\chart_bar {
        $labels = status::get_labels();
        $chart = new \core\chart_bar();
        $chart->set_horizontal(true);
        $chart->set_stacked(true);
        $chart->set_title(get_string('chart_programs', 'local_merqamreports'));
        foreach (overview_service::STATUSES as $code) {
            $values = array_map(static fn(array $program): int => $program['statuses'][$code], $this->summary['programs']);
            $series = new \core\chart_series($labels[$code], $values);
            $series->set_color(self::COLOURS[$code]);
            $chart->add_series($series);
        }
        $chart->set_labels(array_map(static fn(array $program): string => $program['name'], $this->summary['programs']));
        return $chart;
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        $total = $this->summary['total'];
        $rate = $this->summary['completionrate'];
        $none = get_string('tile_noallocations', 'local_merqamreports');
        return [
            'companyname' => $this->companyname,
            'hasallocations' => $total > 0,
            'total' => get_string('tile_total', 'local_merqamreports', $total),
            'tiles' => [
                [
                    'key' => 'completionrate',
                    'label' => get_string('tile_completionrate', 'local_merqamreports'),
                    'value' => $rate === null ? $none : allocation::whole_percent($rate),
                ],
                [
                    'key' => 'overdue',
                    'label' => get_string('tile_overdue', 'local_merqamreports'),
                    'value' => $total ? (string)$this->summary['overdue'] : $none,
                ],
                [
                    'key' => 'duesoon',
                    'label' => get_string('tile_duesoon', 'local_merqamreports', overview_service::DUESOON_DAYS),
                    'value' => $total ? (string)$this->summary['duesoon'] : $none,
                ],
                [
                    'key' => 'certificates',
                    'label' => get_string('tile_certificates', 'local_merqamreports'),
                    'value' => $total ? (string)$this->summary['certificates'] : $none,
                ],
            ],
        ];
    }
}
