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

/**
 * Version information for local_merqamreports.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_merqamreports';
$plugin->version = 2026112400;
$plugin->release = '0.1.0';
$plugin->maturity = MATURITY_ALPHA;
$plugin->requires = 2026091600; // Moodle 5.3.
$plugin->supported = [503, 503];
$plugin->dependencies = [
    'tool_muprog' => 2026111753,
    'tool_certificate' => 2026111000, // The tested v5.0.12.
];
