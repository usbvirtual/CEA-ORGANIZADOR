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
     * Accents, case, tags and extra spaces do not matter.
     */
    public function test_normalize_ignores_accents_case_and_spaces(): void {
        $this->assertSame('facultad de ingenieria', sorter::normalize(' <b>Facultad</b>  de Ingeniería '));
        $this->assertSame(sorter::normalize('Facultad de Ingeniería'), sorter::normalize('facultad  de ingenieria '));
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

    /**
     * "Facultades" is a seed only at the top level.
     */
    public function test_facultades_is_seed_only_at_top_level(): void {
        $p = sorter::default_patterns_parsed();
        $this->assertSame('semilla', sorter::classify('Facultades', true, $p));
        $this->assertSame('otro', sorter::classify('Facultades', false, $p));
    }

    /**
     * Pattern syntax: contains, exact and regex.
     */
    public function test_parse_patterns(): void {
        $this->assertSame(
            [
                ['type' => 'contains', 'value' => 'semilla'],
                ['type' => 'exact', 'value' => 'facultades'],
                ['type' => 'regex', 'value' => '/^programa/'],
            ],
            sorter::parse_patterns("Semilla\n=Facultades\n\n/^programa/")
        );
    }

    /**
     * A "contains" value that normalizes to nothing is dropped and never matches every name.
     */
    public function test_empty_contains_pattern_matches_nothing(): void {
        $this->assertSame([], sorter::parse_patterns("<b></b>\n<span> </span>"));
        $this->assertSame(
            [['type' => 'contains', 'value' => 'semilla']],
            sorter::parse_patterns("<i></i>\nSemilla")
        );
        // A stored empty value, from before this check, is ignored too.
        $patterns = ['semilla' => [['type' => 'contains', 'value' => '']]];
        $this->assertSame('aparte', sorter::classify('Centro de Idiomas', true, $patterns));
    }

    /**
     * An invalid regular expression is dropped.
     */
    public function test_invalid_regex_pattern_is_ignored(): void {
        $this->assertSame([], sorter::parse_patterns('/[unclosed/'));
    }

    /**
     * Without a seed pattern, a top-level name is «aparte» (faculties are not a group any more).
     */
    public function test_classify_without_seed_pattern(): void {
        $p = sorter::default_patterns_parsed();
        $this->assertSame('aparte', sorter::classify('Facultad de Ingeniería', true, $p));
        $this->assertSame('semilla', sorter::classify('Semillas', true, $p));
        $this->assertSame('periodo', sorter::classify('2026-2 Producción', true, $p));
    }

    /**
     * Unset settings fall back to defaults; an empty one means no patterns.
     */
    public function test_patterns_from_config(): void {
        $this->resetAfterTest();
        $this->assertSame(sorter::default_patterns_parsed(), sorter::patterns());
        set_config('patterns_semilla', '', 'tool_ceaorganizador');
        $this->assertSame([], sorter::patterns()['semilla']);
    }
}
