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
#[\PHPUnit\Framework\Attributes\CoversClass(cloner::class)]
/**
 * Tests for the cloner.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\cloner
 */
final class cloner_test extends \advanced_testcase {
    /**
     * The period label is normalized to YYYY-PP.
     */
    public function test_period_label(): void {
        $this->assertSame('2027-01', cloner::period_label('2027-01'));
        $this->assertSame('2027-01', cloner::period_label('2027 -1'));
        $this->assertSame('2027-02', cloner::period_label('2027 II'));
        $this->assertNull(cloner::period_label('Facultades'));
    }

    /**
     * The period is replaced when present and appended otherwise.
     */
    public function test_names_add_or_replace_period(): void {
        $this->assertSame('Cálculo I 2027-01', cloner::new_fullname('Cálculo I', '2027-01'));
        $this->assertSame('Cálculo I 2027-01', cloner::new_fullname('Cálculo I 2026-02', '2027-01'));
        $this->assertSame('CALC1-2027-01', cloner::new_shortname('CALC1', '2027-01'));
        $this->assertSame('CALC1-2027-01', cloner::new_shortname('CALC1-2026-02', '2027-01'));
    }

    /**
     * A period at the start or in the middle leaves no dangling separator.
     */
    public function test_names_period_at_start_or_middle(): void {
        $this->assertSame('CALC1-2027-01', cloner::new_shortname('2026-02-CALC1', '2027-01'));
        $this->assertSame('Semestre II 2027-01', cloner::new_fullname('Semestre II 2026-02', '2027-01'));
        $this->assertSame('2027-01', cloner::new_fullname('2026 II', '2027-01'));
        $this->assertSame('Curso Grupo A 2027-01', cloner::new_fullname('Curso 2026-02 Grupo A', '2027-01'));
    }

    /**
     * Names are never truncated, so the caller can flag the ones that are too long.
     */
    public function test_name_too_long_is_error(): void {
        $this->assertGreaterThan(254, \core_text::strlen(cloner::new_fullname(str_repeat('a', 250), '2027-01')));
    }

    /**
     * The mirror path is found and then resolved against an existing period.
     */
    public function test_mirror_and_resolve(): void {
        $cats = [1 => ['id' => 1, 'parent' => 0, 'name' => 'Facultades'],
                 2 => ['id' => 2, 'parent' => 1, 'name' => 'Facultad de Ingeniería'],
                 3 => ['id' => 3, 'parent' => 2, 'name' => 'Programa Sistemas'],
                 9 => ['id' => 9, 'parent' => 0, 'name' => '2027-01'],
                 10 => ['id' => 10, 'parent' => 9, 'name' => 'Facultades'],
                 11 => ['id' => 11, 'parent' => 10, 'name' => 'Facultad de Ingenieria']];
        $m = cloner::mirror($cats, 3, sorter::default_patterns_parsed());
        $this->assertSame(['root' => 1, 'relative' => ['Facultades', 'Facultad de Ingeniería', 'Programa Sistemas']], $m);
        $this->assertSame(['existing' => 11, 'missing' => ['Programa Sistemas']], cloner::resolve($cats, 9, $m['relative']));
    }

    /**
     * A top-level ancestor that is not a seed gives no root.
     */
    public function test_mirror_without_seed_root(): void {
        $cats = [5 => ['id' => 5, 'parent' => 0, 'name' => 'CEA']];
        $this->assertSame(['root' => null, 'relative' => []], cloner::mirror($cats, 5, sorter::default_patterns_parsed()));
    }

    /**
     * Cycles and missing ids give no root instead of looping.
     */
    public function test_mirror_guards_cycles_and_missing_ids(): void {
        $patterns = sorter::default_patterns_parsed();
        $cycle = [1 => ['id' => 1, 'parent' => 2, 'name' => 'Facultades'],
                  2 => ['id' => 2, 'parent' => 1, 'name' => 'Otra']];
        $none = ['root' => null, 'relative' => []];
        $this->assertSame($none, cloner::mirror($cycle, 1, $patterns));
        $this->assertSame($none, cloner::mirror($cycle, 99, $patterns));
        $orphan = [4 => ['id' => 4, 'parent' => 77, 'name' => 'Programa']];
        $this->assertSame($none, cloner::mirror($orphan, 4, $patterns));
    }

    /**
     * Nothing to create when the whole path exists, and everything when none does.
     */
    public function test_resolve_edges(): void {
        $cats = [9 => ['id' => 9, 'parent' => 0, 'name' => '2027-01'],
                 10 => ['id' => 10, 'parent' => 9, 'name' => 'Facultades']];
        $this->assertSame(['existing' => 9, 'missing' => []], cloner::resolve($cats, 9, []));
        $this->assertSame(['existing' => 10, 'missing' => []], cloner::resolve($cats, 9, ['FACULTADES']));
        $this->assertSame(['existing' => 9, 'missing' => ['Otra', 'Sub']], cloner::resolve($cats, 9, ['Otra', 'Sub']));
    }

    /**
     * The seed root itself gives a path of one name.
     */
    public function test_mirror_of_the_root_itself(): void {
        $cats = [1 => ['id' => 1, 'parent' => 0, 'name' => 'Facultades']];
        $this->assertSame(['root' => 1, 'relative' => ['Facultades']], cloner::mirror($cats, 1, sorter::default_patterns_parsed()));
    }
}
