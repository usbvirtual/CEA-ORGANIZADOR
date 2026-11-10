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
#[\PHPUnit\Framework\Attributes\CoversClass(sorter::class)]
/**
 * Tests for the sorter.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\sorter
 */
final class sorter_test extends \advanced_testcase {
    /**
     * Numbers and roman numerals (even typed with a lowercase L) sort together.
     */
    public function test_compare_numbers_and_romans(): void {
        $names = ['Semestre 10', 'Semestre IX', 'Semestre 2', 'Semestre l', 'Semestre II', 'Semestre 1'];
        usort($names, [sorter::class, 'compare']);
        $this->assertSame(
            ['Semestre 1', 'Semestre l', 'Semestre 2', 'Semestre II', 'Semestre IX', 'Semestre 10'],
            $names
        );
    }

    /**
     * Accents, case and extra spaces do not matter.
     */
    public function test_compare_ignores_accents_case_and_spaces(): void {
        $this->assertSame(0, sorter::compare('Facultad de Ingeniería', 'facultad  de ingenieria '));
    }

    /**
     * Roman numerals go from 1 to 30 only.
     */
    public function test_roman_value(): void {
        $this->assertSame(12, sorter::roman_value('Xll'));
        $this->assertSame(1, sorter::roman_value('l'));
        $this->assertSame(4, sorter::roman_value('IV'));
        $this->assertNull(sorter::roman_value('XL'));
        $this->assertNull(sorter::roman_value('ciclo'));
    }

    /**
     * Period names are detected in several writings.
     *
     * @dataProvider period_provider
     * @param string $name
     * @param array|null $expected
     */
    public function test_parse_period(string $name, ?array $expected): void {
        $this->assertSame($expected, sorter::parse_period($name));
    }

    /**
     * Data for test_parse_period.
     *
     * @return array
     */
    public static function period_provider(): array {
        return [
            ['2026-02', [2026, 2]],
            ['2026 -1', [2026, 1]],
            ['2026-2', [2026, 2]],
            ['2026 II', [2026, 2]],
            ['2026-01 Producción', [2026, 1]],
            ['2026 -2 Cursos Institucionales Transversales', [2026, 2]],
            ['Cohorte 2026', null],
            ['2026S1C01C04 - Neuropsicología', null],
            ['CEA', null],
        ];
    }
}
