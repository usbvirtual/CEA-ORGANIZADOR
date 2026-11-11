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
#[\PHPUnit\Framework\Attributes\CoversClass(suggester::class)]
/**
 * Tests for the suggester.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\suggester
 */
final class suggester_test extends \advanced_testcase {
    /**
     * Vocabulary used by the accent tests: "ingeniería" twice with accent, once without.
     *
     * @return array
     */
    private function site_vocabulary(): array {
        return suggester::vocabulary([
            'Ingeniería de Sistemas',
            'Ingeniería Civil',
            'Ingenieria Industrial',
            'Estadística',
        ]);
    }

    /**
     * Spaces, hyphens and roman numerals are fixed and the reason is formato.
     */
    public function test_format(): void {
        $this->resetAfterTest();
        $result = suggester::suggest('  Cohorte Xll -Semestre   1 ', [], []);
        $this->assertSame('Cohorte XII - Semestre 1', $result['new']);
        $this->assertSame(['formato'], $result['reasons']);
    }

    /**
     * An accent that the site already writes at least twice is proposed.
     */
    public function test_accent_from_site(): void {
        $this->resetAfterTest();
        $vocabulary = $this->site_vocabulary();
        $this->assertSame(['ingenieria' => ['ingeniería' => 2, 'ingenieria' => 1]], array_intersect_key(
            $vocabulary,
            ['ingenieria' => true]
        ));
        $result = suggester::suggest('Ingenieria Industrial', $vocabulary, []);
        $this->assertSame('Ingeniería Industrial', $result['new']);
        $this->assertSame(['tilde'], $result['reasons']);
    }

    /**
     * One accented occurrence is not enough, and words already accented are left alone.
     */
    public function test_accent_needs_two_occurrences(): void {
        $this->resetAfterTest();
        $result = suggester::suggest('Estadistica Aplicada', $this->site_vocabulary(), []);
        $this->assertSame('Estadistica Aplicada', $result['new']);
        $this->assertSame([], $result['reasons']);
    }

    /**
     * The capitalization of the original word is kept.
     */
    public function test_accent_keeps_case(): void {
        $this->resetAfterTest();
        $vocabulary = $this->site_vocabulary();
        $this->assertSame('INGENIERÍA', suggester::suggest('INGENIERIA', $vocabulary, [])['new']);
        $this->assertSame('ingeniería', suggester::suggest('ingenieria', $vocabulary, [])['new']);
        $this->assertSame('Ingeniería', suggester::suggest('Ingenieria', $vocabulary, [])['new']);
    }

    /**
     * Glossary rules are whole word, case-insensitive and literal; invalid lines are ignored.
     */
    public function test_glossary_whole_word_case_insensitive(): void {
        $this->resetAfterTest();
        $glossary = suggester::parse_glossary("Cien => Ciencias\nsin flecha\n => vacio\nvacio =>\nPerfomance=>Performance\n");
        $this->assertSame([['Cien', 'Ciencias'], ['Perfomance', 'Performance']], $glossary);

        $result = suggester::suggest('CIEN Naturales y cien perfomance Ciencias', [], $glossary);
        $this->assertSame('Ciencias Naturales y Ciencias Performance Ciencias', $result['new']);
        $this->assertSame(['glosario'], $result['reasons']);

        // The replacement is literal, not a regular expression replacement.
        $literal = suggester::suggest('Cien', [], [['Cien', '$1 \\0']]);
        $this->assertSame('$1 \\0', $literal['new']);
    }

    /**
     * Plural variants among siblings go to the most used form; a tie goes to the plural.
     */
    public function test_sibling_plural_variant(): void {
        $this->resetAfterTest();
        $siblings = ['Posgrado', 'Posgrados', 'Especialización', 'Especializaciones', 'Especialización'];
        $vocabulary = suggester::vocabulary(array_merge($siblings, ['Posgrados']));
        $forms = suggester::sibling_forms($siblings, $vocabulary);
        $this->assertSame('posgrados', $forms['posgrado']);
        $this->assertSame('posgrados', $forms['posgrados']);
        $this->assertSame('especialización', $forms['especializacion']);
        $this->assertSame('especialización', $forms['especializaciones']);

        $this->assertSame('Posgrados', suggester::suggest('Posgrado', $vocabulary, [], $forms)['new']);
        $result = suggester::suggest('Especializaciones', $vocabulary, [], $forms);
        $this->assertSame('Especialización', $result['new']);
        $this->assertSame(['variante'], $result['reasons']);

        // A tie goes to the plural.
        $tie = suggester::sibling_forms(['Maestría', 'Maestrías'], suggester::vocabulary(['Maestría', 'Maestrías']));
        $this->assertSame('maestrías', $tie['maestria']);
    }

    /**
     * The rules run in order and each one is reported.
     */
    public function test_chain_reasons(): void {
        $this->resetAfterTest();
        $siblings = ['Posgrado', 'Posgrados'];
        $vocabulary = suggester::vocabulary(array_merge(
            ['Ingeniería A', 'Ingeniería B', 'Posgrados', 'Ciencias'],
            $siblings
        ));
        $glossary = suggester::parse_glossary('Cien => Ciencias');
        $forms = suggester::sibling_forms($siblings, $vocabulary);

        $result = suggester::suggest('  Posgrado   Ingenieria Cien ', $vocabulary, $glossary, $forms);
        $this->assertSame('Posgrados Ingeniería Ciencias', $result['new']);
        $this->assertSame(['formato', 'glosario', 'tilde', 'variante'], $result['reasons']);
    }

    /**
     * A name that is already right comes back untouched and with no reasons.
     */
    public function test_no_change_returns_same(): void {
        $this->resetAfterTest();
        $name = 'Ingeniería de Sistemas';
        $result = suggester::suggest($name, $this->site_vocabulary(), [['Cien', 'Ciencias']], []);
        $this->assertSame($name, $result['new']);
        $this->assertSame([], $result['reasons']);

        $multilang = '<span class="multilang" lang="es">Ingenieria</span>';
        $this->assertSame($multilang, suggester::suggest($multilang, $this->site_vocabulary(), [])['new']);
    }
}
