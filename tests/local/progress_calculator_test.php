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

use local_merqamreports\local\progress_calculator as calc;

/**
 * Unit tests for the required-progress calculation (no database needed).
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(progress_calculator::class)]
final class progress_calculator_test extends \basic_testcase {
    /**
     * The phase C "Safety program": top All (Set 1 All: 11, 12; Set 2 At least 1: 21, 22).
     *
     * @return array
     */
    private function safety_program(): array {
        $set1 = calc::node(10, calc::TYPE_SET, 1, calc::SEQUENCE_ALLINANYORDER, 2, null, [
            calc::node(11, calc::TYPE_LEAF),
            calc::node(12, calc::TYPE_LEAF),
        ]);
        $set2 = calc::node(20, calc::TYPE_SET, 1, calc::SEQUENCE_ATLEAST, 1, null, [
            calc::node(21, calc::TYPE_LEAF),
            calc::node(22, calc::TYPE_LEAF),
        ]);
        return calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_ALLINANYORDER, 2, null, [$set1, $set2]);
    }

    /**
     * Turn a list of item ids into the completed map.
     *
     * @param int[] $ids
     * @return array
     */
    private function done(array $ids): array {
        return array_fill_keys($ids, true);
    }

    /**
     * Test: safety program matches phase c.
     */
    public function test_safety_program_matches_phase_c(): void {
        $tree = $this->safety_program();

        $this->assertEqualsWithDelta(0.0, calc::calculate($tree, []), 0.0001);

        // Round 1: S1A and S2A done, MuTMS also records the Set 2 completion.
        $this->assertEqualsWithDelta(0.75, calc::calculate($tree, $this->done([11, 21, 20])), 0.0001);

        // Same without the Set 2 completion record: the set is still worked out from its children.
        $this->assertEqualsWithDelta(0.75, calc::calculate($tree, $this->done([11, 21])), 0.0001);

        // Round 2: everything required done.
        $this->assertEqualsWithDelta(1.0, calc::calculate($tree, $this->done([11, 12, 21, 10, 20])), 0.0001);

        // Round 3 (learner5): Set 1 complete, nothing in Set 2.
        $this->assertEqualsWithDelta(0.5, calc::calculate($tree, $this->done([11, 12, 10])), 0.0001);
    }

    /**
     * Test: completion record of a set counts as complete.
     */
    public function test_completion_record_of_a_set_counts_as_complete(): void {
        $tree = $this->safety_program();
        // Program (top item) completion record wins, for example after a completion override.
        $this->assertEqualsWithDelta(1.0, calc::calculate($tree, $this->done([1])), 0.0001);
        // Set 1 overridden as complete without its courses.
        $this->assertEqualsWithDelta(0.5, calc::calculate($tree, $this->done([10])), 0.0001);
    }

    /**
     * Test: all in order is an average.
     */
    public function test_all_in_order_is_an_average(): void {
        $tree = calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_ALLINORDER, 3, null, [
            calc::node(2, calc::TYPE_LEAF),
            calc::node(3, calc::TYPE_LEAF),
            calc::node(4, calc::TYPE_LEAF),
        ]);
        $this->assertEqualsWithDelta(1 / 3, calc::calculate($tree, $this->done([2])), 0.0001);
        $this->assertEqualsWithDelta(2 / 3, calc::calculate($tree, $this->done([2, 4])), 0.0001);
    }

    /**
     * Test: at least two uses the best children.
     */
    public function test_at_least_two_uses_the_best_children(): void {
        $nested = calc::node(5, calc::TYPE_SET, 1, calc::SEQUENCE_ALLINANYORDER, 2, null, [
            calc::node(6, calc::TYPE_LEAF),
            calc::node(7, calc::TYPE_LEAF),
        ]);
        $tree = calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_ATLEAST, 2, null, [
            calc::node(2, calc::TYPE_LEAF),
            calc::node(3, calc::TYPE_LEAF),
            $nested,
        ]);
        // One leaf done: (1 + 0) / 2.
        $this->assertEqualsWithDelta(0.5, calc::calculate($tree, $this->done([2])), 0.0001);
        // One leaf and half of the nested set: (1 + 0.5) / 2.
        $this->assertEqualsWithDelta(0.75, calc::calculate($tree, $this->done([2, 6])), 0.0001);
        // Extra completions beyond the two required don't go above 100 %.
        $this->assertEqualsWithDelta(1.0, calc::calculate($tree, $this->done([2, 3, 6, 7])), 0.0001);
    }

    /**
     * Test: an impossible "at least N" (N above the number of children) never reaches 100 %, as in MuTMS.
     */
    public function test_at_least_more_than_children_never_completes(): void {
        $tree = calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_ATLEAST, 5, null, [
            calc::node(2, calc::TYPE_LEAF),
            calc::node(3, calc::TYPE_LEAF),
        ]);
        $this->assertEqualsWithDelta(0.2, calc::calculate($tree, $this->done([2])), 0.0001);
        $this->assertEqualsWithDelta(0.4, calc::calculate($tree, $this->done([2, 3])), 0.0001);
        // A completion record (e.g. an override) still counts as complete.
        $this->assertEqualsWithDelta(1.0, calc::calculate($tree, $this->done([1])), 0.0001);
    }

    /**
     * Test: minimum points counts only completed direct children.
     */
    public function test_minimum_points_counts_only_completed_direct_children(): void {
        $nested = calc::node(5, calc::TYPE_SET, 3, calc::SEQUENCE_ALLINANYORDER, 2, null, [
            calc::node(6, calc::TYPE_LEAF),
            calc::node(7, calc::TYPE_LEAF),
        ]);
        $tree = calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_MINPOINTS, null, 4, [
            calc::node(2, calc::TYPE_LEAF, 2),
            calc::node(3, calc::TYPE_LEAF, 1),
            $nested,
        ]);
        // Leaf worth 2 points: 2 / 4.
        $this->assertEqualsWithDelta(0.5, calc::calculate($tree, $this->done([2])), 0.0001);
        // Half of the nested set earns no points.
        $this->assertEqualsWithDelta(0.5, calc::calculate($tree, $this->done([2, 6])), 0.0001);
        // Nested set completed (has its own record): 2 + 3 = 5 points, capped at 100 %.
        $this->assertEqualsWithDelta(1.0, calc::calculate($tree, $this->done([2, 6, 7, 5])), 0.0001);
        // Without a minimum, nothing can be worked out.
        $nominimum = calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_MINPOINTS, null, null, [
            calc::node(2, calc::TYPE_LEAF, 2),
        ]);
        $this->assertEqualsWithDelta(0.0, calc::calculate($nominimum, $this->done([2])), 0.0001);
    }

    /**
     * Test: empty set and leaf.
     */
    public function test_empty_set_and_leaf(): void {
        $emptyset = calc::node(1, calc::TYPE_SET, 1, calc::SEQUENCE_ALLINANYORDER);
        $this->assertEqualsWithDelta(0.0, calc::calculate($emptyset, []), 0.0001);
        $this->assertEqualsWithDelta(0.0, calc::calculate(calc::node(2, calc::TYPE_LEAF), []), 0.0001);
        $this->assertEqualsWithDelta(1.0, calc::calculate(calc::node(2, calc::TYPE_LEAF), $this->done([2])), 0.0001);
    }
}
