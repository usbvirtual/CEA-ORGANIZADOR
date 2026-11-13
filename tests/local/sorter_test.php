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

    /**
     * Top level groups: periods, apart, seeds and faculties.
     */
    public function test_propose_toplevel_groups(): void {
        $items = self::items([
            'Facultad de Ingeniería', 'CEA', '2026-01', 'Semillas', 'Facultades', '2026-02',
            'Centro de Idiomas', '2025-02',
        ]);
        $order = sorter::propose($items, true, sorter::default_patterns_parsed());
        $this->assertSame(
            ['2026-02', '2026-01', '2025-02', 'CEA', 'Centro de Idiomas', 'Facultades', 'Semillas',
                'Facultad de Ingeniería'],
            self::names($items, $order)
        );
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
     * Lower levels: periods first, then natural order.
     */
    public function test_propose_lower_level_periods_first_then_natural(): void {
        $items = self::items(['Semestre II', '2026 -1 Cursos', 'Semestre I', '2026 -2 Cursos']);
        $this->assertSame(
            ['2026 -2 Cursos', '2026 -1 Cursos', 'Semestre I', 'Semestre II'],
            self::names($items, sorter::propose($items, false, sorter::default_patterns_parsed()))
        );
    }

    /**
     * Names in the same period are ordered naturally.
     */
    public function test_same_period_ties_are_natural(): void {
        $items = self::items(['2026-02 Producción', '2026-02 Apoyo a la presencialidad']);
        $this->assertSame(
            ['2026-02 Apoyo a la presencialidad', '2026-02 Producción'],
            self::names($items, sorter::propose($items, true, sorter::default_patterns_parsed()))
        );
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
        $patterns = ['semilla' => [['type' => 'contains', 'value' => '']], 'facultad' => []];
        $this->assertSame('aparte', sorter::classify('Centro de Idiomas', true, $patterns));
    }

    /**
     * An invalid regular expression is dropped.
     */
    public function test_invalid_regex_pattern_is_ignored(): void {
        $this->assertSame([], sorter::parse_patterns('/[unclosed/'));
    }

    /**
     * The default regular expressions match faculties and programs.
     */
    public function test_default_faculty_patterns(): void {
        $p = sorter::default_patterns_parsed();
        $this->assertSame('facultad', sorter::classify('Facultad de Ingeniería', true, $p));
        $this->assertSame('facultad', sorter::classify('Programa Derecho', true, $p));
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

    /**
     * Courses go in natural order, ties by id.
     */
    public function test_propose_courses_and_differs(): void {
        $items = [
            ['id' => 3, 'name' => 'Curso 10'],
            ['id' => 2, 'name' => 'Curso 2'],
            ['id' => 1, 'name' => 'Curso 2'],
        ];
        $this->assertSame([1, 2, 3], sorter::propose_courses($items));
        $this->assertTrue(sorter::differs([3, 2, 1], [1, 2, 3]));
        $this->assertFalse(sorter::differs([1, 2, 3], [1, 2, 3]));
    }

    /**
     * Fixed orders of realistic names (periods, seeds, faculties, accents, numbers and roman numerals), so that the
     * keys computed once per item keep the order the sorter gave before.
     */
    public function test_fixed_orders_of_realistic_names(): void {
        $patterns = sorter::default_patterns_parsed();

        $top = self::items([
            'Facultad de Ingeniería', 'Semillas', '2025-2', 'Centro de Idiomas', '2026-1', 'Plantillas',
            'Facultad de Derecho', 'Programa de Psicología', 'Bienestar', '2025 - I', 'Facultades',
        ]);
        $this->assertSame(
            [
                '2026-1', '2025-2', '2025 - I', 'Bienestar', 'Centro de Idiomas', 'Facultades', 'Plantillas', 'Semillas',
                'Facultad de Derecho', 'Facultad de Ingeniería', 'Programa de Psicología',
            ],
            self::names($top, sorter::propose($top, true, $patterns))
        );

        $sub = self::items([
            'Semestre X', 'Semestre II', 'Semestre I', 'Semestre IX', 'Semestre 10', 'Semestre III', 'Electivas', 'Énfasis',
        ]);
        $this->assertSame(
            ['Electivas', 'Énfasis', 'Semestre I', 'Semestre II', 'Semestre III', 'Semestre IX', 'Semestre 10', 'Semestre X'],
            self::names($sub, sorter::propose($sub, false, $patterns))
        );

        // The names «Álgebra Lineal» and «Algebra lineal» tie on the normalized name: the lower id goes first.
        $courses = self::items([
            'Cálculo III', 'Cálculo I', 'cálculo ii', 'Álgebra Lineal', 'Física 2', 'Física 10', 'Física 1',
            'Ética Profesional', 'Inglés IV', 'Inglés V', 'Programación Orientada a Objetos', 'Algebra lineal',
        ]);
        $this->assertSame(
            [
                'Álgebra Lineal', 'Algebra lineal', 'Cálculo I', 'cálculo ii', 'Cálculo III', 'Ética Profesional', 'Física 1',
                'Física 2', 'Física 10', 'Inglés IV', 'Inglés V', 'Programación Orientada a Objetos',
            ],
            self::names($courses, sorter::propose_courses($courses))
        );
    }

    /**
     * Builds items with consecutive ids.
     *
     * @param string[] $names
     * @return array
     */
    private static function items(array $names): array {
        $items = [];
        foreach (array_values($names) as $i => $name) {
            $items[] = ['id' => $i + 1, 'name' => $name];
        }
        return $items;
    }

    /**
     * Names of the given ids.
     *
     * @param array $items
     * @param int[] $ids
     * @return string[]
     */
    private static function names(array $items, array $ids): array {
        $byid = array_column($items, 'name', 'id');
        return array_map(function (int $id) use ($byid): string {
            return $byid[$id];
        }, $ids);
    }
}
