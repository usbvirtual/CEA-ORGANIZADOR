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

namespace tool_ceaorganizador\external;

use core_external\external_api;

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(suggest_preview::class)]
/**
 * Tests for the suggestion web service, against the test database.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\suggest_preview
 */
final class suggest_external_test extends \advanced_testcase {
    /**
     * Creates a category.
     *
     * @param string $name
     * @param int $parent
     * @return int
     */
    private function category(string $name, int $parent = 0): int {
        return (int) $this->getDataGenerator()->create_category(['name' => $name, 'parent' => $parent])->id;
    }

    /**
     * Creates a course.
     *
     * @param string $fullname
     * @param string $shortname
     * @param int $category
     * @return int
     */
    private function course(string $fullname, string $shortname, int $category): int {
        return (int) $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category,
        ])->id;
    }

    /**
     * Runs the service and cleans the result like the web service layer.
     *
     * @param int $categoryid
     * @param bool $includecourses
     * @return array
     */
    private function suggest(int $categoryid = 0, bool $includecourses = true): array {
        $result = suggest_preview::execute($categoryid, $includecourses);
        return external_api::clean_returnvalue(suggest_preview::execute_returns(), $result);
    }

    /**
     * Keys of the rows, "type:id".
     *
     * @param array $result
     * @return string[]
     */
    private function keys(array $result): array {
        $keys = array_map(function (array $row): string {
            return $row['type'] . ':' . $row['id'];
        }, $result['rows']);
        sort($keys);
        return $keys;
    }

    /**
     * Two programs: names with double spaces, and an accent that the site writes twice.
     *
     * @return array [faculty, program A, program B, course in A, course in B, course in A with a clean name]
     */
    private function site(): array {
        $faculty = $this->category('Facultad');
        $a = $this->category('Programa  A', $faculty);
        $b = $this->category('Programa  B', $faculty);
        $this->course('Ingeniería Civil', 'ICIV', $a);
        $this->course('Ingeniería Industrial', 'IIND', $a);
        $coursea = $this->course('Ingenieria Electrica', 'IELE', $a);
        $courseb = $this->course('Fisica  General', 'FISG', $b);
        $clean = $this->course('Algebra Lineal', 'ALGL', $a);
        return [$faculty, $a, $b, $coursea, $courseb, $clean];
    }

    /**
     * The result matches the declared structure and carries raw names, the group path and the counters.
     */
    public function test_clean_returnvalue(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$faculty, $a, , $coursea] = $this->site();
        $result = $this->suggest();
        $this->assertSame(count($result['rows']), $result['total']);
        $this->assertFalse($result['truncated']);
        $byid = [];
        foreach ($result['rows'] as $row) {
            $byid[$row['type'] . ':' . $row['id']] = $row;
        }
        $this->assertSame('Programa A', $byid['category:' . $a]['new']);
        $this->assertSame('Programa  A', $byid['category:' . $a]['old']);
        $this->assertSame(['formato'], $byid['category:' . $a]['reasons']);
        $this->assertSame('Facultad', $byid['category:' . $a]['group']);
        $this->assertSame('Facultad / Programa A', $byid['course:' . $coursea]['group']);
        $this->assertArrayNotHasKey('category:' . $faculty, $byid);
    }

    /**
     * A subtree only suggests its own categories and the courses under them; 0 means the whole site.
     */
    public function test_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$faculty, $a, $b, $coursea, $courseb] = $this->site();

        $subtree = $this->suggest($a);
        $this->assertSame(['category:' . $a, 'course:' . $coursea], $this->keys($subtree));

        $site = $this->suggest(0);
        $expected = ['category:' . $a, 'category:' . $b, 'course:' . $coursea, 'course:' . $courseb];
        sort($expected);
        $this->assertSame($expected, $this->keys($site));

        // The faculty includes its descendants.
        $this->assertSame($expected, $this->keys($this->suggest($faculty)));
    }

    /**
     * Without courses only the categories are suggested.
     */
    public function test_includecourses_false(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $a, $b] = $this->site();
        $result = $this->suggest(0, false);
        $expected = ['category:' . $a, 'category:' . $b];
        sort($expected);
        $this->assertSame($expected, $this->keys($result));
    }

    /**
     * An accent that the site writes twice is proposed for the course that omits it, across categories.
     */
    public function test_accent_learned_from_the_site(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , , $coursea] = $this->site();
        $other = $this->category('Otra');
        $lonely = $this->course('Ingenieria Ambiental', 'IAMB', $other);
        $result = $this->suggest($other);
        $this->assertSame(['course:' . $lonely], $this->keys($result));
        $this->assertSame('Ingeniería Ambiental', $result['rows'][0]['new']);
        $this->assertSame(['tilde'], $result['rows'][0]['reasons']);
        // The course of the other tree gets the same learned accent.
        $row = $this->suggest(0)['rows'];
        $found = array_values(array_filter($row, function (array $r) use ($coursea): bool {
            return $r['type'] === 'course' && $r['id'] === $coursea;
        }));
        $this->assertSame('Ingeniería Electrica', $found[0]['new']);
    }

    /**
     * Only the full names are proposed: the short names stay as they are.
     */
    public function test_shortnames_are_never_touched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->site();
        $before = $DB->get_records_menu('course', null, 'id', 'id, shortname');
        $result = $this->suggest();
        foreach ($result['rows'] as $row) {
            $this->assertArrayNotHasKey('newshort', $row);
        }
        $this->assertSame($before, $DB->get_records_menu('course', null, 'id', 'id, shortname'));
    }

    /**
     * Rows come ordered by group and then by old name.
     */
    public function test_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->site();
        $rows = $this->suggest()['rows'];
        $sorted = $rows;
        usort($sorted, function (array $a, array $b): int {
            return [$a['group'], $a['old']] <=> [$b['group'], $b['old']];
        });
        $this->assertSame(
            array_map(function (array $r): string {
                return $r['group'] . '|' . $r['old'];
            }, $sorted),
            array_map(function (array $r): string {
                return $r['group'] . '|' . $r['old'];
            }, $rows)
        );
    }

    /**
     * Without the manage capability the service refuses.
     */
    public function test_capability(): void {
        $this->resetAfterTest();
        $this->site();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        suggest_preview::execute(0, true);
    }
}
