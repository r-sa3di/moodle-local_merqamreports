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

/**
 * Tests for the allocation entity's percentage display.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(allocation::class)]
final class allocation_test extends \basic_testcase {
    /**
     * Test: whole numbers, and 100% only when the value really is 100.
     */
    public function test_whole_percent(): void {
        $this->assertSame(get_string('percents', 'moodle', '0'), allocation::whole_percent(0.0));
        $this->assertSame(get_string('percents', 'moodle', '75'), allocation::whole_percent(75.0));
        $this->assertSame(get_string('percents', 'moodle', '67'), allocation::whole_percent(66.6667));
        $this->assertSame(get_string('percents', 'moodle', '99'), allocation::whole_percent(99.9));
        $this->assertSame(get_string('percents', 'moodle', '100'), allocation::whole_percent(100.0));
    }
}
