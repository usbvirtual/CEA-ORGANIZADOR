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
#[\PHPUnit\Framework\Attributes\CoversClass(cloneplan::class)]
/**
 * Tests for the cloning preview.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\cloneplan
 */
final class cloneplan_test extends \advanced_testcase {
    /** @var int Start date used by the tests. */
    private const START = 1798761600;

    /** @var int End date used by the tests. */
    private const END = 1814486400;

    /**
     * Builds a plan with the default label and dates.
     *
     * @param array $items
     * @param int $periodid
     * @param string $container Folder under the period, or ''.
     * @return array
     */
    private function plan(array $items, int $periodid, string $container = ''): array {
        return cloneplan::build($items, $periodid, '2027-01', self::START, self::END, $container);
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
     * The destination mirrors the seed path under the container and lists the categories that will be created.
     */
    public function test_mirror_destination_and_new_categories(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $eng = $gen->create_category(['name' => 'Facultad de Ingeniería', 'parent' => $root->id]);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $eng->id]);
        $law = $gen->create_category(['name' => 'Facultad de Derecho', 'parent' => $root->id]);
        $penal = $gen->create_category(['name' => 'Penal', 'parent' => $law->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $periodroot = $gen->create_category(['name' => 'Facultades', 'parent' => $period->id]);
        $periodeng = $gen->create_category(['name' => 'Facultad de Ingeniería', 'parent' => $periodroot->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I 2026-02', 'shortname' => 'CALC1', 'category' => $prog->id]);
        $crim = $gen->create_course(['fullname' => 'Penal I', 'shortname' => 'PEN1', 'category' => $penal->id]);

        $plan = $this->plan(
            [['type' => 'course', 'id' => $calc->id], ['type' => 'course', 'id' => $crim->id]],
            $period->id,
            'Facultades'
        );

        $this->assertSame(2, $plan['total']);
        $a = $this->row($plan, (int) $calc->id);
        $this->assertSame(0, $a['destcategoryid']);
        $this->assertSame('2027-01 / Facultades / Facultad de Ingeniería / Programa Sistemas', $a['destpath']);
        $this->assertSame([$a['destpath']], $a['newcategories']);
        $this->assertSame('Cálculo I 2027-01', $a['newfullname']);
        $this->assertSame('CALC1-2027-01', $a['newshortname']);
        $this->assertNull($a['error']);

        $b = $this->row($plan, (int) $crim->id);
        $this->assertSame(0, $b['destcategoryid']);
        $this->assertSame('2027-01 / Facultades / Facultad de Derecho / Penal', $b['destpath']);
        $this->assertSame(
            ['2027-01 / Facultades / Facultad de Derecho', '2027-01 / Facultades / Facultad de Derecho / Penal'],
            $b['newcategories']
        );

        // When the whole path exists the destination is that category and nothing is created.
        $periodprog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $periodeng->id]);
        $again = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id, 'Facultades');
        $this->assertSame((int) $periodprog->id, $again['rows'][0]['destcategoryid']);
        $this->assertSame([], $again['rows'][0]['newcategories']);
    }

    /**
     * A course whose copy already exists in the destination is skipped on a relaunch, also inside a container.
     */
    public function test_relaunch_skips_already_cloned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $periodroot = $gen->create_category(['name' => 'Facultades', 'parent' => $period->id]);
        $periodprog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $periodroot->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $prog->id]);
        $gen->create_course(['fullname' => 'Cálculo I 2027-01', 'shortname' => 'CALC1-2027-01', 'category' => $periodprog->id]);

        $plan = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id, 'Facultades');
        $this->assertSame((int) $periodprog->id, $plan['rows'][0]['destcategoryid']);
        $this->assertSame('alreadycloned', $plan['rows'][0]['error']);

        // Without the container the destination is another category: the name is taken, not already cloned.
        $plain = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id);
        $this->assertSame('shortnametaken', $plain['rows'][0]['error']);
    }

    /**
     * A short name used elsewhere, or by two rows of the batch, is an error.
     */
    public function test_shortname_taken_and_duplicate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $one = $gen->create_category(['name' => 'Programa Uno', 'parent' => $root->id]);
        $two = $gen->create_category(['name' => 'Programa Dos', 'parent' => $root->id]);
        $other = $gen->create_category(['name' => 'Otros']);
        $period = $gen->create_category(['name' => '2027-01']);
        $a = $gen->create_course(['fullname' => 'A 2026-02', 'shortname' => 'A-2026-02', 'category' => $one->id]);
        $b = $gen->create_course(['fullname' => 'B 2025-01', 'shortname' => 'A-2025-01', 'category' => $two->id]);
        $t = $gen->create_course(['fullname' => 'T', 'shortname' => 'T', 'category' => $one->id]);
        $gen->create_course(['fullname' => 'Otro', 'shortname' => 'T-2027-01', 'category' => $other->id]);

        $plan = $this->plan([['type' => 'category', 'id' => $root->id]], $period->id);
        $this->assertSame('shortnameduplicate', $this->row($plan, (int) $a->id)['error']);
        $this->assertSame('shortnameduplicate', $this->row($plan, (int) $b->id)['error']);
        $this->assertSame('shortnametaken', $this->row($plan, (int) $t->id)['error']);
    }

    /**
     * A period inside the seeds is rejected on every row.
     */
    public function test_destination_inside_seeds_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01', 'parent' => $root->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $prog->id]);

        $plan = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id);
        $this->assertSame('destinsideseeds', $plan['rows'][0]['error']);
    }

    /**
     * A category item expands to its courses and those of its descendants, once each and in a stable order.
     */
    public function test_category_item_expands_descendants(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $pa = $gen->create_category(['name' => 'Programa A', 'parent' => $root->id]);
        $pb = $gen->create_category(['name' => 'Programa B', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $c2 = $gen->create_course(['shortname' => 'C2', 'category' => $pb->id]);
        $c1 = $gen->create_course(['shortname' => 'C1', 'category' => $pa->id]);
        $c0 = $gen->create_course(['shortname' => 'C0', 'category' => $root->id]);

        $items = [
            ['type' => 'category', 'id' => $root->id],
            ['type' => 'course', 'id' => $c1->id],
            ['type' => 'course', 'id' => SITEID],
        ];
        $plan = $this->plan($items, $period->id);
        $this->assertSame(3, $plan['total']);
        $this->assertSame(
            [(int) $c0->id, (int) $c1->id, (int) $c2->id],
            array_column($plan['rows'], 'courseid')
        );
    }

    /**
     * A user who cannot copy a course gets an error on that row instead of an exception.
     */
    public function test_nopermission_row(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $root->id]);

        $user = $gen->create_user();
        $roleid = $gen->create_role();
        assign_capability('tool/ceaorganizador:manage', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->assertFalse(has_capability('moodle/backup:backupcourse', \context_course::instance($calc->id)));

        $plan = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id);
        $this->assertSame('nopermission', $plan['rows'][0]['error']);
    }

    /**
     * The hash depends on the dates, the period and the label.
     */
    public function test_hash_changes_with_dates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $root->id]);
        $items = [['type' => 'course', 'id' => $calc->id]];

        $base = cloneplan::build($items, $period->id, '2027-01', self::START, self::END);
        $same = cloneplan::build($items, $period->id, '2027-01', self::START, self::END);
        $later = cloneplan::build($items, $period->id, '2027-01', self::START, self::END + DAYSECS);
        $this->assertSame($base['hash'], $same['hash']);
        $this->assertNotSame($base['hash'], $later['hash']);

        // The container is part of the signature; spaces around it are not.
        $folder = cloneplan::build($items, $period->id, '2027-01', self::START, self::END, 'Facultades');
        $spaced = cloneplan::build($items, $period->id, '2027-01', self::START, self::END, '  Facultades ');
        $this->assertNotSame($base['hash'], $folder['hash']);
        $this->assertSame($folder['hash'], $spaced['hash']);
    }

    /**
     * Invalid label, dates or period are rejected.
     */
    public function test_invalid_parameters_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $period = $this->getDataGenerator()->create_category(['name' => '2027-01']);

        foreach (
            [
                [$period->id, '2027 II', self::START, self::END],
                [$period->id, '2027-01', self::END, self::START],
                [$period->id, '2027-01', 0, self::END],
                [$period->id + 1000, '2027-01', self::START, self::END],
            ] as [$id, $label, $start, $end]
        ) {
            try {
                cloneplan::build([], $id, $label, $start, $end);
                $this->fail('Expected invalid_parameter_exception');
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }

        // Category 0 would expand to the whole site; negative ids are just as wrong.
        foreach ([['type' => 'category', 'id' => 0], ['type' => 'course', 'id' => -3]] as $item) {
            try {
                cloneplan::build([$item], $period->id, '2027-01', self::START, self::END);
                $this->fail('Expected invalid_parameter_exception');
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
    }

    /**
     * A name that becomes longer than 254 characters is an error on its row.
     */
    public function test_toolong_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $long = $gen->create_course(['fullname' => 'Largo', 'shortname' => str_repeat('x', 250), 'category' => $root->id]);

        $plan = $this->plan([['type' => 'course', 'id' => $long->id]], $period->id);
        $this->assertSame(258, \core_text::strlen($plan['rows'][0]['newshortname']));
        $this->assertSame('toolong', $plan['rows'][0]['error']);
    }

    /**
     * The seed root itself cannot be the period.
     */
    public function test_period_equal_seed_root_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $prog->id]);

        $plan = $this->plan([['type' => 'course', 'id' => $calc->id]], $root->id);
        $this->assertSame('destinsideseeds', $plan['rows'][0]['error']);
    }

    /**
     * New names are raw: an ampersand is not turned into an entity.
     */
    public function test_ampersand_name_stays_raw(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $course = $gen->create_course(['fullname' => 'A & B', 'shortname' => 'AB', 'category' => $root->id]);

        $plan = $this->plan([['type' => 'course', 'id' => $course->id]], $period->id);
        $this->assertSame('A & B 2027-01', $plan['rows'][0]['newfullname']);
        $this->assertNull($plan['rows'][0]['error']);
    }

    /**
     * More courses than the batch limit are rejected.
     */
    public function test_too_many_courses_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        for ($i = 1; $i <= cloneplan::MAX_ROWS + 1; $i++) {
            $gen->create_course(['fullname' => "Curso $i", 'shortname' => "C$i", 'category' => $root->id]);
        }

        try {
            $this->plan([['type' => 'category', 'id' => $root->id]], (int) $period->id);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_toomanyclones', $e->errorcode);
        }
    }

    /**
     * Without a container the mirrored path hangs directly from the period, without the seed root.
     */
    public function test_no_container_hangs_from_period(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $eng = $gen->create_category(['name' => 'Facultad de Ingeniería', 'parent' => $root->id]);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $eng->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $periodeng = $gen->create_category(['name' => 'Facultad de Ingenieria', 'parent' => $period->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $prog->id]);

        $row = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id)['rows'][0];
        $this->assertSame('2027-01 / Facultad de Ingenieria / Programa Sistemas', $row['destpath']);
        $this->assertSame(['2027-01 / Facultad de Ingenieria / Programa Sistemas'], $row['newcategories']);
        $this->assertSame(0, $row['destcategoryid']);
        $this->assertNull($row['error']);

        // Once the program exists under the period's faculty, that is the destination.
        $periodprog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $periodeng->id]);
        $row = $this->plan([['type' => 'course', 'id' => $calc->id]], $period->id)['rows'][0];
        $this->assertSame((int) $periodprog->id, $row['destcategoryid']);
        $this->assertSame([], $row['newcategories']);
    }

    /**
     * A course directly in the seed root goes to the period itself, or to the container.
     */
    public function test_course_directly_in_seed_root(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $course = $gen->create_course(['fullname' => 'Inducción', 'shortname' => 'IND', 'category' => $root->id]);
        $items = [['type' => 'course', 'id' => $course->id]];

        $row = $this->plan($items, $period->id)['rows'][0];
        $this->assertSame((int) $period->id, $row['destcategoryid']);
        $this->assertSame('2027-01', $row['destpath']);
        $this->assertSame([], $row['newcategories']);

        $row = $this->plan($items, $period->id, 'Facultades')['rows'][0];
        $this->assertSame(0, $row['destcategoryid']);
        $this->assertSame('2027-01 / Facultades', $row['destpath']);
        $this->assertSame(['2027-01 / Facultades'], $row['newcategories']);
    }

    /**
     * A container that is a period name, or too long for a category, is rejected; blanks mean no container.
     */
    public function test_container_rejects_period_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $course = $gen->create_course(['fullname' => 'Inducción', 'shortname' => 'IND', 'category' => $root->id]);
        $items = [['type' => 'course', 'id' => $course->id]];

        foreach (['2027-01', ' 2026 II ', 'Periodo 2027-02', str_repeat('x', 255)] as $container) {
            try {
                $this->plan($items, $period->id, $container);
                $this->fail('Expected invalid_parameter_exception');
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }

        $this->assertSame((int) $period->id, $this->plan($items, $period->id, '   ')['rows'][0]['destcategoryid']);
        $longest = str_repeat('x', 254);
        $this->assertSame('2027-01 / ' . $longest, $this->plan($items, $period->id, $longest)['rows'][0]['destpath']);
    }
}
