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
     * A category.
     *
     * @param string $name
     * @param int $parent
     * @return int
     */
    private function cat(string $name, int $parent = 0): int {
        return (int) $this->getDataGenerator()->create_category(['name' => $name, 'parent' => $parent])->id;
    }

    /**
     * A course, with an exact timemodified when one is given (set last: adding activities may touch it).
     *
     * @param string $fullname
     * @param string $shortname
     * @param int $category
     * @param array $options visible, activities (number of labels) and timemodified.
     * @return int
     */
    private function course(string $fullname, string $shortname, int $category, array $options = []): int {
        global $DB;
        $generator = $this->getDataGenerator();
        $id = (int) $generator->create_course([
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category,
            'visible' => $options['visible'] ?? 1,
        ])->id;
        for ($i = 0; $i < ($options['activities'] ?? 0); $i++) {
            $generator->create_module('label', ['course' => $id]);
        }
        if (isset($options['timemodified'])) {
            $DB->set_field('course', 'timemodified', $options['timemodified'], ['id' => $id]);
        }
        return $id;
    }

    /**
     * Rows come in tree order and carry their path.
     */
    public function test_categories_have_path_and_traversal_order(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
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
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $generator->create_category(['name' => 'Semestre II', 'parent' => $a]);
        $generator->create_category(['name' => 'Semestre I', 'parent' => $a]);

        $this->assertTrue($this->row(tree::categories(), $a)['needsort']);

        \core_course_category::get($a, MUST_EXIST, true)->resort_subcategories('name');
        $this->assertFalse($this->row(tree::categories(), $a)['needsort']);
    }

    /**
     * Courses out of order raise the flag too.
     */
    public function test_needsort_counts_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
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
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $generator->create_category(['name' => 'Facultad de Ingeniería']);
        $generator->create_category(['name' => '2026-02']);
        $this->assertTrue(tree::root_needsort());
        // The overview reads the categories once and gives the same two answers.
        $overview = tree::overview();
        $this->assertTrue($overview['rootneedsort']);
        $this->assertSame(tree::categories(), $overview['categories']);

        // In the proposed order, the top level no longer needs sorting.
        mover::apply_category_order(0, sorter::propose(array_map(function (int $id): array {
            global $DB;
            return ['id' => $id, 'name' => format_string($DB->get_field('course_categories', 'name', ['id' => $id]))];
        }, tree::sibling_categories(0)), true, sorter::patterns()));
        $this->assertFalse(tree::root_needsort());
        $this->assertFalse(tree::overview()['rootneedsort']);
    }

    /**
     * The short names taken outside a set, in one query, with the answer of the database.
     */
    public function test_taken_shortnames(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = (int) $generator->create_category(['name' => 'K'])->id;
        $generator->create_course(['category' => $category, 'shortname' => 'TS-A']);
        $mine = (int) $generator->create_course(['category' => $category, 'shortname' => 'TS-B'])->id;
        $generator->create_course(['category' => $category, 'shortname' => 'ts-c']);

        $taken = tree::taken_shortnames(['TS-A', 'TS-B', 'TS-Z', 'TS-C', 'TS-A'], [$mine]);

        $this->assertTrue(isset($taken['TS-A']));
        $this->assertFalse(isset($taken['TS-B']));
        $this->assertFalse(isset($taken['TS-Z']));
        // Held only through a collation that ignores case (MariaDB): whatever the database says.
        $held = $DB->record_exists_select('course', 'shortname = :shortname', ['shortname' => 'TS-C']);
        $this->assertSame($held, isset($taken['TS-C']));
        $this->assertSame([], tree::taken_shortnames([], [$mine]));

        // Two wanted names equal for the collation, one of them held exactly: each gets the answer of the database.
        $generator->create_course(['category' => $category, 'shortname' => 'TS-D']);
        $both = tree::taken_shortnames(['TS-D', 'ts-d'], [$mine]);
        $this->assertTrue(isset($both['TS-D']));
        $lower = $DB->record_exists_select('course', 'shortname = :shortname', ['shortname' => 'ts-d']);
        $this->assertSame($lower, isset($both['ts-d']));
    }

    /**
     * Pages hold 100 courses and the search covers the short name.
     */
    public function test_courses_pagination_and_search(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
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
     * Course paths are the full formatted category path, also in a site-wide search.
     */
    public function test_courses_paths_are_full_category_paths(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'Facultad'])->id;
        $b = (int) $generator->create_category(['name' => 'Programa & Co', 'parent' => $a])->id;
        $c = (int) $generator->create_category(['name' => 'Semestre I', 'parent' => $b])->id;
        $generator->create_course(['category' => $c, 'shortname' => 'PATHC', 'fullname' => 'Ruta C']);
        $generator->create_course(['category' => $a, 'shortname' => 'PATHA', 'fullname' => 'Ruta A']);

        $expected = [];
        foreach (tree::categories() as $row) {
            $expected[$row['id']] = $row['path'];
        }

        $inc = tree::courses($c);
        $this->assertSame($expected[$c], $inc['courses'][0]['path']);
        $this->assertSame('Facultad / Programa &amp; Co / Semestre I', $inc['courses'][0]['path']);

        $found = tree::courses(0, 'PATH');
        $this->assertSame(2, $found['total']);
        foreach ($found['courses'] as $course) {
            $this->assertSame($expected[$course['category']], $course['path']);
        }
        $this->assertSame('Facultad', $expected[$a]);
    }

    /**
     * The site course never shows up, not even in a site-wide search.
     */
    public function test_courses_never_include_site(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
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
        $this->setAdminUser();
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
