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
#[\PHPUnit\Framework\Attributes\CoversClass(renamer::class)]
/**
 * Tests for the renamer.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\renamer
 */
final class renamer_test extends \advanced_testcase {
    /**
     * Spaces are collapsed and a lowercase L typed as a roman numeral is fixed.
     */
    public function test_clean_spaces_and_roman_l(): void {
        $this->assertSame(
            'Facultades › Semestre I',
            renamer::apply("  Facultades   ›  Semestre l ", [['type' => 'clean', 'case' => 'none']])
        );
        $this->assertSame(
            'Cohorte XII - Semestre 1',
            renamer::apply('Cohorte Xll -Semestre 1', [['type' => 'clean', 'case' => 'none']])
        );
    }

    /**
     * Title case keeps acronyms and roman numerals and lowercases connectors.
     */
    public function test_title_case_keeps_acronyms_romans_and_connectors(): void {
        $this->assertSame(
            'Maestría en Gestión de la Innovación TIC II',
            renamer::apply('maestría en gestión DE LA innovación TIC ii', [['type' => 'clean', 'case' => 'title']])
        );
    }

    /**
     * An all-caps name has no acronyms: everything is treated as a word.
     */
    public function test_title_case_from_all_caps_has_no_acronyms(): void {
        // Todo en mayúsculas: no hay forma de saber qué es sigla; todo se trata como palabra.
        $this->assertSame(
            'Facultad de Ingeniería',
            renamer::apply('FACULTAD DE INGENIERÍA', [['type' => 'clean', 'case' => 'title']])
        );
    }

    /**
     * Sentence case keeps acronyms and roman numerals.
     */
    public function test_sentence_case(): void {
        $this->assertSame(
            'Maestría en gestión de la innovación TIC II',
            renamer::apply('MAESTRÍA en Gestión DE LA Innovación TIC ii', [['type' => 'clean', 'case' => 'sentence']])
        );
    }

    /**
     * Upper case uses the UTF-8 aware conversion.
     */
    public function test_upper_case(): void {
        $this->assertSame(
            'GESTIÓN AÑO',
            renamer::apply('Gestión año', [['type' => 'clean', 'case' => 'upper']])
        );
    }

    /**
     * Whole-word replacement ignores case when asked and skips partial words.
     */
    public function test_replace_whole_word_case_insensitive(): void {
        $ops = [[
            'type' => 'replace',
            'search' => 'posgrado',
            'replace' => 'Posgrados',
            'casesensitive' => false,
            'wholeword' => true,
        ]];
        $this->assertSame('Posgrados', renamer::apply('Posgrado', $ops));
        $this->assertSame('Posgrados', renamer::apply('Posgrados', $ops)); // Palabra completa: no toca "Posgrados".
    }

    /**
     * Plain replacement is literal, with or without case sensitivity.
     */
    public function test_replace_literal(): void {
        $sensitive = [['type' => 'replace', 'search' => 'a.b', 'replace' => '$1', 'casesensitive' => true, 'wholeword' => false]];
        $this->assertSame('x$1 A.B', renamer::apply('xa.b A.B', $sensitive));
        $insensitive = [['type' => 'replace', 'search' => 'a.b', 'replace' => 'Z', 'casesensitive' => false, 'wholeword' => false]];
        $this->assertSame('xZ Z axb', renamer::apply('xa.b A.B axb', $insensitive));
    }

    /**
     * Adding an affix is idempotent and removing one only happens when present.
     */
    public function test_affix_add_is_idempotent_and_remove(): void {
        $add = [['type' => 'affix', 'position' => 'prefix', 'mode' => 'add', 'text' => '2026-2 ']];
        $this->assertSame('2026-2 Cálculo', renamer::apply('2026-2 Cálculo', $add));
        $this->assertSame('2026-2 Cálculo', renamer::apply('Cálculo', $add));
        $this->assertSame(
            'Cálculo',
            renamer::apply(
                'Cálculo (Docente) 2026-2',
                [['type' => 'affix', 'position' => 'suffix', 'mode' => 'remove', 'text' => ' (Docente) 2026-2']]
            )
        );
        $this->assertSame(
            'Cálculo',
            renamer::apply('Cálculo', [['type' => 'affix', 'position' => 'suffix', 'mode' => 'remove', 'text' => ' (X)']])
        );
    }

    /**
     * A result that is empty after trimming is an error.
     */
    public function test_empty_result_is_error(): void {
        $rows = renamer::preview(
            [['type' => 'category', 'id' => 5, 'name' => 'Eliminar', 'shortname' => null]],
            [['type' => 'affix', 'position' => 'prefix', 'mode' => 'remove', 'text' => 'Eliminar']],
            []
        );
        $this->assertSame('empty', $rows[0]['error']);
    }

    /**
     * Names with markup (multilang) are not transformed.
     */
    public function test_multilang_names_are_skipped(): void {
        $rows = renamer::preview(
            [['type' => 'course', 'id' => 9, 'name' => '<span lang="es" class="multilang">Cálculo</span>', 'shortname' => 'CAL']],
            [['type' => 'clean', 'case' => 'upper']],
            []
        );
        $this->assertSame('multilang', $rows[0]['error']);
    }

    /**
     * A name longer than the maximum is an error.
     */
    public function test_toolong_is_error(): void {
        $rows = renamer::preview(
            [['type' => 'category', 'id' => 1, 'name' => 'Corto', 'shortname' => null]],
            [['type' => 'affix', 'position' => 'suffix', 'mode' => 'add', 'text' => str_repeat('ñ', renamer::MAXLEN)]],
            []
        );
        $this->assertSame('toolong', $rows[0]['error']);
    }

    /**
     * A manual value overrides the result, even for multilang names.
     */
    public function test_manual_overrides_and_unchanged_are_omitted(): void {
        $items = [
            ['type' => 'category', 'id' => 1, 'name' => 'A', 'shortname' => null],
            ['type' => 'category', 'id' => 2, 'name' => 'B', 'shortname' => null],
        ];
        $rows = renamer::preview($items, [], ['category:2' => 'Bé']);
        $this->assertCount(1, $rows);
        $this->assertSame('Bé', $rows[0]['new']);

        $multilang = [['type' => 'course', 'id' => 9, 'name' => '<b>x</b>', 'shortname' => null]];
        $rows = renamer::preview($multilang, [], ['course:9' => 'Manual']);
        $this->assertNull($rows[0]['error']);
        $this->assertSame('Manual', $rows[0]['new']);
    }
}
