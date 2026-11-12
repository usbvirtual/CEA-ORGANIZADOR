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
#[\PHPUnit\Framework\Attributes\CoversClass(duplicates::class)]
/**
 * Tests for the pure rules of repeated courses.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\duplicates
 */
final class duplicates_test extends \advanced_testcase {
    /**
     * Copy, period and group markers are stripped from the full name.
     */
    public function test_key_name_same_course(): void {
        $names = [
            'Cálculo I',
            'CALCULO  I',
            'Cálculo I (copia)',
            'Cálculo I copia 2',
            'Cálculo I (Copia 1)',
            'Copy of Cálculo I',
            'Copia de Cálculo I',
            'Cálculo I 2025-2',
            'Cálculo I 2025-02',
            'Cálculo I (2025-2)',
            'Cálculo I - Grupo 1',
            'Cálculo I Grupo A',
            'Cálculo I G1',
            'Cálculo I G1 2025-2',
        ];
        foreach ($names as $name) {
            $this->assertSame('calculo i', duplicates::key_name($name), $name);
        }
        $this->assertSame(
            'electiva: emprendimiento (maria balbin)',
            duplicates::key_name('Electiva: Emprendimiento (Maria Balbin)COPIA')
        );
    }

    /**
     * Level numbers, roman or arabic, are never stripped, and words that only look like markers are kept.
     */
    public function test_key_name_keeps_levels(): void {
        $this->assertNotSame(duplicates::key_name('Cálculo I'), duplicates::key_name('Cálculo II'));
        $this->assertNotSame(duplicates::key_name('Física 1'), duplicates::key_name('Física 2'));
        $this->assertNotSame(duplicates::key_name('Inglés I'), duplicates::key_name('Inglés IV'));
        $this->assertNotSame(duplicates::key_name('Inglés I G1'), duplicates::key_name('Inglés IV G1'));
        $this->assertSame('grupos', duplicates::key_name('Grupos'));
        $this->assertSame('escopia', duplicates::key_name('Escopia'));
        $this->assertSame('programa', duplicates::key_name('Programa'));
        $this->assertSame('', duplicates::key_name('Copia'));
    }

    /**
     * Copy markers, counters and periods are stripped from the short name, case aside.
     */
    public function test_key_shortname(): void {
        foreach (['CALC1', 'calc1', 'CALC1_1', 'CALC1-2025-2', 'CALC1_2025-02', 'CALC1 (copia)'] as $short) {
            $this->assertSame('calc1', duplicates::key_shortname($short), $short);
        }
        $this->assertSame('calc2', duplicates::key_shortname('CALC2'));
        $this->assertSame('calc12', duplicates::key_shortname('CALC12'));
        $this->assertSame('fis1b', duplicates::key_shortname('FIS1B'));
        // Digits glued to the code are not a period.
        $this->assertSame('cal20261', duplicates::key_shortname('CAL20261'));
    }

    /**
     * A level number in a short name is a different course: only «_N» and a period are stripped, never a bare «-N».
     */
    public function test_key_shortname_keeps_levels(): void {
        $this->assertNotSame(duplicates::key_shortname('MAT-1'), duplicates::key_shortname('MAT-2'));
        $this->assertNotSame(duplicates::key_shortname('ING-I'), duplicates::key_shortname('ING-II'));
        $this->assertNotSame(duplicates::key_shortname('FIS1'), duplicates::key_shortname('FIS2'));
        $this->assertSame('mat-1', duplicates::key_shortname('MAT-1'));
        $this->assertSame('calc1-1', duplicates::key_shortname('CALC1-1'));
    }

    /**
     * Names that differ only in a level number are never grouped, not by a matching short name, not through a third.
     */
    public function test_groups_never_join_levels(): void {
        $this->assertSame([], duplicates::groups([
            ['id' => 1, 'fullname' => 'Cálculo I', 'shortname' => 'CALC', 'timemodified' => 1],
            ['id' => 2, 'fullname' => 'Cálculo II', 'shortname' => 'CALC_1', 'timemodified' => 1],
        ]));
        $this->assertSame([], duplicates::groups([
            ['id' => 1, 'fullname' => 'Física 1', 'shortname' => 'FISX', 'timemodified' => 1],
            ['id' => 2, 'fullname' => 'Física 2 (copia)', 'shortname' => 'FISX_1', 'timemodified' => 1],
        ]));

        // C repeats A by name and by short name; B shares the short name base but is another level.
        $groups = duplicates::groups([
            ['id' => 1, 'fullname' => 'Cálculo I', 'shortname' => 'CALC', 'timemodified' => 1],
            ['id' => 2, 'fullname' => 'Cálculo II', 'shortname' => 'CALC_1', 'timemodified' => 1],
            ['id' => 3, 'fullname' => 'Cálculo I copia', 'shortname' => 'CALC_2', 'timemodified' => 1],
        ]);
        $this->assertCount(1, $groups);
        $this->assertSame('1-3', $groups[0]['key']);
        $this->assertSame(['key', 'shortname'], $groups[0]['courses'][0]['reasons']);
    }

    /**
     * The stem and the level numbers of a name key.
     */
    public function test_levels(): void {
        $this->assertSame(['calculo #', '2'], duplicates::levels('calculo ii'));
        $this->assertSame(['fisica #', '1'], duplicates::levels('fisica 1'));
        $this->assertSame(['civil', ''], duplicates::levels('civil'));
    }

    /**
     * A base short name with fewer than three letters and digits does not match.
     */
    public function test_key_shortname_too_short(): void {
        $this->assertSame('', duplicates::key_shortname('PC_1'));
        $this->assertSame('', duplicates::key_shortname('AB_1'));
        $this->assertSame('', duplicates::key_shortname(''));
    }

    /**
     * Grouping is transitive and reports the rules that matched; unrelated courses are left out.
     */
    public function test_groups_transitive(): void {
        $groups = duplicates::groups([
            ['id' => 1, 'fullname' => 'Cálculo I', 'shortname' => 'XA1', 'timemodified' => 5],
            ['id' => 2, 'fullname' => 'Cálculo I copia 1', 'shortname' => 'ZZZ9', 'timemodified' => 5],
            ['id' => 3, 'fullname' => 'Cálculo I (nueva versión)', 'shortname' => 'ZZZ9_1', 'timemodified' => 1],
            ['id' => 4, 'fullname' => 'Cálculo II', 'shortname' => 'C2X', 'timemodified' => 9],
            ['id' => 5, 'fullname' => 'Física', 'shortname' => 'F1X', 'timemodified' => 1],
            ['id' => 6, 'fullname' => 'FÍSICA', 'shortname' => 'F2X', 'timemodified' => 1],
        ]);

        $this->assertCount(2, $groups);
        $this->assertSame('1-2-3', $groups[0]['key']);
        $this->assertSame([1, 2, 3], $groups[0]['ids']);
        $reasons = array_column($groups[0]['courses'], 'reasons', 'id');
        $this->assertSame(['key'], $reasons[1]);
        $this->assertSame(['key', 'shortname'], $reasons[2]);
        $this->assertSame(['shortname'], $reasons[3]);
        $this->assertSame('5-6', $groups[1]['key']);
        $this->assertSame(['name'], $groups[1]['courses'][0]['reasons']);
    }

    /**
     * The most recently modified course stays; a tie goes to the highest id.
     */
    public function test_groups_newest_wins(): void {
        $groups = duplicates::groups([
            ['id' => 7, 'fullname' => 'Álgebra', 'shortname' => 'ALG1', 'timemodified' => 300],
            ['id' => 8, 'fullname' => 'Álgebra (copia)', 'shortname' => 'ALG1_1', 'timemodified' => 100],
        ]);
        $this->assertSame(7, $groups[0]['keep']);
        $this->assertSame([true, false], array_column($groups[0]['courses'], 'keep'));

        $tie = duplicates::groups([
            ['id' => 9, 'fullname' => 'Álgebra', 'shortname' => 'ALG1', 'timemodified' => 100],
            ['id' => 8, 'fullname' => 'Álgebra', 'shortname' => 'ALG9', 'timemodified' => 100],
        ]);
        $this->assertSame(9, $tie[0]['keep']);
        $this->assertSame('8-9', $tie[0]['key']);
    }

    /**
     * No group when nothing repeats.
     */
    public function test_groups_none(): void {
        $this->assertSame([], duplicates::groups([]));
        $this->assertSame([], duplicates::groups([
            ['id' => 1, 'fullname' => 'Física 1', 'shortname' => 'FIS1', 'timemodified' => 1],
            ['id' => 2, 'fullname' => 'Física 2', 'shortname' => 'FIS2', 'timemodified' => 1],
        ]));
    }

    /**
     * A course of the same category.
     *
     * @param string $fullname
     * @param int $id
     * @param string $shortname
     * @param int $timemodified
     * @param int $visible
     * @return array
     */
    private static function entry(string $fullname, int $id, string $shortname, int $timemodified = 1, int $visible = 1): array {
        return [
            'id' => $id,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'timemodified' => $timemodified,
            'visible' => $visible,
        ];
    }

    /**
     * The group id of a name: digits as a number, a letter as is, '' when there is none.
     */
    public function test_group_id(): void {
        $this->assertSame('1', duplicates::group_id('Cálculo I G1'));
        $this->assertSame('1', duplicates::group_id('Cálculo I - Grupo 01'));
        $this->assertSame('a', duplicates::group_id('Física Grupo A'));
        $this->assertSame('1', duplicates::group_id('Juegos de Inteligencia (2026-2) G1'));
        $this->assertSame('1', duplicates::group_id('Cálculo I G1 (copia)'));
        $this->assertSame('', duplicates::group_id('Cálculo I'));
        $this->assertSame('', duplicates::group_id('Grupos'));
    }

    /**
     * Different live sections of a course (different groups) are never grouped: real pairs from eCampus.
     */
    public function test_groups_never_join_different_groups(): void {
        $pairs = [
            ['Juegos de Inteligencia (2026-2) G1', 'Juegos de Inteligencia (2026-2) G2'],
            ['Física Grupo A', 'Física Grupo B'],
            ['Laboratorio de Física G2', 'Laboratorio de Física G3'],
            ['Práctica Profesional I - Grupo 1', 'Práctica Profesional I - Grupo 2'],
        ];
        foreach ($pairs as [$one, $two]) {
            $this->assertSame([], duplicates::groups([
                self::entry($one, 1, 'SEC1'),
                self::entry($two, 2, 'SEC1_1'),
            ]), $one);
        }
    }

    /**
     * A course without a group may join one with a group, but then never a course of another group.
     */
    public function test_groups_with_and_without_group(): void {
        $groups = duplicates::groups([self::entry('Cálculo I', 1, 'CA1'), self::entry('Cálculo I G1', 2, 'CA2')]);
        $this->assertSame('1-2', $groups[0]['key']);

        $groups = duplicates::groups([
            self::entry('Cálculo I G1', 1, 'CA1'),
            self::entry('Cálculo I', 2, 'CA2'),
            self::entry('Cálculo I G2', 3, 'CA3'),
        ]);
        $this->assertCount(1, $groups);
        $this->assertSame('1-2', $groups[0]['key']);
    }

    /**
     * A visible course stays before a hidden one, even an older one.
     */
    public function test_groups_visible_wins(): void {
        $groups = duplicates::groups([
            self::entry('Álgebra', 1, 'ALG1', 9000, 0),
            self::entry('Álgebra', 2, 'ALG2', 1000, 1),
        ]);
        $this->assertSame(2, $groups[0]['keep']);
        // Without visibility a course counts as visible.
        $groups = duplicates::groups([
            ['id' => 3, 'fullname' => 'Álgebra', 'shortname' => 'ALG3', 'timemodified' => 9000],
            self::entry('Álgebra', 4, 'ALG4', 1000, 1),
        ]);
        $this->assertSame(3, $groups[0]['keep']);
    }

    /**
     * A short name match alone links only names that share a significant word.
     */
    public function test_groups_shortname_needs_shared_word(): void {
        $this->assertSame([], duplicates::groups([
            self::entry('Cálculo Diferencial', 1, 'CAL101'),
            self::entry('Física', 2, 'CAL101_2'),
        ]));
        $groups = duplicates::groups([
            self::entry('Cálculo Diferencial', 1, 'CAL101'),
            self::entry('Cálculo Diferencial e Integral', 2, 'CAL101_2'),
        ]);
        $this->assertSame('1-2', $groups[0]['key']);
        $this->assertSame(['shortname'], $groups[0]['courses'][0]['reasons']);
        // Stopwords and short words do not count.
        $this->assertSame(['calculo' => 'calculo', 'integral' => 'integral'], duplicates::words('calculo del integral y la'));
    }

    /**
     * The course that stays: visible first, then the most activities, then the most recent, then the highest id.
     */
    public function test_groups_rank_activities(): void {
        $course = static function (int $id, int $time, int $visible, int $activities): array {
            return self::entry('Álgebra', $id, 'ALG' . $id, $time, $visible) + ['activities' => $activities];
        };
        // More activities beat a more recent course.
        $this->assertSame(1, duplicates::groups([$course(1, 1000, 1, 12), $course(2, 9000, 1, 2)])[0]['keep']);
        // A visible course beats a hidden one with more activities.
        $this->assertSame(1, duplicates::groups([$course(1, 1000, 1, 1), $course(2, 9000, 0, 50)])[0]['keep']);
        // Same activities: the most recent.
        $this->assertSame(2, duplicates::groups([$course(1, 1000, 1, 3), $course(2, 9000, 1, 3)])[0]['keep']);
    }

    /**
     * Possible repeated courses: each reason, with the course that would stay.
     */
    public function test_candidates_positives(): void {
        $cases = [
            'group' => ['Física Grupo A', 'Física Grupo B', 'FA1', 'FB1'],
            'shortname' => ['Cálculo Diferencial', 'Física', 'CAL101', 'CAL101_2'],
            'extra' => ['Física', 'Física Mecánica', 'F01', 'F02'],
            'similar' => ['Cálculo Diferencial', 'Cálculo Diferencail', 'Y01', 'Y02'],
        ];
        foreach ($cases as $reason => [$one, $two, $short1, $short2]) {
            $courses = [self::entry($one, 1, $short1), self::entry($two, 2, $short2) + ['activities' => 4]];
            $groups = duplicates::groups($courses);
            $this->assertSame([], $groups, $reason);
            $possible = duplicates::candidates($courses, $groups);
            $this->assertCount(1, $possible, $reason);
            $this->assertSame($reason, $possible[0]['reason']);
            $this->assertSame('1-2', $possible[0]['key']);
            $this->assertSame(2, $possible[0]['keep'], $reason);
        }
        // A parenthesis at the end, and the same words in another order.
        $courses = [self::entry('Cálculo Diferencial', 1, 'X01'), self::entry('Cálculo Diferencial (virtual)', 2, 'X02')];
        $this->assertSame('extra', duplicates::candidates($courses, [])[0]['reason']);
        $courses = [self::entry('Historia de Colombia', 1, 'H01'), self::entry('Colombia Historia de', 2, 'H02')];
        $this->assertSame('similar', duplicates::candidates($courses, [])[0]['reason']);
    }

    /**
     * Never suggested: different levels, a level as the extra word, unrelated names, and courses of a sure group.
     */
    public function test_candidates_negatives(): void {
        $pairs = [
            ['Cálculo I', 'Cálculo II', 'Z01', 'Z02'],
            ['Cálculo I', 'Cálculo II', 'CALC', 'CALC_1'],
            ['Física 1', 'Física 2', 'S01', 'S02'],
            ['Programación', 'Programación I', 'P01', 'P02'],
            ['Inglés I', 'Inglés IV', 'I01', 'I02'],
            ['Física', 'Química', 'Q01', 'Q02'],
        ];
        foreach ($pairs as [$one, $two, $short1, $short2]) {
            $courses = [self::entry($one, 1, $short1), self::entry($two, 2, $short2)];
            $this->assertSame([], duplicates::candidates($courses, duplicates::groups($courses)), "$one / $two");
        }
        // Repeated for sure: in a group, so not a possible one.
        $courses = [self::entry('Física', 1, 'R01'), self::entry('Física (copia)', 2, 'R02')];
        $groups = duplicates::groups($courses);
        $this->assertCount(1, $groups);
        $this->assertSame([], duplicates::candidates($courses, $groups));
    }
}
