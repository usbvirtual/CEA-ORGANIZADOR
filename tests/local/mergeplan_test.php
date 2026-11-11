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
#[\PHPUnit\Framework\Attributes\CoversClass(mergeplan::class)]
/**
 * Tests for the merge preview.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\mergeplan
 */
final class mergeplan_test extends \advanced_testcase {
    /**
     * Creates a category.
     *
     * @param string $name
     * @param \core_course_category|null $parent
     * @return \core_course_category
     */
    private function cat(string $name, ?\core_course_category $parent = null): \core_course_category {
        $record = $this->getDataGenerator()->create_category(['name' => $name, 'parent' => $parent ? $parent->id : 0]);
        return \core_course_category::get((int) $record->id, MUST_EXIST, true);
    }

    /**
     * Creates a course.
     *
     * @param string $fullname
     * @param \core_course_category $category
     * @return int
     */
    private function course(string $fullname, \core_course_category $category): int {
        static $n = 0;
        $n++;
        $course = $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => 'MP' . $n,
            'category' => $category->id,
        ]);
        return (int) $course->id;
    }

    /**
     * Finds the row of a course.
     *
     * @param array $plan
     * @param int $courseid
     * @return array
     */
    private function row(array $plan, int $courseid): array {
        foreach ($plan['rows'] as $row) {
            if ($row['courseid'] === $courseid) {
                return $row;
            }
        }
        $this->fail('Row not found');
    }

    /**
     * The destination mirrors the source path and lists the categories that will be created.
     */
    public function test_mirror_paths_and_new_categories(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targeteng = $this->cat('Facultad de Ingeniería', $target);
        $source = $this->cat('Facultades');
        $eng = $this->cat('Facultad de Ingeniería', $source);
        $prog = $this->cat('Programa Sistemas', $eng);
        $sem = $this->cat('Semestre 1', $prog);
        $law = $this->cat('Facultad de Derecho', $source);
        $calc = $this->course('Cálculo I', $sem);
        $penal = $this->course('Penal I', $law);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertCount(2, $plan['rows']);
        $row = $this->row($plan, $calc);
        $this->assertSame('Facultades / Facultad de Ingeniería / Programa Sistemas / Semestre 1', $row['from']);
        $this->assertSame('Semillas / Facultad de Ingeniería / Programa Sistemas / Semestre 1', $row['to']);
        $this->assertSame(0, $row['toid']);
        $this->assertSame([
            'Semillas / Facultad de Ingeniería / Programa Sistemas',
            'Semillas / Facultad de Ingeniería / Programa Sistemas / Semestre 1',
        ], $row['newcategories']);
        $this->assertSame('move', $row['action']);
        $this->assertFalse($row['duplicate']);

        $row = $this->row($plan, $penal);
        $this->assertSame('Semillas / Facultad de Derecho', $row['to']);
        $this->assertSame(['Semillas / Facultad de Derecho'], $row['newcategories']);
        $this->assertNotSame((int) $targeteng->id, $row['toid']);
    }

    /**
     * Existing categories are matched without caring about accents or case.
     */
    public function test_accent_insensitive_match(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targeteng = $this->cat('FACULTAD DE INGENIERIA', $target);
        $source = $this->cat('Facultades');
        $eng = $this->cat('Facultad de Ingeniería', $source);
        $calc = $this->course('Cálculo I', $eng);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $row = $this->row($plan, $calc);
        $this->assertSame((int) $targeteng->id, $row['toid']);
        $this->assertSame('Semillas / FACULTAD DE INGENIERIA', $row['to']);
        $this->assertSame([], $row['newcategories']);
    }

    /**
     * A course with the same normalized name in the destination is a duplicate and is skipped by default.
     */
    public function test_duplicate_detection_default_skip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targeteng = $this->cat('Ingeniería', $target);
        $source = $this->cat('Facultades');
        $eng = $this->cat('Ingenieria', $source);
        $this->course('Cálculo I', $targeteng);
        $dup = $this->course('CALCULO I', $eng);
        $other = $this->course('Álgebra', $eng);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertTrue($this->row($plan, $dup)['duplicate']);
        $this->assertSame('skip', $this->row($plan, $dup)['action']);
        $this->assertFalse($this->row($plan, $other)['duplicate']);
        $this->assertSame('move', $this->row($plan, $other)['action']);
    }

    /**
     * A course directly in a source root goes to the target itself.
     */
    public function test_course_directly_in_source_goes_to_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades');
        $loose = $this->course('Suelto', $source);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $row = $this->row($plan, $loose);
        $this->assertSame((int) $target->id, $row['toid']);
        $this->assertSame('Semillas', $row['to']);
        $this->assertSame('Facultades', $row['from']);
        $this->assertSame([], $row['newcategories']);
    }

    /**
     * Two source courses with the same name landing in the same new category: the second is a duplicate.
     */
    public function test_batch_duplicate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $first = $this->cat('Facultades');
        $second = $this->cat('Semillas viejas');
        $eng1 = $this->cat('Ingeniería', $first);
        $eng2 = $this->cat('INGENIERIA', $second);
        $a = $this->course('Cálculo I', $eng1);
        $b = $this->course('Calculo I', $eng2);
        $c = $this->course('Física', $eng2);

        $plan = mergeplan::build((int) $target->id, [(int) $first->id, (int) $second->id]);

        $rowa = $this->row($plan, $a);
        $rowb = $this->row($plan, $b);
        $this->assertSame($rowa['to'], $rowb['to']);
        $this->assertSame($rowa['newcategories'], $rowb['newcategories']);
        $this->assertSame(['Semillas / Ingeniería'], $rowb['newcategories']);
        $this->assertSame(1, count(array_filter([$rowa, $rowb], static fn($r) => $r['duplicate'])));
        $this->assertSame('move', $rowa['action']);
        $this->assertSame('skip', $rowb['action']);
        $this->assertSame('move', $this->row($plan, $c)['action']);
    }

    /**
     * A source that contains the target is rejected.
     */
    public function test_target_inside_source_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $target = $this->cat('Semillas', $source);
        $this->expectException(\invalid_parameter_exception::class);
        mergeplan::build((int) $target->id, [(int) $source->id]);
    }

    /**
     * A source inside the target is rejected.
     */
    public function test_source_inside_target_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades', $target);
        $this->expectException(\invalid_parameter_exception::class);
        mergeplan::build((int) $target->id, [(int) $source->id]);
    }

    /**
     * Empty or repeated sources are rejected.
     */
    public function test_invalid_sources_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades');
        try {
            mergeplan::build((int) $target->id, []);
            $this->fail('Empty sources accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        }
        $this->expectException(\invalid_parameter_exception::class);
        mergeplan::build((int) $target->id, [(int) $source->id, (int) $source->id]);
    }

    /**
     * Source categories that would be left empty are listed, deepest first, roots included.
     */
    public function test_emptied_list(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades');
        $prog = $this->cat('Programa', $source);
        $sem = $this->cat('Semestre 1', $prog);
        $other = $this->cat('Otro', $source);
        $this->course('Cálculo', $sem);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertSame(
            [(int) $sem->id, (int) $prog->id, (int) $other->id, (int) $source->id],
            array_column($plan['emptied'], 'id')
        );
        $this->assertSame('Facultades / Programa / Semestre 1', $plan['emptied'][0]['path']);
    }

    /**
     * A category with a skipped course stays, and so do its ancestors.
     */
    public function test_emptied_keeps_categories_with_skipped_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targetprog = $this->cat('Programa', $target);
        $source = $this->cat('Facultades');
        $prog = $this->cat('Programa', $source);
        $other = $this->cat('Otro', $source);
        $this->course('Repetido', $targetprog);
        $this->course('repetido', $prog);
        $this->course('Libre', $other);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertSame([(int) $other->id], array_column($plan['emptied'], 'id'));
        $this->assertSame('Facultades / Otro', $plan['emptied'][0]['path']);
    }

    /**
     * The hash is stable for the same input and changes when the plan changes.
     */
    public function test_hash_stable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades');
        $eng = $this->cat('Ingeniería', $source);
        $this->course('Cálculo I', $eng);

        $one = mergeplan::build((int) $target->id, [(int) $source->id]);
        $two = mergeplan::build((int) $target->id, [(int) $source->id]);
        $this->assertSame($one['hash'], $two['hash']);

        $this->cat('Ingeniería', $target);
        $three = mergeplan::build((int) $target->id, [(int) $source->id]);
        $this->assertNotSame($one['hash'], $three['hash']);
    }
}
