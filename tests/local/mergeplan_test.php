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
     * @param int $timemodified 0 keeps the time of creation.
     * @return int
     */
    private function course(string $fullname, \core_course_category $category, int $timemodified = 0): int {
        global $DB;
        static $n = 0;
        $n++;
        $course = $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => 'MP' . $n,
            'category' => $category->id,
        ]);
        if ($timemodified > 0) {
            $DB->set_field('course', 'timemodified', $timemodified, ['id' => $course->id]);
        }
        return (int) $course->id;
    }

    /**
     * Rows of a course that is already in the target (they have no source row).
     *
     * @param array $plan
     * @return array Map course id => row.
     */
    private function existing_rows(array $plan): array {
        $rows = [];
        foreach ($plan['rows'] as $row) {
            if ($row['existing']) {
                $rows[$row['courseid']] = $row;
            }
        }
        return $rows;
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
     * A source course older than its repeat in the destination goes to Repetidos; the target course stays.
     */
    public function test_older_source_course_goes_to_repeated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targeteng = $this->cat('Ingeniería', $target);
        $source = $this->cat('Facultades');
        $eng = $this->cat('Ingenieria', $source);
        $kept = $this->course('Cálculo I', $targeteng, 2000);
        $dup = $this->course('CALCULO I (copia)', $eng, 1000);
        $other = $this->course('Álgebra', $eng);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $row = $this->row($plan, $dup);
        $this->assertTrue($row['duplicate']);
        $this->assertSame('repeated', $row['action']);
        $this->assertFalse($row['winner']);
        $this->assertSame(['key'], $row['reasons']);
        $existing = $this->existing_rows($plan);
        $this->assertSame([$kept], array_keys($existing));
        $this->assertSame('move', $existing[$kept]['action']);
        $this->assertTrue($existing[$kept]['winner']);
        $this->assertSame($row['group'], $existing[$kept]['group']);
        $this->assertSame(min($kept, $dup) . '-' . max($kept, $dup), $row['group']);
        $this->assertFalse($this->row($plan, $other)['duplicate']);
        $this->assertSame('move', $this->row($plan, $other)['action']);
        $this->assertSame('', $this->row($plan, $other)['group']);
        $this->assertSame(['id' => 0, 'path' => 'Semillas / Repetidos'], $plan['folder']);
    }

    /**
     * A target course that loses to a newer source course gets its own row, to Repetidos, next to its group.
     */
    public function test_existing_target_course_that_loses_goes_to_repeated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targeteng = $this->cat('Ingeniería', $target);
        $source = $this->cat('Facultades');
        $eng = $this->cat('Ingenieria', $source);
        $old = $this->course('Cálculo I', $targeteng, 1000);
        $new = $this->course('Cálculo I', $eng, 2000);
        $other = $this->course('Álgebra', $eng);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertSame('move', $this->row($plan, $new)['action']);
        $this->assertTrue($this->row($plan, $new)['winner']);
        $this->assertSame(['name'], $this->row($plan, $new)['reasons']);
        $existing = $this->existing_rows($plan)[$old];
        $this->assertSame('repeated', $existing['action']);
        $this->assertSame('Semillas / Ingeniería', $existing['from']);
        $this->assertSame((int) $targeteng->id, $existing['toid']);
        $this->assertFalse($existing['winner']);
        // The group stays together: the target course right after the source row of its group.
        $order = array_column($plan['rows'], 'courseid');
        $this->assertCount(3, $order);
        $this->assertContains($other, $order);
        $this->assertSame(array_search($new, $order, true) + 1, array_search($old, $order, true));
    }

    /**
     * Courses repeated only among the target courses are left alone; an existing «Repetidos» folder is reused.
     */
    public function test_target_only_repeats_ignored_and_folder_reused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targeteng = $this->cat('Ingeniería', $target);
        $folder = $this->cat('REPETIDOS', $target);
        $this->course('Física', $targeteng);
        $this->course('Física (copia)', $targeteng);
        $source = $this->cat('Facultades');
        $eng = $this->cat('Ingeniería', $source);
        $chem = $this->course('Química', $eng);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertSame([$chem], array_column($plan['rows'], 'courseid'));
        $this->assertSame(['id' => (int) $folder->id, 'path' => 'Semillas / REPETIDOS'], $plan['folder']);
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
     * Two source courses repeated across sources and landing in the same new category: the newest wins.
     */
    public function test_batch_duplicate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $first = $this->cat('Facultades');
        $second = $this->cat('Semillas viejas');
        $eng1 = $this->cat('Ingeniería', $first);
        $eng2 = $this->cat('INGENIERIA', $second);
        $a = $this->course('Cálculo I 2025-2', $eng1, 3000);
        $b = $this->course('Calculo I', $eng2, 1000);
        $c = $this->course('Física', $eng2);

        $plan = mergeplan::build((int) $target->id, [(int) $first->id, (int) $second->id]);

        $rowa = $this->row($plan, $a);
        $rowb = $this->row($plan, $b);
        $this->assertSame($rowa['to'], $rowb['to']);
        $this->assertSame($rowa['newcategories'], $rowb['newcategories']);
        $this->assertSame(['Semillas / Ingeniería'], $rowb['newcategories']);
        $this->assertTrue($rowa['duplicate']);
        $this->assertTrue($rowb['duplicate']);
        $this->assertSame($rowa['group'], $rowb['group']);
        $this->assertSame('move', $rowa['action']);
        $this->assertSame('repeated', $rowb['action']);
        $this->assertSame('move', $this->row($plan, $c)['action']);
        $this->assertSame([], $this->existing_rows($plan));
    }

    /**
     * Courses with the same name in different destinations are not repeated.
     */
    public function test_only_same_destination_compared(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades');
        $sys = $this->cat('Sistemas', $source);
        $civil = $this->cat('Civil', $source);
        $a = $this->course('Cálculo I', $sys);
        $b = $this->course('Cálculo I', $civil);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertFalse($this->row($plan, $a)['duplicate']);
        $this->assertFalse($this->row($plan, $b)['duplicate']);
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
     * A repeated course leaves its source category too (to Repetidos), so that category is emptied as well.
     */
    public function test_emptied_counts_repeated_courses_as_leaving(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $targetprog = $this->cat('Programa', $target);
        $source = $this->cat('Facultades');
        $prog = $this->cat('Programa', $source);
        $other = $this->cat('Otro', $source);
        $this->course('Repetido', $targetprog, 2000);
        $repeated = $this->course('repetido', $prog, 1000);
        $this->course('Libre', $other);

        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertSame('repeated', $this->row($plan, $repeated)['action']);
        $this->assertSame(
            [(int) $prog->id, (int) $other->id, (int) $source->id],
            array_column($plan['emptied'], 'id')
        );
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

    /**
     * The hash covers the winner of each group: a course modified since the preview changes it.
     */
    public function test_hash_covers_winners(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $target = $this->cat('Semillas');
        $source = $this->cat('Facultades');
        $a = $this->course('Cálculo I', $source, 1000);
        $this->course('Cálculo I copia 1', $source, 2000);

        $one = mergeplan::build((int) $target->id, [(int) $source->id]);
        $this->assertSame('repeated', $this->row($one, $a)['action']);
        $DB->set_field('course', 'timemodified', 3000, ['id' => $a]);
        $two = mergeplan::build((int) $target->id, [(int) $source->id]);

        $this->assertSame('move', $this->row($two, $a)['action']);
        $this->assertNotSame($one['hash'], $two['hash']);
    }
}
