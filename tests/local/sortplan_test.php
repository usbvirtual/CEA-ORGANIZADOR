<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_ceaorganizador\local;

// Coverage declared twice: the attribute for PHPUnit 11 and the @covers annotation for PHPUnit 9.6.
#[\PHPUnit\Framework\Attributes\CoversClass(sortplan::class)]
/**
 * Tests for the sort plan of one or several categories.
 *
 * New categories are appended last among their siblings; new courses go first in their category.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\sortplan
 */
final class sortplan_test extends \advanced_testcase {
    /**
     * A category under a parent.
     *
     * @param string $name
     * @param int $parent
     * @return int
     */
    private function category(string $name, int $parent = 0): int {
        return (int) $this->getDataGenerator()->create_category(['name' => $name, 'parent' => $parent])->id;
    }

    /**
     * The lists a plan changes, as 'kind:parent'.
     *
     * @param array $plan
     * @return string[]
     */
    private function keys(array $plan): array {
        return array_map(fn(array $change): string => $change['kind'] . ':' . $change['parentid'], $plan['changes']);
    }

    /**
     * A selected category and its descendant give the same plan as the ancestor alone, with no repeated list.
     */
    public function test_build_many_dedupes_descendants(): void {
        $this->resetAfterTest();
        $parent = $this->category('P');
        $this->category('C', $parent);
        $inner = $this->category('A', $parent);
        $this->category('Z', $inner);
        $this->category('Y', $inner);

        $alone = sortplan::build_many([$parent], 'categories', true);
        $many = sortplan::build_many([$inner, $parent, $parent, $inner], 'categories', true);

        $this->assertCount(2, $alone['changes']);
        $this->assertEqualsCanonicalizing(['categories:' . $parent, 'categories:' . $inner], $this->keys($many));
        $this->assertSame($alone['hash'], $many['hash']);

        // Without recursion each selected category sorts only its own children.
        $flat = sortplan::build_many([$parent, $inner], 'categories', false);
        $this->assertEqualsCanonicalizing(['categories:' . $parent, 'categories:' . $inner], $this->keys($flat));
    }

    /**
     * With one id the plan is the one of build(); the signature does not depend on the order of the selection.
     */
    public function test_build_many_union_same_hash_as_single(): void {
        $this->resetAfterTest();
        $first = $this->category('P1');
        $this->category('C', $first);
        $this->category('A', $first);
        $second = $this->category('P2');
        $this->category('Y', $second);
        $this->category('X', $second);

        $this->assertSame(
            sortplan::build($first, 'both', true),
            sortplan::build_many([$first], 'both', true)
        );
        $one = sortplan::build_many([$first, $second], 'categories', false);
        $other = sortplan::build_many([$second, $first], 'categories', false);

        $this->assertCount(2, $one['changes']);
        $this->assertSame($one['hash'], $other['hash']);
        $this->assertNotSame(sortplan::build($first, 'categories', false)['hash'], $one['hash']);
    }

    /**
     * The top level cannot be mixed with other categories.
     */
    public function test_build_many_rejects_top_level_with_others(): void {
        $this->resetAfterTest();
        $parent = $this->category('P');

        $this->expectException(\coding_exception::class);
        sortplan::build_many([0, $parent], 'both', false);
    }
}
