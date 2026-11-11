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
     * Sibling names that differ only by a plural word are unified; a tie goes to the plural.
     */
    public function test_sibling_plural_variant(): void {
        $this->resetAfterTest();
        $siblings = ['Posgrado', 'Posgrados', 'Facultad de Ingeniería - Posgrado', 'Facultad de Ingeniería - Posgrados'];
        $vocabulary = suggester::vocabulary(array_merge($siblings, ['Posgrados']));
        $forms = suggester::sibling_forms($siblings, $vocabulary);
        $this->assertSame(['Posgrado', 'Facultad de Ingeniería - Posgrado'], array_keys($forms));
        $this->assertSame(['posgrado' => 'posgrados'], $forms['Posgrado']);

        $result = suggester::suggest('Posgrado', $vocabulary, [], $forms['Posgrado']);
        $this->assertSame('Posgrados', $result['new']);
        $this->assertSame(['variante'], $result['reasons']);
        $longname = 'Facultad de Ingeniería - Posgrado';
        $long = suggester::suggest($longname, $vocabulary, [], $forms[$longname]);
        $this->assertSame('Facultad de Ingeniería - Posgrados', $long['new']);

        // The most frequent form wins, even when it is the singular; a tie goes to the plural.
        $names = ['Maestría', 'Maestrías', 'Diplomado', 'Diplomados'];
        $vocabulary = suggester::vocabulary(array_merge($names, ['Diplomado']));
        $forms = suggester::sibling_forms($names, $vocabulary);
        $this->assertSame(
            ['Maestría' => ['maestria' => 'maestrías'], 'Diplomados' => ['diplomados' => 'diplomado']],
            $forms
        );
        $this->assertSame('Diplomado', suggester::suggest('Diplomados', $vocabulary, [], $forms['Diplomados'])['new']);
    }

    /**
     * Words of different names are never paired, and a name outside the siblings is not touched.
     */
    public function test_sibling_needs_same_name_but_one_word(): void {
        $this->resetAfterTest();
        $siblings = ['Ciencia Política', 'Ciencias Sociales'];
        $vocabulary = suggester::vocabulary($siblings);
        $this->assertSame([], suggester::sibling_forms($siblings, $vocabulary));
        $this->assertSame('Ciencia Política', suggester::suggest('Ciencia Política', $vocabulary, [], [])['new']);

        // Different structure (word count or separators): no pairing.
        $different = ['Posgrado', 'Posgrados de Ingeniería', 'A - Posgrado', 'A Posgrados'];
        $this->assertSame([], suggester::sibling_forms($different, []));

        // Only the entry of the name changes it.
        $forms = suggester::sibling_forms(['Posgrado', 'Posgrados'], suggester::vocabulary(['Posgrados']));
        $this->assertSame('Posgrado Otro', suggester::suggest('Posgrado Otro', [], [], [])['new']);
        $this->assertSame('Posgrados Otro', suggester::suggest('Posgrado Otro', [], [], $forms['Posgrado'])['new']);
    }

    /**
     * Only the plural suffix changes: no accent is imported from the other form.
     */
    public function test_sibling_keeps_accent_state(): void {
        $this->resetAfterTest();
        $siblings = ['Especialización', 'Especializaciones', 'Educación', 'Educaciones'];
        $vocabulary = suggester::vocabulary(['Especialización', 'Especialización', 'Especializaciones']);
        $forms = suggester::sibling_forms($siblings, $vocabulary);
        // The singular is the most used: the plural goes to the singular and the singular is untouched.
        $this->assertSame(['especializaciones' => 'especialización'], $forms['Especializaciones']);
        $this->assertSame(false, isset($forms['Especialización']));
        $plural = suggester::suggest('Especializaciones', $vocabulary, [], $forms['Especializaciones']);
        $this->assertSame('Especialización', $plural['new']);
        // The plural wins on a tie; the stem keeps its own accents.
        $this->assertSame(['educacion' => 'educaciones'], $forms['Educación']);
        $this->assertSame('Educaciones', suggester::suggest('Educación', $vocabulary, [], $forms['Educación'])['new']);
        $this->assertSame('Educacion', suggester::suggest('Educacion', $vocabulary, [], [])['new']);
    }

    /**
     * The preferred word is copied as written, so accented endings stay correct in both directions.
     */
    public function test_sibling_copies_written_form(): void {
        $this->resetAfterTest();
        $cases = [
            // [vocabulary names, singular, plural, expected singular result, expected plural result].
            [['Sesión', 'Sesiones'], 'Sesión', 'Sesiones', 'Sesiones', 'Sesiones'],
            [['Sesión', 'Sesión', 'Sesiones'], 'Sesión', 'Sesiones', 'Sesión', 'Sesión'],
            [['Región', 'Región', 'Región', 'Regiones'], 'Región', 'Regiones', 'Región', 'Región'],
            [['Extensión', 'Extensiones', 'Extensiones'], 'Extensión', 'Extensiones', 'Extensiones', 'Extensiones'],
            [['Inglés', 'Ingleses', 'Ingleses'], 'Inglés', 'Ingleses', 'Ingleses', 'Ingleses'],
            [['Inglés', 'Inglés', 'Ingleses'], 'Inglés', 'Ingleses', 'Inglés', 'Inglés'],
        ];
        foreach ($cases as [$names, $singular, $plural, $expectedsingular, $expectedplural]) {
            $vocabulary = suggester::vocabulary($names);
            $forms = suggester::sibling_forms([$singular, $plural], $vocabulary);
            $this->assertSame($expectedsingular, suggester::suggest($singular, $vocabulary, [], $forms[$singular] ?? [])['new']);
            $this->assertSame($expectedplural, suggester::suggest($plural, $vocabulary, [], $forms[$plural] ?? [])['new']);
        }
        // Uppercase keeps its case pattern.
        $vocabulary = suggester::vocabulary(['Región', 'Región', 'Regiones']);
        $forms = suggester::sibling_forms(['REGIONES', 'REGIÓN'], $vocabulary);
        $this->assertSame('REGIÓN', suggester::suggest('REGIONES', $vocabulary, [], $forms['REGIONES'])['new']);
    }

    /**
     * Words whose accent changes the meaning are never accented.
     */
    public function test_accent_stoplist(): void {
        $this->resetAfterTest();
        $vocabulary = suggester::vocabulary(['Qué', 'Qué hacer', 'Qué es', 'que', 'Él', 'Él y yo', 'Él es', 'el']);
        $this->assertSame('Que es', suggester::suggest('Que es', $vocabulary, [])['new']);
        $this->assertSame('El curso', suggester::suggest('El curso', $vocabulary, [])['new']);
        $this->assertSame([], suggester::suggest('el curso', $vocabulary, [])['reasons']);
    }

    /**
     * A tie, a different accent, and codes with digits are left alone.
     */
    public function test_accent_ties_other_accents_and_codes(): void {
        $this->resetAfterTest();
        $tie = suggester::vocabulary(['Ingeniería', 'Ingeniería A', 'Ingenieria', 'Ingenieria B']);
        $this->assertSame('Ingenieria Z', suggester::suggest('Ingenieria Z', $tie, [])['new']);

        $vocabulary = suggester::vocabulary(['Química', 'Química A', 'Quimica']);
        $this->assertSame('Quìmica', suggester::suggest('Quìmica', $vocabulary, [])['new']);

        $codes = suggester::vocabulary(['Cálc1', 'Cálc1 A', '2026S1C01']);
        foreach (['CALC1', '2026S1C01', 'Calc1'] as $code) {
            $result = suggester::suggest($code, $codes, []);
            $this->assertSame($code, $result['new']);
            $this->assertSame([], $result['reasons']);
        }
    }

    /**
     * The rules run in order and each one is reported; running again changes nothing.
     */
    public function test_chain_reasons(): void {
        $this->resetAfterTest();
        $siblings = ['Posgrado Ingenieria Cien', 'Posgrados Ingenieria Cien'];
        $vocabulary = suggester::vocabulary(array_merge(
            ['Ingeniería A', 'Ingeniería B', 'Ingeniería C', 'Ingeniería D', 'Posgrados', 'Ciencias'],
            $siblings
        ));
        $glossary = suggester::parse_glossary('Cien => Ciencias');
        $forms = suggester::sibling_forms($siblings, $vocabulary);
        $entry = $forms['Posgrado Ingenieria Cien'];

        $result = suggester::suggest('  Posgrado   Ingenieria Cien ', $vocabulary, $glossary, $entry);
        $this->assertSame('Posgrados Ingeniería Ciencias', $result['new']);
        $this->assertSame(['formato', 'glosario', 'tilde', 'variante'], $result['reasons']);

        $again = suggester::suggest($result['new'], $vocabulary, $glossary, $entry);
        $this->assertSame('Posgrados Ingeniería Ciencias', $again['new']);
        $this->assertSame([], $again['reasons']);

        // Glossary output with its own spacing is cleaned, so the result is stable.
        $rules = [['Cien', 'Ciencias   Básicas']];
        $spaced = suggester::suggest('Cien', [], $rules);
        $this->assertSame('Ciencias Básicas', $spaced['new']);
        $this->assertSame([], suggester::suggest($spaced['new'], [], $rules)['reasons']);
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
