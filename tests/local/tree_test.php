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
#[\PHPUnit\Framework\Attributes\CoversClass(tree::class)]
/**
 * Tests for the tree reader.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\tree
 */
final class tree_test extends \advanced_testcase {
    /**
     * Finds a row by category id.
     *
     * @param array $rows
     * @param int $id
     * @return array
     */
    private function row(array $rows, int $id): array {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("Category $id not found");
    }

    /**
     * Rows come in tree order and carry their path.
     */
    public function test_categories_have_path_and_traversal_order(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $misc = (int) $DB->get_field_select('course_categories', 'MIN(id)', 'parent = 0');
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B', 'parent' => $a])->id;
        $c = (int) $generator->create_category(['name' => 'C', 'parent' => $a])->id;
        $d = (int) $generator->create_category(['name' => 'D'])->id;

        $rows = tree::categories();
        $this->assertSame([$misc, $a, $b, $c, $d], array_column($rows, 'id'));
        $this->assertSame('A / B', $this->row($rows, $b)['path']);
        $this->assertSame($a, $this->row($rows, $b)['parent']);
        $this->assertSame(2, $this->row($rows, $b)['depth']);
    }

    /**
     * Children created out of order raise the flag until they are sorted.
     */
    public function test_needsort_flags_unsorted_children(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $generator->create_category(['name' => 'Semestre II', 'parent' => $a]);
        $generator->create_category(['name' => 'Semestre I', 'parent' => $a]);

        $this->assertTrue($this->row(tree::categories(), $a)['needsort']);

        \core_course_category::get($a)->resort_subcategories('name');
        $this->assertFalse($this->row(tree::categories(), $a)['needsort']);
    }

    /**
     * Courses out of order raise the flag too.
     */
    public function test_needsort_counts_courses(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = (int) $generator->create_category(['name' => 'Cursos'])->id;
        // Moodle puts each new course first, so creating A and then B leaves B before A.
        $generator->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $category]);
        $generator->create_course(['fullname' => 'B', 'shortname' => 'B', 'category' => $category]);

        $row = $this->row(tree::categories(), $category);
        $this->assertTrue($row['needsort']);
        $this->assertSame(2, $row['coursecount']);
    }

    /**
     * The top level has its own recommendation.
     */
    public function test_root_needsort(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->create_category(['name' => 'Facultad de Ingeniería']);
        $generator->create_category(['name' => '2026-02']);
        $this->assertTrue(tree::root_needsort());
    }

    /**
     * Pages hold 100 courses and the search covers the short name.
     */
    public function test_courses_pagination_and_search(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = (int) $generator->create_category(['name' => 'Muchos'])->id;
        for ($i = 1; $i <= 104; $i++) {
            $generator->create_course(['category' => $category, 'shortname' => 'CURSO' . $i, 'fullname' => 'Curso ' . $i]);
        }
        $generator->create_course(['category' => $category, 'shortname' => 'CALC1', 'fullname' => 'Cálculo I']);

        $first = tree::courses($category);
        $this->assertCount(100, $first['courses']);
        $this->assertSame(105, $first['total']);
        $second = tree::courses($category, '', 1);
        $this->assertCount(5, $second['courses']);
        $this->assertSame('Muchos', $second['courses'][0]['path']);

        $found = tree::courses(0, 'calc');
        $this->assertSame(1, $found['total']);
        $this->assertSame('Cálculo I', $found['courses'][0]['fullname']);
    }

    /**
     * The site course never shows up, not even in a site-wide search.
     */
    public function test_courses_never_include_site(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course();
        $all = tree::courses(0);
        $this->assertNotContains((int) SITEID, array_column($all['courses'], 'id'));
        $this->assertSame(1, $all['total']);
    }

    /**
     * The id lists follow the current order.
     */
    public function test_sibling_categories_and_category_courses(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B', 'parent' => $a])->id;
        $c = (int) $generator->create_category(['name' => 'C', 'parent' => $a])->id;
        $one = (int) $generator->create_course(['category' => $a])->id;
        $two = (int) $generator->create_course(['category' => $a])->id;

        $this->assertSame([$b, $c], tree::sibling_categories($a));
        $this->assertSame([$two, $one], tree::category_courses($a));
    }
}
