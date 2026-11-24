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

/**
 * Required progress of a program allocation, counting only what is needed for completion.
 *
 * Pure calculation without database access, so it can be unit tested on its own.
 * The program structure is a tree of plain arrays (see {@see self::node()}), built
 * from MuTMS content by {@see progress_service}.
 *
 * Rules, matching how MuTMS evaluates completion:
 * - any item with an effective completion record (completion date not in the future) is 100 %;
 * - a leaf (course, offline attendance, training credits) without one is 0 %;
 * - "all in any order" and "all in order" sets: the average of their children;
 * - "at least N" sets: the average of the N best children;
 * - "minimum points" sets: points of completed direct children divided by the minimum, at most 100 %.
 *
 * @package    local_merqamreports
 * @copyright  2026 Almerqam
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_calculator {
    /** @var string Leaf item: course, attendance or credits. */
    public const TYPE_LEAF = 'leaf';
    /** @var string Set of items. */
    public const TYPE_SET = 'set';

    /** @var string Same value as \tool_muprog\local\content\set::SEQUENCE_TYPE_ALLINANYORDER. */
    public const SEQUENCE_ALLINANYORDER = 'allinanyorder';
    /** @var string Same value as \tool_muprog\local\content\set::SEQUENCE_TYPE_ALLINORDER. */
    public const SEQUENCE_ALLINORDER = 'allinorder';
    /** @var string Same value as \tool_muprog\local\content\set::SEQUENCE_TYPE_ATLEAST. */
    public const SEQUENCE_ATLEAST = 'atleast';
    /** @var string Same value as \tool_muprog\local\content\set::SEQUENCE_TYPE_MINPOINTS. */
    public const SEQUENCE_MINPOINTS = 'minpoints';

    /**
     * Build one tree node.
     *
     * @param int $id MuTMS item id
     * @param string $type self::TYPE_LEAF or self::TYPE_SET
     * @param int $points points this item gives to a "minimum points" parent
     * @param string|null $sequence set completion type (sets only)
     * @param int|null $min required number of children for "at least" sets
     * @param int|null $minpoints required points for "minimum points" sets
     * @param array $children child nodes (sets only)
     * @return array
     */
    public static function node(
        int $id,
        string $type,
        int $points = 1,
        ?string $sequence = null,
        ?int $min = null,
        ?int $minpoints = null,
        array $children = []
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'points' => $points,
            'sequence' => $sequence,
            'min' => $min,
            'minpoints' => $minpoints,
            'children' => $children,
        ];
    }

    /**
     * Calculate the required progress of one node.
     *
     * @param array $node tree node from {@see self::node()}
     * @param array $completed item ids with an effective completion, as keys (itemid => true)
     * @return float between 0.0 and 1.0
     */
    public static function calculate(array $node, array $completed): float {
        if (isset($completed[$node['id']])) {
            return 1.0;
        }
        if ($node['type'] !== self::TYPE_SET || empty($node['children'])) {
            return 0.0;
        }

        $children = $node['children'];
        $fractions = [];
        foreach ($children as $child) {
            $fractions[] = self::calculate($child, $completed);
        }
        $count = count($fractions);

        switch ($node['sequence']) {
            case self::SEQUENCE_ATLEAST:
                // Not capped at the number of children: like MuTMS, an "at least 5" set with two
                // children can never complete, so it never reaches 100 % here either.
                $required = max(1, (int)($node['min'] ?? 1));
                rsort($fractions);
                return min(1.0, array_sum(array_slice($fractions, 0, $required)) / $required);

            case self::SEQUENCE_MINPOINTS:
                $minpoints = (int)($node['minpoints'] ?? 0);
                if ($minpoints <= 0) {
                    return 0.0;
                }
                $earned = 0;
                foreach ($children as $child) {
                    // Like MuTMS, only children with their own completion record give points.
                    if (isset($completed[$child['id']])) {
                        $earned += (int)$child['points'];
                    }
                }
                return min(1.0, $earned / $minpoints);

            case self::SEQUENCE_ALLINANYORDER:
            case self::SEQUENCE_ALLINORDER:
            default:
                return array_sum($fractions) / $count;
        }
    }
}
