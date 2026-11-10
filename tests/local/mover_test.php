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

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(mover::class)]
/**
 * Tests for the mover, against the test database.
 *
 * New categories are appended last among their siblings; new courses go first in their category.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\mover
 */
final class mover_test extends \advanced_testcase {
    /**
     * Id of the default top-level category created by the installer.
     *
     * @return int
     */
    private function misc(): int {
        global $DB;
        return (int) $DB->get_field_select('course_categories', 'MIN(id)', 'parent = 0');
    }

    /**
     * Runs a callable and checks it throws the given plugin error.
     *
     * @param string $code Error code without the 'error_' prefix.
     * @param callable $fn
     */
    private function assert_error(string $code, callable $fn): void {
        try {
            $fn();
            $this->fail("Expected error_$code");
        } catch (\moodle_exception $e) {
            $this->assertSame('error_' . $code, $e->errorcode);
            $this->assertSame('tool_ceaorganizador', $e->module);
        }
    }

    /**
     * A root category moves into another one as its first child.
     */
    public function test_move_category_to_new_parent_and_position(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B'])->id;
        $c = (int) $generator->create_category(['name' => 'C'])->id;
        $a1 = (int) $generator->create_category(['name' => 'A1', 'parent' => $a])->id;
        $generator->create_course(['category' => $a]);

        $result = mover::move_category($c, $a, 0);

        $this->assertSame($a, (int) $DB->get_field('course_categories', 'parent', ['id' => $c]));
        $this->assertSame([$c, $a1], tree::sibling_categories($a));
        $this->assertSame([$this->misc(), $a, $b], tree::sibling_categories(0));
        $this->assertSame(['parent' => 0, 'afterid' => $b], $result['from']);
        $this->assertSame(['parent' => $a, 'afterid' => 0], $result['to']);
    }

    /**
     * A category moves after a sibling under the same parent.
     */
    public function test_move_category_after_sibling(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B'])->id;
        $c = (int) $generator->create_category(['name' => 'C'])->id;
        // Courses make the sortorders interleave, as on a real site.
        $generator->create_course(['category' => $a]);
        $generator->create_course(['category' => $b]);
        $generator->create_course(['category' => $b]);
        $misc = $this->misc();

        $result = mover::move_category($a, 0, $c);

        $this->assertSame([$misc, $b, $c, $a], tree::sibling_categories(0));
        $this->assertSame(['parent' => 0, 'afterid' => $misc], $result['from']);
        $this->assertSame(['parent' => 0, 'afterid' => $c], $result['to']);
    }

    /**
     * The descendant check uses the tree at the time of the move, not an older view.
     */
    public function test_move_category_into_own_descendant_after_external_change(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B'])->id;

        // Another admin puts B inside A after the tree was loaded.
        \core_course_category::get($b)->change_parent($a);

        $this->assert_error('cannotmoveintodescendant', fn() => mover::move_category($a, $b, 0));
        $this->assert_error('cannotmoveintodescendant', fn() => mover::move_category($a, $a, 0));
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'parent', ['id' => $a]));
        $this->assertSame($a, (int) $DB->get_field('course_categories', 'parent', ['id' => $b]));
    }

    /**
     * A course moves into another category at the given position.
     */
    public function test_move_course_between_categories_with_position(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $x = (int) $generator->create_category(['name' => 'X'])->id;
        $y = (int) $generator->create_category(['name' => 'Y'])->id;
        $x1 = (int) $generator->create_course(['category' => $x])->id;
        $x2 = (int) $generator->create_course(['category' => $x])->id;
        $generator->create_course(['category' => $y]);
        $generator->create_course(['category' => $y]);
        $xbefore = tree::category_courses($x);
        $ybefore = tree::category_courses($y);
        $this->assertSame([$x2, $x1], $xbefore);

        $result = mover::move_course($x1, $y, $ybefore[0]);

        $this->assertSame($y, (int) $DB->get_field('course', 'category', ['id' => $x1]));
        $this->assertSame([$ybefore[0], $x1, $ybefore[1]], tree::category_courses($y));
        $this->assertSame([$x2], tree::category_courses($x));
        $this->assertSame(['category' => $x, 'afterid' => $x2], $result['from']);
        $this->assertSame(['category' => $y, 'afterid' => $ybefore[0]], $result['to']);

        // Back to the first position of X.
        mover::move_course($x1, $x, 0);
        $this->assertSame([$x1, $x2], tree::category_courses($x));

        // A course of another category is not a valid position.
        $this->assert_error('aftermismatch', fn() => mover::move_course($x1, $x, $ybefore[0]));
        $this->assertSame([$x1, $x2], tree::category_courses($x));
    }

    /**
     * The category order must contain exactly the current children.
     */
    public function test_apply_category_order_rejects_wrong_set(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B', 'parent' => $a])->id;
        $c = (int) $generator->create_category(['name' => 'C', 'parent' => $a])->id;
        $other = (int) $generator->create_category(['name' => 'Otra'])->id;

        $this->assert_error('ordermismatch', fn() => mover::apply_category_order($a, [$b]));
        $this->assert_error('ordermismatch', fn() => mover::apply_category_order($a, [$b, $c, $other]));
        $this->assert_error('ordermismatch', fn() => mover::apply_category_order($a, [$b, $other]));
        $this->assert_error('ordermismatch', fn() => mover::apply_category_order($a, [$b, $b]));
        $this->assertSame([$b, $c], tree::sibling_categories($a));

        mover::apply_category_order($a, [$c, $b]);
        $this->assertSame([$c, $b], tree::sibling_categories($a));
    }

    /**
     * The course order is applied exactly.
     */
    public function test_apply_course_order_sets_exact_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $cat = (int) $generator->create_category(['name' => 'K'])->id;
        $c1 = (int) $generator->create_course(['category' => $cat])->id;
        $c2 = (int) $generator->create_course(['category' => $cat])->id;
        $c3 = (int) $generator->create_course(['category' => $cat])->id;
        $this->assertSame([$c3, $c2, $c1], tree::category_courses($cat));

        mover::apply_course_order($cat, [$c2, $c1, $c3]);
        $this->assertSame([$c2, $c1, $c3], tree::category_courses($cat));

        $this->assert_error('ordermismatch', fn() => mover::apply_course_order($cat, [$c1, $c2]));
        $this->assertSame([$c2, $c1, $c3], tree::category_courses($cat));
    }

    /**
     * Renaming a course changes the short name only when one is given.
     */
    public function test_rename_course_shortname(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Viejo', 'shortname' => 'VIEJO']);
        $id = (int) $course->id;

        mover::rename_course($id, 'Nuevo', 'NUEVO');
        $record = $DB->get_record('course', ['id' => $id]);
        $this->assertSame('Nuevo', $record->fullname);
        $this->assertSame('NUEVO', $record->shortname);

        mover::rename_course($id, 'Nuevo 2', null);
        $record = $DB->get_record('course', ['id' => $id]);
        $this->assertSame('Nuevo 2', $record->fullname);
        $this->assertSame('NUEVO', $record->shortname);
    }
}
