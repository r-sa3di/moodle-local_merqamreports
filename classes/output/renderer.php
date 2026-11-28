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

/**
 * Renderer for local_merqamreports.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * The overview: tiles from the template, then both charts through Moodle's normal chart renderer
     * (with its "Show chart data" table, for screen readers).
     *
     * @param overview $overview
     * @return string HTML
     */
    protected function render_overview(overview $overview): string {
        $data = $overview->export_for_template($this);
        if ($data['hasallocations']) {
            $data['statuschart'] = $this->output->render_chart($overview->get_status_chart(), true);
            $data['programschart'] = $this->output->render_chart($overview->get_programs_chart(), true);
        }
        return $this->render_from_template('local_merqamreports/overview', $data);
    }
}
