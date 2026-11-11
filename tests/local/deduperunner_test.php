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
#[\PHPUnit\Framework\Attributes\CoversClass(dedupeplan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(deduperunner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(batchrunner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(journal::class)]
/**
 * Tests for «Quitar repetidos»: preview, run and undo.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\dedupeplan
 * @covers     \tool_ceaorganizador\local\deduperunner
 * @covers     \tool_ceaorganizador\local\batchrunner
 * @covers     \tool_ceaorganizador\local\journal
 */
final class deduperunner_test extends \advanced_testcase {
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
     * @param string $shortname '' for a unique one.
     * @return int
     */
    private function course(string $fullname, \core_course_category $category, int $timemodified = 0, string $shortname = ''): int {
        global $DB;
        static $n = 0;
        $n++;
        $course = $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => $shortname !== '' ? $shortname : 'DR' . $n,
            'category' => $category->id,
        ]);
        if ($timemodified > 0) {
            $DB->set_field('course', 'timemodified', $timemodified, ['id' => $course->id]);
        }
        return (int) $course->id;
    }

    /**
     * Category of a course.
     *
     * @param int $courseid
     * @return int
     */
    private function category_of(int $courseid): int {
        global $DB;
        return (int) $DB->get_field('course', 'category', ['id' => $courseid], MUST_EXIST);
    }

    /**
     * Visibility of a course or a category.
     *
     * @param string $table course or course_categories.
     * @param int $id
     * @return int
     */
    private function visible(string $table, int $id): int {
        global $DB;
        return (int) $DB->get_field($table, 'visible', ['id' => $id], MUST_EXIST);
    }

    /**
     * Id of the child category with that name, or 0. Filtered by parent: MariaDB ignores accents.
     *
     * @param int $parent
     * @param string $name
     * @return int
     */
    private function child(int $parent, string $name): int {
        global $DB;
        return (int) $DB->get_field('course_categories', 'id', ['parent' => $parent, 'name' => $name]);
    }

    /**
     * Previews and applies with the hash of that preview.
     *
     * @param \core_course_category $category
     * @param array $groups [['key' => string, 'keep' => int]]; null keeps the default of every group.
     * @return array Result of deduperunner::run().
     */
    private function apply_dedupe(\core_course_category $category, ?array $groups = null): array {
        $plan = dedupeplan::build((int) $category->id);
        if ($groups === null) {
            $groups = [];
            foreach ($plan['groups'] as $group) {
                $keep = array_values(array_filter($group['courses'], static function (array $course): bool {
                    return $course['keep'];
                }));
                $groups[] = ['key' => $group['key'], 'keep' => $keep[0]['courseid']];
            }
        }
        return deduperunner::run((int) $category->id, $groups, $plan['hash']);
    }

    /**
     * Each category is compared only with itself, and every «Repetidos» folder is skipped with its subtree.
     */
    public function test_preview_per_category_and_skips_folders(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Facultad');
        $proga = $this->cat('Programa A', $root);
        $progb = $this->cat('Programa B', $root);
        $folder = $this->cat('REPETIDOS', $root);
        $inner = $this->cat('Sub', $folder);
        $nested = $this->cat('Repetidos', $proga);
        $a1 = $this->course('Cálculo I', $proga, 2000, 'CALCA');
        $a2 = $this->course('Cálculo I copia 1', $proga, 1000, 'CALCA_1');
        $this->course('Cálculo I', $progb);
        $this->course('Física', $folder);
        $this->course('Física', $folder);
        $this->course('Química', $inner);
        $this->course('Química', $inner);
        $this->course('Álgebra', $nested);
        $this->course('Álgebra', $nested);

        $plan = dedupeplan::build((int) $root->id);

        $this->assertCount(1, $plan['groups']);
        $group = $plan['groups'][0];
        $this->assertSame((int) $proga->id, $group['categoryid']);
        $this->assertSame('Facultad / Programa A', $group['path']);
        $this->assertSame(min($a1, $a2) . '-' . max($a1, $a2), $group['key']);
        $courses = array_column($group['courses'], null, 'courseid');
        $this->assertTrue($courses[$a1]['keep']);
        $this->assertFalse($courses[$a2]['keep']);
        $this->assertSame(['key', 'shortname'], $courses[$a2]['reasons']);
        $this->assertSame('Cálculo I copia 1', $courses[$a2]['fullname']);
        $this->assertSame('CALCA_1', $courses[$a2]['shortname']);
        $this->assertSame(1000, $courses[$a2]['timemodified']);
        $this->assertSame((int) $folder->id, $plan['folder']['id']);
        $this->assertSame('Facultad / REPETIDOS', $plan['folder']['path']);
        // It exists and is visible: the panel warns that moved courses would stay visible.
        $this->assertTrue($plan['folder']['visible']);
    }

    /**
     * Grouping is transitive across the three rules: by name and by short name, three courses make one group.
     */
    public function test_preview_transitive(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $a = $this->course('Cálculo I', $root, 1000, 'XAB1');
        $b = $this->course('Cálculo I (2025-2)', $root, 3000, 'ZZQ9');
        $c = $this->course('Cálculo I (nueva versión)', $root, 2000, 'ZZQ9_2');
        $this->course('Cálculo II', $root, 5000, 'XAB2');

        $plan = dedupeplan::build((int) $root->id);

        $this->assertCount(1, $plan['groups']);
        $ids = [$a, $b, $c];
        sort($ids);
        $this->assertSame(implode('-', $ids), $plan['groups'][0]['key']);
        $keep = array_column($plan['groups'][0]['courses'], 'keep', 'courseid');
        $this->assertTrue($keep[$b]);
        $this->assertSame(0, $plan['folder']['id']);
        $this->assertSame('Programa / Repetidos', $plan['folder']['path']);
        $this->assertFalse($plan['folder']['visible']);
    }

    /**
     * A visible course stays before a hidden one, even a newer one; the preview says which ones are hidden.
     */
    public function test_preview_visible_wins(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $visible = $this->course('Álgebra', $root, 1000);
        $hidden = $this->course('Álgebra', $root, 3000);
        $DB->set_field('course', 'visible', 0, ['id' => $hidden]);

        $plan = dedupeplan::build((int) $root->id);

        $courses = array_column($plan['groups'][0]['courses'], null, 'courseid');
        $this->assertTrue($courses[$visible]['keep']);
        $this->assertFalse($courses[$hidden]['keep']);
        $this->assertTrue($courses[$visible]['visible']);
        $this->assertFalse($courses[$hidden]['visible']);
    }

    /**
     * Sections of a course with different groups are never moved as repeated.
     */
    public function test_preview_keeps_groups_apart(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $this->course('Juegos de Inteligencia (2026-2) G1', $root);
        $this->course('Juegos de Inteligencia (2026-2) G2', $root);
        $this->course('Física Grupo A', $root);
        $this->course('Física Grupo B', $root);

        $this->assertSame([], dedupeplan::build((int) $root->id)['groups']);
    }

    /**
     * By default the newest stays; the others go flat to a «Repetidos» folder created hidden under the category,
     * and are hidden. Undo brings them back visible and deletes the folder it created.
     */
    public function test_run_newest_wins_and_undo(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Facultad');
        $prog = $this->cat('Programa', $root);
        $sem = $this->cat('Semestre 1', $prog);
        $old = $this->course('Cálculo I', $sem, 1000);
        $new = $this->course('Cálculo I - Grupo 1', $sem, 2000);
        $older = $this->course('Física', $prog, 1000);
        $newer = $this->course('FÍSICA', $prog, 3000);

        $result = $this->apply_dedupe($root);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['moved']);
        $folder = $this->child((int) $root->id, duplicates::FOLDER);
        $this->assertGreaterThan(0, $folder);
        $this->assertSame(0, $this->visible('course_categories', $folder));
        $this->assertSame($folder, $this->category_of($old));
        $this->assertSame($folder, $this->category_of($older));
        $this->assertSame((int) $sem->id, $this->category_of($new));
        $this->assertSame((int) $prog->id, $this->category_of($newer));
        $this->assertSame(0, $this->visible('course', $old));
        $this->assertSame(1, $this->visible('course', $new));
        $log = $DB->get_record(journal::TABLE, ['id' => $result['logid']], '*', MUST_EXIST);
        $this->assertSame('dedupe', $log->action);
        $this->assertSame(
            get_string('summary_dedupe', 'tool_ceaorganizador', (object) ['category' => 'Facultad', 'count' => 2]),
            $log->summary
        );
        $payload = json_decode($log->payload, true);
        $this->assertSame((int) $root->id, $payload['categoryid']);
        $this->assertSame([$folder], $payload['created']);

        // A second preview does not see the folder: nothing is repeated now.
        $this->assertSame([], dedupeplan::build((int) $root->id)['groups']);

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $sem->id, $this->category_of($old));
        $this->assertSame((int) $prog->id, $this->category_of($older));
        $this->assertSame(1, $this->visible('course', $old));
        $this->assertSame(1, $this->visible('course', $older));
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $folder]));
    }

    /**
     * The user's choice of the course that stays is honoured, and a group left out is not touched.
     */
    public function test_run_user_choice_and_excluded_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $old = $this->course('Cálculo I', $root, 1000);
        $new = $this->course('Cálculo I copia 1', $root, 2000);
        $f1 = $this->course('Física', $root, 1000);
        $f2 = $this->course('Física', $root, 2000);
        $plan = dedupeplan::build((int) $root->id);
        $this->assertCount(2, $plan['groups']);
        $calc = array_values(array_filter($plan['groups'], static function (array $group) use ($old): bool {
            return in_array($old, array_column($group['courses'], 'courseid'), true);
        }))[0];

        $result = deduperunner::run((int) $root->id, [['key' => $calc['key'], 'keep' => $old]], $plan['hash']);

        $this->assertSame(1, $result['moved']);
        $folder = $this->child((int) $root->id, duplicates::FOLDER);
        $this->assertSame((int) $root->id, $this->category_of($old));
        $this->assertSame($folder, $this->category_of($new));
        $this->assertSame((int) $root->id, $this->category_of($f1));
        $this->assertSame((int) $root->id, $this->category_of($f2));
    }

    /**
     * An existing «Repetidos» folder is reused, even visible, and undo keeps it: the batch did not create it.
     * A created folder that received a course since is kept by undo too.
     */
    public function test_folder_reused_and_kept(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $existing = $this->cat('Repetidos', $root);
        $old = $this->course('Cálculo I', $root, 1000);
        $this->course('Cálculo I', $root, 2000);
        $categories = $DB->count_records('course_categories');

        $result = $this->apply_dedupe($root);

        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame((int) $existing->id, $this->category_of($old));
        $this->assertSame([], json_decode($DB->get_field(journal::TABLE, 'payload', ['id' => $result['logid']]), true)['created']);
        $this->assertTrue(journal::undo((int) $result['logid'])['ok']);
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $existing->id]));

        // Now a batch that creates the folder, which then receives another course.
        $other = $this->cat('Otro');
        $a = $this->course('Álgebra', $other, 1000);
        $this->course('Álgebra', $other, 2000);
        $result = $this->apply_dedupe($other);
        $folder = $this->child((int) $other->id, duplicates::FOLDER);
        $this->course('Suelto', \core_course_category::get($folder, MUST_EXIST, true));

        $this->assertTrue(journal::undo((int) $result['logid'])['ok']);
        $this->assertSame((int) $other->id, $this->category_of($a));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $folder]));
    }

    /**
     * A preview that no longer matches returns 'stale' and writes nothing.
     */
    public function test_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $a = $this->course('Cálculo I', $root, 1000);
        $b = $this->course('Cálculo I', $root, 2000);
        $plan = dedupeplan::build((int) $root->id);
        // A new repeated course changes the groups.
        $this->course('Cálculo I copia 2', $root);

        $result = deduperunner::run((int) $root->id, [['key' => $plan['groups'][0]['key'], 'keep' => $b]], $plan['hash']);

        $this->assertSame(['status' => 'stale', 'logid' => 0, 'moved' => 0], $result);
        $this->assertSame((int) $root->id, $this->category_of($a));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
        $this->assertSame(0, $this->child((int) $root->id, duplicates::FOLDER));
    }

    /**
     * An unknown group, a repeated group or a course outside its group is rejected before any write.
     */
    public function test_rejects_invalid_groups(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $a = $this->course('Cálculo I', $root);
        $this->course('Cálculo I', $root);
        $outside = $this->course('Física', $root);
        $plan = dedupeplan::build((int) $root->id);
        $key = $plan['groups'][0]['key'];

        $cases = [
            [['key' => '0-0', 'keep' => $a]],
            [['key' => $key, 'keep' => $outside]],
            [['key' => $key, 'keep' => $a], ['key' => $key, 'keep' => $a]],
        ];
        foreach ($cases as $groups) {
            try {
                deduperunner::run((int) $root->id, $groups, $plan['hash']);
                $this->fail('Invalid groups must be rejected');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame(0, $DB->count_records(journal::TABLE));
            }
        }
        $this->assertSame((int) $root->id, $this->category_of($a));
    }

    /**
     * More courses than the limit are refused, with the limit in the message, before any write.
     */
    public function test_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $this->course('Cálculo I', $root);
        $this->course('Cálculo I', $root);
        $this->course('Cálculo I', $root);
        $runner = new class extends deduperunner {
            /** @var int A small limit for the test. */
            public const MAX_MOVES = 1;
        };
        $plan = dedupeplan::build((int) $root->id);
        $group = $plan['groups'][0];

        try {
            $runner::run((int) $root->id, [['key' => $group['key'], 'keep' => $group['courses'][0]['courseid']]], $plan['hash']);
            $this->fail('The limit must be enforced');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_toomanydedupe', $e->errorcode);
            $this->assertSame(get_string('error_toomanydedupe', 'tool_ceaorganizador', 1), $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * A tree with more courses than the preview examines is refused, with the limit in the message; the courses of
     * a «Repetidos» folder do not count.
     */
    public function test_preview_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame(5000, dedupeplan::MAX_COURSES);
        $root = $this->cat('Programa');
        $sub = $this->cat('Semestre 1', $root);
        $folder = $this->cat('Repetidos', $root);
        $this->course('Cálculo I', $root);
        $this->course('Física', $sub);
        $this->course('Química', $folder);
        $this->course('Química', $folder);
        $plan = new class extends dedupeplan {
            /** @var int A small limit for the test. */
            public const MAX_COURSES = 2;
        };

        // Two courses to examine: within the limit.
        $this->assertSame([], $plan::build((int) $root->id)['groups']);

        $this->course('Álgebra', $sub);
        try {
            $plan::build((int) $root->id);
            $this->fail('The limit must be enforced');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_toomanydedupecourses', $e->errorcode);
            $this->assertSame(get_string('error_toomanydedupecourses', 'tool_ceaorganizador', 2), $e->getMessage());
        }
        // A smaller category is fine.
        $this->assertSame([], $plan::build((int) $sub->id)['groups']);
    }

    /**
     * Without moodle/course:changecategory, or without managing the category, nothing is created, moved or
     * recorded.
     */
    public function test_requires_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $root = $this->cat('Programa');
        $a = $this->course('Cálculo I', $root, 1000);
        $b = $this->course('Cálculo I', $root, 2000);
        $plan = dedupeplan::build((int) $root->id);
        $groups = [['key' => $plan['groups'][0]['key'], 'keep' => $b]];
        $categories = $DB->count_records('course_categories');
        $system = \context_system::instance();

        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('moodle/category:manage', CAP_ALLOW, $role, $system->id);
        role_assign($role, $user->id, $system->id);
        $this->setUser($user);
        try {
            deduperunner::run((int) $root->id, $groups, $plan['hash']);
            $this->fail('moodle/course:changecategory must be required');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:changecategory'), $e->a);
        }

        $other = $this->getDataGenerator()->create_user();
        $partial = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:changecategory', CAP_ALLOW, $partial, $system->id);
        role_assign($partial, $other->id, $system->id);
        $this->setUser($other);
        try {
            deduperunner::run((int) $root->id, $groups, $plan['hash']);
            $this->fail('moodle/category:manage must be required');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/category:manage'), $e->a);
        }

        $this->assertSame((int) $root->id, $this->category_of($a));
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }
}
