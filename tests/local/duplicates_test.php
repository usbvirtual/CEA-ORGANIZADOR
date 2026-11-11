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
        foreach (['CALC1', 'calc1', 'CALC1_1', 'CALC1-1', 'CALC1-2025-2', 'CALC1_2025-02', 'CALC1 (copia)'] as $short) {
            $this->assertSame('calc1', duplicates::key_shortname($short), $short);
        }
        $this->assertSame('calc2', duplicates::key_shortname('CALC2'));
        $this->assertSame('calc12', duplicates::key_shortname('CALC12'));
        $this->assertSame('fis1b', duplicates::key_shortname('FIS1B'));
    }

    /**
     * A base short name with fewer than three letters and digits does not match.
     */
    public function test_key_shortname_too_short(): void {
        $this->assertSame('', duplicates::key_shortname('PC_1'));
        $this->assertSame('', duplicates::key_shortname('AB-1'));
        $this->assertSame('', duplicates::key_shortname(''));
    }

    /**
     * Grouping is transitive and reports the rules that matched; unrelated courses are left out.
     */
    public function test_groups_transitive(): void {
        $groups = duplicates::groups([
            ['id' => 1, 'fullname' => 'Cálculo I', 'shortname' => 'XA1', 'timemodified' => 5],
            ['id' => 2, 'fullname' => 'Cálculo I copia 1', 'shortname' => 'ZZZ9', 'timemodified' => 5],
            ['id' => 3, 'fullname' => 'Otro nombre', 'shortname' => 'ZZZ9_1', 'timemodified' => 1],
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
}
