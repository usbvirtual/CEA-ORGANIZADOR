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
#[\PHPUnit\Framework\Attributes\CoversClass(journal::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_ceaorganizador\task\cleanup_log::class)]
/**
 * Tests for the journal, against the test database.
 *
 * New categories are appended last among their siblings; new courses go first in their category.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\journal
 * @covers     \tool_ceaorganizador\task\cleanup_log
 */
final class journal_test extends \advanced_testcase {
    /**
     * Id of the default top-level category created by the installer.
     *
     * @return int
     */
    private function misc(): int {
        global $DB;
        return (int) $DB->get_field_select('course_categories', 'MIN(id)', 'parent = 0');
    }

    /**
     * Undoing a category move puts it back under its parent, at its position.
     */
    public function test_undo_move_category_restores_parent_and_position(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B'])->id;
        $c = (int) $generator->create_category(['name' => 'C'])->id;
        $misc = $this->misc();

        $result = journal::move_category($b, $a, 0);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame(['parent' => 0, 'afterid' => $a], $result['from']);
        $this->assertSame(['parent' => $a, 'afterid' => 0], $result['to']);
        $this->assertSame([$misc, $a, $c], tree::sibling_categories(0));

        $undo = journal::undo($result['logid']);

        $this->assertSame(['ok' => true, 'conflicts' => []], $undo);
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'parent', ['id' => $b]));
        $this->assertSame([$misc, $a, $b, $c], tree::sibling_categories(0));
        $this->assertSame([], tree::sibling_categories($a));
        $log = $DB->get_record('tool_ceaorganizador_log', ['id' => $result['logid']]);
        $this->assertSame((int) $USER->id, (int) $log->undoneby);
        $this->assertGreaterThan(0, (int) $log->timeundone);
    }

    /**
     * When the item changed after the operation, nothing is written and the conflict names it.
     */
    public function test_undo_rejects_when_changed_after(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'Categoria Alfa'])->id;
        $b = (int) $generator->create_category(['name' => 'B'])->id;
        $c = (int) $generator->create_category(['name' => 'C'])->id;

        $first = journal::move_category($a, $b, 0);
        // Someone moves it again by hand.
        mover::move_category($a, $c, 0);

        $undo = journal::undo($first['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertNotEmpty($undo['conflicts']);
        $this->assertStringContainsString('Categoria Alfa', implode(' ', $undo['conflicts']));
        $this->assertSame($c, (int) $DB->get_field('course_categories', 'parent', ['id' => $a]));
        $this->assertSame([$a], tree::sibling_categories($c));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $first['logid']]));
    }

    /**
     * Undoing a course move whose original neighbour was moved away is a conflict.
     */
    public function test_undo_move_course_rejects_when_original_position_is_gone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $x = (int) $generator->create_category(['name' => 'X'])->id;
        $y = (int) $generator->create_category(['name' => 'Y'])->id;
        $k1 = (int) $generator->create_course(['category' => $x, 'fullname' => 'Curso uno'])->id;
        $k2 = (int) $generator->create_course(['category' => $x, 'fullname' => 'Curso dos'])->id;
        // X holds [k2, k1]: k1 sits after k2.
        $result = journal::move_course($k1, $y, 0);
        $this->assertSame(['category' => $x, 'afterid' => $k2], $result['from']);

        // The neighbour it should return after leaves the category.
        mover::move_course($k2, $y, $k1);

        $undo = journal::undo($result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertStringContainsString('Curso uno', implode(' ', $undo['conflicts']));
        $this->assertSame($y, (int) $DB->get_field('course', 'category', ['id' => $k1]));
        $this->assertSame([$k1, $k2], tree::category_courses($y));
    }

    /**
     * Undoing a sort applies the 'before' orders.
     */
    public function test_undo_sort_restores_before_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $p = (int) $generator->create_category(['name' => 'P'])->id;
        $c1 = (int) $generator->create_category(['name' => 'C1', 'parent' => $p])->id;
        $c2 = (int) $generator->create_category(['name' => 'C2', 'parent' => $p])->id;
        $c3 = (int) $generator->create_category(['name' => 'C3', 'parent' => $p])->id;
        $k1 = (int) $generator->create_course(['category' => $p])->id;
        $k2 = (int) $generator->create_course(['category' => $p])->id;
        $catbefore = [$c1, $c2, $c3];
        $catafter = [$c3, $c1, $c2];
        $coursebefore = [$k2, $k1];
        $courseafter = [$k1, $k2];
        $this->assertSame($catbefore, tree::sibling_categories($p));
        $this->assertSame($coursebefore, tree::category_courses($p));

        mover::apply_category_order($p, $catafter);
        mover::apply_course_order($p, $courseafter);
        $logid = journal::record('sort', 'Ordenar P', [
            'categories' => [['parentid' => $p, 'before' => $catbefore, 'after' => $catafter]],
            'courses' => [['categoryid' => $p, 'before' => $coursebefore, 'after' => $courseafter]],
        ]);

        $undo = journal::undo($logid);

        $this->assertTrue($undo['ok']);
        $this->assertSame($catbefore, tree::sibling_categories($p));
        $this->assertSame($coursebefore, tree::category_courses($p));
    }

    /**
     * Undoing a rename restores the category name and the course names.
     */
    public function test_undo_rename_restores_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $cat = (int) $generator->create_category(['name' => 'Viejo nombre'])->id;
        $course = (int) $generator->create_course(['fullname' => 'Curso viejo', 'shortname' => 'CV'])->id;

        mover::rename_category($cat, 'Nuevo nombre');
        mover::rename_course($course, 'Curso nuevo', 'CN');
        $logid = journal::record('rename', 'Renombrar', [
            'items' => [
                [
                    'type' => 'category',
                    'id' => $cat,
                    'before' => ['name' => 'Viejo nombre'],
                    'after' => ['name' => 'Nuevo nombre'],
                ],
                [
                    'type' => 'course',
                    'id' => $course,
                    'before' => ['fullname' => 'Curso viejo', 'shortname' => 'CV'],
                    'after' => ['fullname' => 'Curso nuevo', 'shortname' => 'CN'],
                ],
            ],
        ]);

        $undo = journal::undo($logid);

        $this->assertTrue($undo['ok']);
        $this->assertSame('Viejo nombre', $DB->get_field('course_categories', 'name', ['id' => $cat]));
        $record = $DB->get_record('course', ['id' => $course]);
        $this->assertSame('Curso viejo', $record->fullname);
        $this->assertSame('CV', $record->shortname);
    }

    /**
     * A name changed after the rename blocks the whole undo, including the unchanged items.
     */
    public function test_undo_rename_rejects_when_renamed_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $one = (int) $generator->create_category(['name' => 'Uno'])->id;
        $two = (int) $generator->create_category(['name' => 'Dos'])->id;
        mover::rename_category($one, 'Uno nuevo');
        mover::rename_category($two, 'Dos nuevo');
        $logid = journal::record('rename', 'Renombrar', [
            'items' => [
                ['type' => 'category', 'id' => $one, 'before' => ['name' => 'Uno'], 'after' => ['name' => 'Uno nuevo']],
                ['type' => 'category', 'id' => $two, 'before' => ['name' => 'Dos'], 'after' => ['name' => 'Dos nuevo']],
            ],
        ]);
        mover::rename_category($two, 'Dos a mano');

        $undo = journal::undo($logid);

        $this->assertFalse($undo['ok']);
        $this->assertCount(1, $undo['conflicts']);
        $this->assertStringContainsString('Dos a mano', $undo['conflicts'][0]);
        $this->assertSame('Uno nuevo', $DB->get_field('course_categories', 'name', ['id' => $one]));
        $this->assertSame('Dos a mano', $DB->get_field('course_categories', 'name', ['id' => $two]));
    }

    /**
     * An operation is undone only once, and an unknown row is an error.
     */
    public function test_undo_twice_throws(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $a = (int) $generator->create_category(['name' => 'A'])->id;
        $b = (int) $generator->create_category(['name' => 'B'])->id;
        $result = journal::move_category($a, $b, 0);
        $this->assertTrue(journal::undo($result['logid'])['ok']);

        try {
            journal::undo($result['logid']);
            $this->fail('Expected error_alreadyundone');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_alreadyundone', $e->errorcode);
            $this->assertSame('tool_ceaorganizador', $e->module);
        }

        $this->expectException(\dml_missing_record_exception::class);
        journal::undo($result['logid'] + 1000);
    }

    /**
     * Moving into a hidden parent hides the item; undoing shows it again (Ruling 7).
     */
    public function test_undo_restores_visibility_after_move_into_hidden_parent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $hidden = (int) $generator->create_category(['name' => 'Oculta'])->id;
        \core_course_category::get($hidden, MUST_EXIST, true)->hide();
        $origin = (int) $generator->create_category(['name' => 'Origen'])->id;
        $x = (int) $generator->create_category(['name' => 'X'])->id;
        $course = (int) $generator->create_course(['category' => $origin])->id;

        // Category.
        $result = journal::move_category($x, $hidden, 0);
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'visible', ['id' => $x]));
        $payload = json_decode($DB->get_field('tool_ceaorganizador_log', 'payload', ['id' => $result['logid']]), true);
        $this->assertSame(1, $payload['from']['visible']);
        $this->assertSame(0, $payload['to']['visible']);

        $this->assertTrue(journal::undo($result['logid'])['ok']);
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'parent', ['id' => $x]));
        $this->assertSame(1, (int) $DB->get_field('course_categories', 'visible', ['id' => $x]));

        // Course.
        $result = journal::move_course($course, $hidden, 0);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $course]));

        $this->assertTrue(journal::undo($result['logid'])['ok']);
        $this->assertSame($origin, (int) $DB->get_field('course', 'category', ['id' => $course]));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $course]));
    }

    /**
     * An item hidden on purpose after a move between visible parents is a conflict, not shown again.
     */
    public function test_undo_rejects_when_visibility_changed_after(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $p = (int) $generator->create_category(['name' => 'P'])->id;
        $q = (int) $generator->create_category(['name' => 'Q'])->id;
        $x = (int) $generator->create_category(['name' => 'Categoria Equis', 'parent' => $p])->id;
        $result = journal::move_category($x, $q, 0);
        // An admin hides it on purpose.
        \core_course_category::get($x, MUST_EXIST, true)->hide();

        $undo = journal::undo($result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertStringContainsString('Categoria Equis', implode(' ', $undo['conflicts']));
        $this->assertSame($q, (int) $DB->get_field('course_categories', 'parent', ['id' => $x]));
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'visible', ['id' => $x]));
    }

    /**
     * When the old parent was hidden after the move, the undo leaves the item hidden inside it.
     */
    public function test_undo_into_parent_hidden_after_keeps_item_hidden(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $p = (int) $generator->create_category(['name' => 'P'])->id;
        $q = (int) $generator->create_category(['name' => 'Q'])->id;
        $x = (int) $generator->create_category(['name' => 'X', 'parent' => $p])->id;
        $result = journal::move_category($x, $q, 0);
        \core_course_category::get($p, MUST_EXIST, true)->hide();

        $undo = journal::undo($result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame($p, (int) $DB->get_field('course_categories', 'parent', ['id' => $x]));
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'visible', ['id' => $x]));
        $this->assertSame(0, (int) $DB->get_field('course_categories', 'visible', ['id' => $p]));
    }

    /**
     * A chain of short names (Y from B to C, then X from A to B) undoes cleanly in reverse order.
     */
    public function test_undo_rename_chain_of_shortnames(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $x = (int) $generator->create_course(['fullname' => 'Curso X', 'shortname' => 'A'])->id;
        $y = (int) $generator->create_course(['fullname' => 'Curso Y', 'shortname' => 'B'])->id;
        mover::rename_course($y, 'Curso Y', 'C');
        mover::rename_course($x, 'Curso X', 'B');
        $logid = journal::record('rename', 'Renombrar', [
            'items' => [
                [
                    'type' => 'course',
                    'id' => $y,
                    'before' => ['fullname' => 'Curso Y', 'shortname' => 'B'],
                    'after' => ['fullname' => 'Curso Y', 'shortname' => 'C'],
                ],
                [
                    'type' => 'course',
                    'id' => $x,
                    'before' => ['fullname' => 'Curso X', 'shortname' => 'A'],
                    'after' => ['fullname' => 'Curso X', 'shortname' => 'B'],
                ],
            ],
        ]);

        $undo = journal::undo($logid);

        $this->assertSame(['ok' => true, 'conflicts' => []], $undo);
        $this->assertSame('A', $DB->get_field('course', 'shortname', ['id' => $x]));
        $this->assertSame('B', $DB->get_field('course', 'shortname', ['id' => $y]));
    }

    /**
     * An error in the middle of the restore rolls back the writes already made.
     */
    public function test_undo_failure_rolls_back_earlier_writes(): void {
        global $DB;
        $this->resetAfterTest();
        // On PostgreSQL the test runs inside a transaction; opt out so undo's rollback is real.
        $this->preventResetByRollback();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $p = (int) $generator->create_category(['name' => 'P'])->id;
        $c1 = (int) $generator->create_category(['name' => 'C1', 'parent' => $p])->id;
        $c2 = (int) $generator->create_category(['name' => 'C2', 'parent' => $p])->id;
        $k1 = (int) $generator->create_course(['category' => $p])->id;
        $k2 = (int) $generator->create_course(['category' => $p])->id;
        $k3 = (int) $generator->create_course(['category' => $p])->id;
        $q = (int) $generator->create_category(['name' => 'Q'])->id;
        $q1 = (int) $generator->create_course(['category' => $q])->id;
        $q2 = (int) $generator->create_course(['category' => $q])->id;
        mover::apply_category_order($p, [$c2, $c1]);
        mover::apply_course_order($p, [$k1, $k2, $k3]);
        mover::apply_course_order($q, [$q1, $q2]);
        $logid = journal::record('sort', 'Ordenar P', [
            'categories' => [['parentid' => $p, 'before' => [$c1, $c2], 'after' => [$c2, $c1]]],
            'courses' => [
                ['categoryid' => $q, 'before' => [$q2, $q1], 'after' => [$q1, $q2]],
                ['categoryid' => $p, 'before' => [$k3, $k2, $k1], 'after' => [$k1, $k2, $k3]],
            ],
        ]);
        // The course lists are restored first: Q is written, then P fails (3 courses, limit 2).
        set_config('maxcoursesincategory', 2);

        try {
            journal::undo($logid);
            $this->fail('Expected error_toomanycourses');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_toomanycourses', $e->errorcode);
        }

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame([$c2, $c1], tree::sibling_categories($p));
        $this->assertSame([$k1, $k2, $k3], tree::category_courses($p));
        $this->assertSame([$q1, $q2], tree::category_courses($q));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $logid]));
    }

    /**
     * The history lists newest first, with the user name and the undone flag.
     */
    public function test_history_lists_newest_first(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = journal::record('sort', 'Primera', ['categories' => [], 'courses' => []]);
        $second = journal::record('sort', 'Segunda', ['categories' => [], 'courses' => []]);
        $third = journal::record('sort', 'Tercera', ['categories' => [], 'courses' => []]);
        $DB->set_field('tool_ceaorganizador_log', 'timecreated', time() - 100, ['id' => $first]);
        $DB->set_field('tool_ceaorganizador_log', 'timeundone', time(), ['id' => $second]);

        $page = journal::history(0, 2);

        $this->assertSame(3, $page['total']);
        $this->assertSame([$third, $second], array_column($page['rows'], 'id'));
        $this->assertSame(fullname($USER), $page['rows'][0]['userfullname']);
        $this->assertFalse($page['rows'][0]['undone']);
        $this->assertTrue($page['rows'][1]['undone']);
        $this->assertSame([$first], array_column(journal::history(1, 2)['rows'], 'id'));
    }

    /**
     * The scheduled task deletes the rows older than 90 days.
     */
    public function test_cleanup_deletes_older_than_90_days(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $old = journal::record('sort', 'Vieja', ['categories' => [], 'courses' => []]);
        $recent = journal::record('sort', 'Reciente', ['categories' => [], 'courses' => []]);
        $DB->set_field('tool_ceaorganizador_log', 'timecreated', time() - 91 * DAYSECS, ['id' => $old]);
        $DB->set_field('tool_ceaorganizador_log', 'timecreated', time() - 89 * DAYSECS, ['id' => $recent]);

        $task = new \tool_ceaorganizador\task\cleanup_log();
        $this->assertNotEmpty($task->get_name());
        $task->execute();

        $this->assertFalse($DB->record_exists('tool_ceaorganizador_log', ['id' => $old]));
        $this->assertTrue($DB->record_exists('tool_ceaorganizador_log', ['id' => $recent]));
    }
}
