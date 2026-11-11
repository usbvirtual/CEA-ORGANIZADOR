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
#[\PHPUnit\Framework\Attributes\CoversClass(deleterunner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(journal::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mover::class)]
/**
 * Tests for deleting empty categories and undoing it.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\deleterunner
 * @covers     \tool_ceaorganizador\local\journal
 * @covers     \tool_ceaorganizador\local\mover
 */
final class deleterunner_test extends \advanced_testcase {
    /**
     * Creates a category.
     *
     * @param string $name
     * @param int $parent 0 for the top level.
     * @param array $extra Other fields for the generator (idnumber, description, visible…).
     * @return int
     */
    private function cat(string $name, int $parent = 0, array $extra = []): int {
        return (int) $this->getDataGenerator()->create_category(['name' => $name, 'parent' => $parent] + $extra)->id;
    }

    /**
     * Whether a category exists.
     *
     * @param int $id
     * @return bool
     */
    private function exists(int $id): bool {
        global $DB;
        return $DB->record_exists('course_categories', ['id' => $id]);
    }

    /**
     * The category with that name under a parent, filtered by parent (MariaDB compares names without accents).
     *
     * @param int $parent
     * @param string $name
     * @return \stdClass|false
     */
    private function child_record(int $parent, string $name) {
        global $DB;
        return $DB->get_record('course_categories', ['parent' => $parent, 'name' => $name]);
    }

    /**
     * Payload of a journal row.
     *
     * @param int $logid
     * @return array
     */
    private function payload(int $logid): array {
        global $DB;
        return json_decode($DB->get_field(journal::TABLE, 'payload', ['id' => $logid], MUST_EXIST), true);
    }

    /**
     * Inserts a question category in the context of a category.
     *
     * @param int $categoryid
     * @return int
     */
    private function question_category(int $categoryid): int {
        global $DB;
        return (int) $DB->insert_record('question_categories', (object) [
            'name' => 'top',
            'contextid' => \context_coursecat::instance($categoryid)->id,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => 0,
            'sortorder' => 0,
        ]);
    }

    /**
     * Grants moodle/category:manage to a user in one context only.
     *
     * @param int $userid
     * @param \context $context
     */
    private function grant_manage(int $userid, \context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/category:manage', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $userid, $context->id);
    }

    /**
     * An empty category is deleted and recorded with what the undo needs.
     */
    public function test_deletes_empty_category(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $first = $this->cat('Primera', $parent);
        $empty = $this->cat('Vacía', $parent, [
            'idnumber' => 'VAC-1',
            'description' => 'Texto',
            'descriptionformat' => FORMAT_PLAIN,
        ]);

        $result = deleterunner::run([$empty]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame([], $result['skipped']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertFalse($this->exists($empty));
        $this->assertTrue($this->exists($parent));

        $log = $DB->get_record(journal::TABLE, ['id' => $result['logid']], '*', MUST_EXIST);
        $this->assertSame('delete', $log->action);
        $this->assertSame(get_string('summary_delete', 'tool_ceaorganizador', 'Vacía'), $log->summary);
        $items = $this->payload((int) $result['logid'])['items'];
        $this->assertCount(1, $items);
        $this->assertSame($empty, $items[0]['id']);
        $this->assertSame('Vacía', $items[0]['name']);
        $this->assertSame('VAC-1', $items[0]['idnumber']);
        $this->assertSame('Texto', $items[0]['description']);
        $this->assertSame((int) FORMAT_PLAIN, $items[0]['descriptionformat']);
        $this->assertSame($parent, $items[0]['parent']);
        $this->assertSame($first, $items[0]['afterid']);
        $this->assertSame(1, $items[0]['visible']);
        $this->assertArrayNotHasKey('theme', $items[0]);
    }

    /**
     * A category with a course, a subcategory or a question category is skipped with its reason.
     */
    public function test_skips_nonempty_categories(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $withcourse = $this->cat('Con curso');
        $this->getDataGenerator()->create_course(['category' => $withcourse]);
        $withchild = $this->cat('Con hija');
        $child = $this->cat('Hija', $withchild);
        $withquestions = $this->cat('Con preguntas');
        $this->question_category($withquestions);
        $missing = $withquestions + 1000;

        $result = deleterunner::run([$withcourse, $withchild, $withquestions, $missing]);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(0, $result['logid']);
        $reasons = array_column($result['skipped'], 'reason', 'id');
        $this->assertSame('hascourses', $reasons[$withcourse]);
        $this->assertSame('haschildren', $reasons[$withchild]);
        $this->assertSame('hascontent', $reasons[$withquestions]);
        $this->assertSame('notfound', $reasons[$missing]);
        $names = array_column($result['skipped'], 'name', 'id');
        $this->assertSame('Con hija', $names[$withchild]);
        $this->assertSame('', $names[$missing]);
        foreach ([$withcourse, $withchild, $child, $withquestions] as $id) {
            $this->assertTrue($this->exists($id));
        }
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * A parent whose only subcategory is selected and empty is deleted too, after it; alone it is kept.
     */
    public function test_parent_and_empty_child_in_same_batch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $child = $this->cat('Hija', $parent);

        $plan = deleterunner::plan([$parent]);
        $this->assertSame([], $plan['delete']);
        $this->assertSame('haschildren', $plan['skipped'][0]['reason']);
        $this->assertSame('Padre', $plan['skipped'][0]['path']);

        $plan = deleterunner::plan([$parent, $child]);
        $this->assertSame([$child, $parent], array_column($plan['delete'], 'id'));
        $this->assertSame('Padre / Hija', $plan['delete'][0]['path']);
        $this->assertSame([], $plan['skipped']);

        $result = deleterunner::run([$parent, $child]);

        $this->assertSame(2, $result['deleted']);
        $this->assertFalse($this->exists($parent));
        $this->assertFalse($this->exists($child));
        $this->assertSame([$child, $parent], array_column($this->payload((int) $result['logid'])['items'], 'id'));
    }

    /**
     * moodle/category:manage is needed on the category and on its parent, as core_course_category::can_delete().
     */
    public function test_requires_manage_on_category_and_parent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $child = $this->cat('Hija', $parent);
        $top = $this->cat('Arriba');

        // Manages the subcategory only: not its parent, so it cannot delete it.
        $user = $this->getDataGenerator()->create_user();
        $this->grant_manage((int) $user->id, \context_coursecat::instance($child));
        $this->setUser($user);
        $result = deleterunner::run([$child]);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame([['id' => $child, 'name' => 'Hija', 'reason' => 'nopermission']], $result['skipped']);
        $this->assertTrue($this->exists($child));

        // Manages the parent: the subcategory can go, but a top-level category needs the system context.
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_user();
        $this->grant_manage((int) $other->id, \context_coursecat::instance($parent));
        $this->grant_manage((int) $other->id, \context_coursecat::instance($top));
        $this->setUser($other);
        $result = deleterunner::run([$child, $top]);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame([['id' => $top, 'name' => 'Arriba', 'reason' => 'nopermission']], $result['skipped']);
        $this->assertFalse($this->exists($child));
        $this->assertTrue($this->exists($top));
    }

    /**
     * A failure inside the transaction leaves every category and no journal row.
     */
    public function test_failure_rolls_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $child = $this->cat('Hija', $parent);
        // Fails at the last step of the transaction, after both categories are deleted.
        $runner = new class extends deleterunner {
            /**
             * Fails instead of recording.
             *
             * @param array $payload
             * @return int
             */
            protected static function record_delete(array $payload): int {
                throw new \coding_exception('Simulated failure');
            }
        };

        try {
            $runner::run([$parent, $child]);
            $this->fail('The failure must reach the caller');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Simulated failure', $e->getMessage());
        }

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
        $this->assertSame((int) $parent, (int) $DB->get_field('course_categories', 'parent', ['id' => $child]));
        $this->assertTrue($DB->record_exists('context', ['contextlevel' => CONTEXT_COURSECAT, 'instanceid' => $child]));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Undo creates the categories again with new ids: fields, visibility, place and the child under the new parent.
     */
    public function test_undo_recreates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $faculty = $this->cat('Facultad');
        $first = $this->cat('Primera', $faculty);
        $hidden = $this->cat('Equis', $faculty, [
            'idnumber' => 'EQ-1',
            'description' => '<p>Descripción</p>',
            'descriptionformat' => FORMAT_HTML,
            'visible' => 0,
        ]);
        $last = $this->cat('Última', $faculty);
        // Hidden only because its parent is hidden.
        $child = $this->cat('Ye', $hidden);
        $result = deleterunner::run([$hidden, $child]);
        $this->assertSame(2, $result['deleted']);

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame([], $undo['conflicts']);
        $new = $this->child_record($faculty, 'Equis');
        $this->assertNotEmpty($new);
        $this->assertNotSame($hidden, (int) $new->id);
        $this->assertSame('EQ-1', $new->idnumber);
        $this->assertSame('<p>Descripción</p>', $new->description);
        $this->assertSame((int) FORMAT_HTML, (int) $new->descriptionformat);
        $this->assertSame(0, (int) $new->visible);
        $this->assertSame([$first, (int) $new->id, $last], tree::sibling_categories($faculty));

        $newchild = $this->child_record((int) $new->id, 'Ye');
        $this->assertNotEmpty($newchild);
        $this->assertNotSame($child, (int) $newchild->id);
        $this->assertSame(0, (int) $newchild->visible);
        $this->assertSame(1, (int) $newchild->visibleold);
        $this->assertGreaterThan(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * Siblings deleted together come back in their old order.
     */
    public function test_undo_restores_order_of_siblings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $one = $this->cat('Uno', $parent);
        $two = $this->cat('Dos', $parent);
        $three = $this->cat('Tres', $parent);
        $this->assertSame([$one, $two, $three], tree::sibling_categories($parent));
        $result = deleterunner::run([$two, $one]);
        $this->assertSame(2, $result['deleted']);

        $this->assertTrue(journal::undo((int) $result['logid'])['ok']);

        $newone = (int) $this->child_record($parent, 'Uno')->id;
        $newtwo = (int) $this->child_record($parent, 'Dos')->id;
        $this->assertSame([$newone, $newtwo, $three], tree::sibling_categories($parent));
    }

    /**
     * Undo is refused, and creates nothing, when the old parent was deleted since.
     */
    public function test_undo_conflict_parent_missing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $child = $this->cat('Hija', $parent);
        $result = deleterunner::run([$child]);
        \core_course_category::get($parent, MUST_EXIST, true)->delete_full(false);
        $count = $DB->count_records('course_categories');

        $undo = journal::undo((int) $result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertSame([get_string('conflict_parentmissing', 'tool_ceaorganizador', 'Hija')], $undo['conflicts']);
        $this->assertSame($count, $DB->count_records('course_categories'));
        $this->assertSame(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * Undo is refused, and creates nothing, when another category took the old idnumber.
     */
    public function test_undo_conflict_idnumber_taken(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->cat('Padre');
        $child = $this->cat('Hija', $parent, ['idnumber' => 'HIJA-1']);
        $result = deleterunner::run([$child]);
        $this->cat('Otra', $parent, ['idnumber' => 'HIJA-1']);
        $count = $DB->count_records('course_categories');

        $undo = journal::undo((int) $result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertSame([get_string('conflict_idnumbertaken', 'tool_ceaorganizador', 'Hija')], $undo['conflicts']);
        $this->assertSame($count, $DB->count_records('course_categories'));
        $this->assertSame(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * More than the limit is rejected before anything is read or written.
     */
    public function test_rejects_too_many(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/500/');
        deleterunner::run(range(1, deleterunner::MAX_ITEMS + 1));
    }
}
