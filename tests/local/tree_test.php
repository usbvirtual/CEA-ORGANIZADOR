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
     * Every course of a category with its marks, read one per page: the marks must not depend on the page.
     *
     * @param int $category
     * @return array Map course id => course row.
     */
    private function marked(int $category): array {
        $result = [];
        $page = 0;
        do {
            $list = tree::courses($category, '', $page++, 1);
            foreach ($list['courses'] as $course) {
                $result[$course['id']] = $course;
            }
        } while (count($result) < $list['total'] && $page < 50);
        return $result;
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

    /**
     * Each category counts its repeated courses, and its subtree adds up; a «Repetidos» folder never counts, and a
     * level number or a group keeps two courses apart.
     */
    public function test_categories_count_repeated_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $faculty = $this->cat('Facultad');
        $program = $this->cat('Programa', $faculty);
        $semester = $this->cat('Semestre I', $program);
        $folder = $this->cat('Repetidos', $program);
        $below = $this->cat('Sub', $folder);
        $apart = $this->cat('Niveles', $faculty);

        // Semestre I: two groups of two, so two repeated courses.
        $this->course('Cálculo I', 'CAL1', $semester);
        $this->course('Cálculo I (copia)', 'CAL1_1', $semester);
        $this->course('Física', 'FIS', $semester);
        $this->course('Física 2025-2', 'FIS-2025-2', $semester);
        // Programa: one group of three, so two repeated courses.
        $this->course('Ética', 'ETI', $program);
        $this->course('Ética', 'ETI2', $program);
        $this->course('Ética (copia)', 'ETI3', $program);
        // The folder and what is under it never count.
        $this->course('Ética', 'ETI4', $folder);
        $this->course('Ética', 'ETI5', $folder);
        $this->course('Ética', 'ETI6', $below);
        $this->course('Ética', 'ETI7', $below);
        // Same short name base and a shared word, but two levels or two groups: never repeated.
        $this->course('Cálculo I', 'CAL-2025-1', $apart);
        $this->course('Cálculo II', 'CAL-2025-2', $apart);
        $this->course('Inglés G1', 'ING-2025-1', $apart);
        $this->course('Inglés G2', 'ING-2025-2', $apart);

        $rows = tree::categories();
        $counts = function (int $id) use ($rows): array {
            $row = $this->row($rows, $id);
            return [$row['dupcount'], $row['dupcounttotal']];
        };
        $this->assertSame([2, 2], $counts($semester));
        $this->assertSame([0, 0], $counts($folder));
        $this->assertSame([0, 0], $counts($below));
        $this->assertSame([2, 4], $counts($program));
        $this->assertSame([0, 0], $counts($apart));
        $this->assertSame([0, 4], $counts($faculty));
    }

    /**
     * The courses of a category carry their mark: the base follows the ranking of «Quitar repetidos» (a visible
     * course before a hidden one, then more activities before a more recent one), possible repeats are asked, and
     * the marks come from the whole category, whatever the page.
     */
    public function test_courses_mark_repeated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->cat('Cursos');
        $older = $this->course('Álgebra', 'ALG', $category, ['activities' => 1, 'timemodified' => 100]);
        $newer = $this->course('Álgebra (copia)', 'ALG_1', $category, ['timemodified' => 200]);
        $hidden = $this->course('Biología', 'BIO', $category, ['visible' => 0, 'activities' => 1, 'timemodified' => 300]);
        $shown = $this->course('Biología 2025-2', 'BIO2', $category, ['timemodified' => 100]);
        $one = $this->course('Programación de computadores', 'PROG1', $category, ['timemodified' => 100]);
        $two = $this->course('Programación de computadoras', 'PROG2', $category, ['timemodified' => 200]);
        $alone = $this->course('Química', 'QUI', $category);

        $courses = $this->marked($category);
        $this->assertCount(7, $courses);
        $mark = function (int $id) use ($courses): array {
            return [$courses[$id]['dup'], $courses[$id]['dupreasons'], $courses[$id]['dupbase']];
        };
        $this->assertSame(['base', ['key', 'shortname'], ''], $mark($older));
        $this->assertSame(['repeated', ['key', 'shortname'], 'Álgebra'], $mark($newer));
        $this->assertSame(['base', ['key'], ''], $mark($shown));
        $this->assertSame(['repeated', ['key'], 'Biología 2025-2'], $mark($hidden));
        $this->assertSame(['possible', ['similar'], 'Programación de computadoras'], $mark($one));
        $this->assertSame(['possible', ['similar'], 'Programación de computadores'], $mark($two));
        $this->assertSame(['', [], ''], $mark($alone));

        // A site-wide list has no marks: they are only compared within one category.
        $found = tree::courses(0, 'ALG')['courses'];
        $this->assertNotEmpty($found);
        foreach ($found as $course) {
            $this->assertSame('', $course['dup']);
        }
    }

    /**
     * Nothing is marked in a «Repetidos» folder nor under one.
     */
    public function test_courses_no_marks_in_repeated_folder(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->cat('Cursos');
        $folder = $this->cat('Repetidos', $category);
        $below = $this->cat('Sub', $folder);
        $this->course('Ética', 'ETI', $folder);
        $this->course('Ética', 'ETI2', $folder);
        $this->course('Ética', 'ETI3', $below);
        $this->course('Ética', 'ETI4', $below);

        foreach ([$folder, $below] as $id) {
            $courses = tree::courses($id)['courses'];
            $this->assertCount(2, $courses);
            $this->assertSame(['', ''], array_column($courses, 'dup'));
        }
    }
}
