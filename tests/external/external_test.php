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

namespace tool_ceaorganizador\external;

use core_external\external_api;
use tool_ceaorganizador\local\tree;

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(get_tree::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(move_category::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(move_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sort_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sort_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rename_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rename_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(undo::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(history::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(set_visibility::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_ceaorganizador\local\sortplan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_ceaorganizador\local\guard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_ceaorganizador\local\mergerunner::class)]
/**
 * Tests for the web services, against the test database.
 *
 * New categories are appended last among their siblings; new courses go first in their category.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\get_tree
 * @covers     \tool_ceaorganizador\external\get_courses
 * @covers     \tool_ceaorganizador\external\move_category
 * @covers     \tool_ceaorganizador\external\move_course
 * @covers     \tool_ceaorganizador\external\sort_preview
 * @covers     \tool_ceaorganizador\external\sort_apply
 * @covers     \tool_ceaorganizador\external\rename_preview
 * @covers     \tool_ceaorganizador\external\rename_apply
 * @covers     \tool_ceaorganizador\external\undo
 * @covers     \tool_ceaorganizador\external\history
 * @covers     \tool_ceaorganizador\external\set_visibility
 * @covers     \tool_ceaorganizador\local\sortplan
 * @covers     \tool_ceaorganizador\local\guard
 * @covers     \tool_ceaorganizador\local\mergerunner
 */
final class external_test extends \advanced_testcase {
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
     * A category under a parent.
     *
     * @param string $name
     * @param int $parent
     * @return int
     */
    private function category(string $name, int $parent = 0): int {
        return (int) $this->getDataGenerator()->create_category(['name' => $name, 'parent' => $parent])->id;
    }

    /**
     * A course in a category.
     *
     * @param string $fullname
     * @param string $shortname
     * @param int $category
     * @return int
     */
    private function course(string $fullname, string $shortname, int $category): int {
        return (int) $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category,
        ])->id;
    }

    /**
     * A user with the plugin capability and the given capabilities, all in the system context.
     *
     * @param string[] $caps
     * @return \stdClass
     */
    private function user_with(array $caps): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $system = \context_system::instance();
        foreach (array_merge(['tool/ceaorganizador:manage'], $caps) as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
        }
        role_assign($roleid, $user->id, $system->id);
        return $user;
    }

    /**
     * Grants a capability to a user in one context only.
     *
     * @param int $userid
     * @param string $cap
     * @param \context $context
     */
    private function grant(int $userid, string $cap, \context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($cap, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $userid, $context->id);
    }

    /**
     * Asserts that a call is refused for a missing capability.
     *
     * @param callable $call
     */
    private function assert_refused(callable $call): void {
        try {
            $call();
        } catch (\required_capability_exception $e) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('A required_capability_exception was expected');
    }

    /**
     * A user without the plugin capability is refused.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        get_tree::execute();
    }

    /**
     * Moving a category returns the journal id, and undoing it restores the category.
     */
    public function test_move_category_records_journal(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->category('A');
        $b = $this->category('B');
        $misc = $this->misc();

        $result = move_category::execute($b, $a, 0);
        $result = external_api::clean_returnvalue(move_category::execute_returns(), $result);

        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame(['parent' => 0, 'afterid' => $a], $result['from']);
        $this->assertSame(['parent' => $a, 'afterid' => 0], $result['to']);
        $this->assertSame($a, (int) $DB->get_field('course_categories', 'parent', ['id' => $b]));

        $undo = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($result['logid']));

        $this->assertSame(['ok' => true, 'conflicts' => []], $undo);
        $this->assertSame([$misc, $a, $b], tree::sibling_categories(0));
    }

    /**
     * When a name changed after the preview, the apply reports 'stale' and writes nothing.
     */
    public function test_sort_apply_rejects_stale_hash(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('P');
        $c = $this->category('C', $parent);
        $a = $this->category('A', $parent);
        $b = $this->category('B', $parent);

        $preview = sort_preview::execute($parent, 'categories', false);
        $this->assertCount(1, $preview['changes']);
        $this->assertSame([$c, $a, $b], array_column($preview['changes'][0]['before'], 'id'));
        $this->assertSame([$a, $b, $c], array_column($preview['changes'][0]['after'], 'id'));

        // Someone renames C: the proposed order becomes A, AA, B.
        \core_course_category::get($c, MUST_EXIST, true)->update(['name' => 'AA']);

        $result = sort_apply::execute($parent, 'categories', false, $preview['hash']);
        $result = external_api::clean_returnvalue(sort_apply::execute_returns(), $result);

        $this->assertSame(['status' => 'stale', 'logid' => 0], $result);
        $this->assertSame([$c, $a, $b], tree::sibling_categories($parent));
        $this->assertSame(0, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * A fresh hash applies subcategories and courses in one journal entry, and the undo restores both.
     */
    public function test_sort_apply_applies_and_undo_restores(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('P');
        $c = $this->category('C', $parent);
        $a = $this->category('A', $parent);
        $one = $this->course('Curso 1', 'C1', $parent);
        $ten = $this->course('Curso 10', 'C10', $parent);
        $two = $this->course('Curso 2', 'C2', $parent);
        $this->assertSame([$two, $ten, $one], tree::category_courses($parent));

        $preview = sort_preview::execute($parent, 'both', false);
        $this->assertSame(['categories', 'courses'], array_column($preview['changes'], 'kind'));

        $result = sort_apply::execute($parent, 'both', false, $preview['hash']);

        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame([$a, $c], tree::sibling_categories($parent));
        $this->assertSame([$one, $two, $ten], tree::category_courses($parent));
        $this->assertSame(1, $DB->count_records('tool_ceaorganizador_log', ['action' => 'sort']));

        // Nothing is left to sort: an empty plan writes no journal row.
        $again = sort_preview::execute($parent, 'both', false);
        $this->assertSame([], $again['changes']);
        $this->assertSame(['status' => 'ok', 'logid' => 0], sort_apply::execute($parent, 'both', false, $again['hash']));

        $undo = undo::execute($result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame([$c, $a], tree::sibling_categories($parent));
        $this->assertSame([$two, $ten, $one], tree::category_courses($parent));
    }

    /**
     * Several categories are sorted with one preview, one apply, one journal row and one undo.
     */
    public function test_sort_apply_many_records_one_entry(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $first = $this->category('P1');
        $c1 = $this->category('C', $first);
        $a1 = $this->category('A', $first);
        $second = $this->category('P2');
        $y2 = $this->category('Y', $second);
        $x2 = $this->category('X', $second);

        $preview = sort_preview::execute(0, 'categories', false, [$first, $second]);
        $preview = external_api::clean_returnvalue(sort_preview::execute_returns(), $preview);
        $this->assertCount(2, $preview['changes']);

        $result = sort_apply::execute(0, 'categories', false, $preview['hash'], [$first, $second]);
        $result = external_api::clean_returnvalue(sort_apply::execute_returns(), $result);

        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame([$a1, $c1], tree::sibling_categories($first));
        $this->assertSame([$x2, $y2], tree::sibling_categories($second));
        $this->assertSame(1, $DB->count_records('tool_ceaorganizador_log', ['action' => 'sort']));

        $undo = undo::execute($result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame([$c1, $a1], tree::sibling_categories($first));
        $this->assertSame([$y2, $x2], tree::sibling_categories($second));
    }

    /**
     * A category mixed with a list, a long list or a list holding the top level is refused.
     */
    public function test_sort_apply_rejects_both_params(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('P');
        $other = $this->category('Q');
        $preview = sort_preview::execute($parent, 'categories', false);

        try {
            sort_apply::execute($parent, 'categories', false, $preview['hash'], [$other]);
            $this->fail('categoryid and categoryids must not be combined');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('cannot be combined', $e->getMessage());
        }
        try {
            sort_preview::execute($parent, 'categories', false, [$other]);
            $this->fail('categoryid and categoryids must not be combined');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('cannot be combined', $e->getMessage());
        }
        try {
            sort_preview::execute(0, 'categories', false, range(1, 201));
            $this->fail('Too many categories must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('Too many', $e->getMessage());
        }
        try {
            sort_preview::execute(0, 'categories', false, [$other, 0]);
            $this->fail('The top level inside a list must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('Invalid category id', $e->getMessage());
        }
    }

    /**
     * Both services return values that pass the declared structure when they receive categoryids.
     */
    public function test_sort_many_clean_returnvalue(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('P');
        $this->category('C', $parent);
        $this->category('A', $parent);

        $preview = sort_preview::execute(0, 'both', true, [$parent]);
        $preview = external_api::clean_returnvalue(sort_preview::execute_returns(), $preview);
        $this->assertCount(1, $preview['changes']);

        $result = sort_apply::execute(0, 'both', true, $preview['hash'], [$parent]);
        $result = external_api::clean_returnvalue(sort_apply::execute_returns(), $result);
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThan(0, $result['logid']);
    }

    /**
     * Two courses of the batch that would end with the same short name are both flagged.
     */
    public function test_rename_preview_flags_duplicate_shortnames_in_batch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $first = $this->course('Uno', 'A-X', $category);
        $second = $this->course('Dos', 'B-X', $category);
        $ops = json_encode([
            ['type' => 'replace', 'search' => 'A-', 'replace' => 'Z-', 'casesensitive' => true, 'wholeword' => false],
            ['type' => 'replace', 'search' => 'B-', 'replace' => 'Z-', 'casesensitive' => true, 'wholeword' => false],
        ]);
        $items = [['type' => 'course', 'id' => $first], ['type' => 'course', 'id' => $second]];

        $result = rename_preview::execute($items, $ops, '{}', true);
        $result = external_api::clean_returnvalue(rename_preview::execute_returns(), $result);

        $this->assertCount(2, $result['rows']);
        foreach ($result['rows'] as $row) {
            $this->assertSame('shortnameduplicate', $row['error']);
            $this->assertSame('Z-X', $row['newshort']);
            $this->assertSame($row['old'], $row['new']);
        }
        $this->assertEqualsCanonicalizing([$first, $second], array_column($result['rows'], 'id'));
    }

    /**
     * A new short name held by a course outside the batch is flagged; without includeshortname it is ignored.
     */
    public function test_rename_preview_flags_shortname_taken_outside_batch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $first = $this->course('Uno', 'A-X', $category);
        $this->course('Otro', 'Z-X', $category);
        $ops = json_encode([['type' => 'replace', 'search' => 'A-', 'replace' => 'Z-']]);
        $items = [['type' => 'course', 'id' => $first]];

        $result = rename_preview::execute($items, $ops, '{}', true);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('shortnametaken', $result['rows'][0]['error']);
        $this->assertSame([], rename_preview::execute($items, $ops, '{}', false)['rows']);
    }

    /**
     * Operations with keys the renamer does not know are rejected.
     */
    public function test_rename_preview_rejects_unknown_op_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $ops = json_encode([['type' => 'replace', 'search' => 'a', 'replace' => 'b', 'evil' => 1]]);

        $this->expectException(\invalid_parameter_exception::class);
        rename_preview::execute([['type' => 'category', 'id' => $category]], $ops, '{}', false);
    }

    /**
     * Rows whose name changed since the preview are skipped; the others are applied and recorded.
     */
    public function test_rename_apply_skips_changed_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $one = $this->category('Uno');
        $two = $this->category('Dos');
        $changes = [
            ['type' => 'category', 'id' => $one, 'old' => 'Uno', 'new' => 'Uno nuevo'],
            ['type' => 'category', 'id' => $two, 'old' => 'Dos', 'new' => 'Dos nuevo'],
        ];
        \core_course_category::get($two, MUST_EXIST, true)->update(['name' => 'Dos cambiado']);

        $result = rename_apply::execute($changes);
        $result = external_api::clean_returnvalue(rename_apply::execute_returns(), $result);

        $this->assertSame(['ok', 'skipped'], array_column($result['results'], 'status'));
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame('Uno nuevo', $DB->get_field('course_categories', 'name', ['id' => $one]));
        $this->assertSame('Dos cambiado', $DB->get_field('course_categories', 'name', ['id' => $two]));
        $payload = json_decode($DB->get_field('tool_ceaorganizador_log', 'payload', ['id' => $result['logid']]), true);
        $this->assertCount(1, $payload['items']);
        $this->assertSame(['name' => 'Uno'], $payload['items'][0]['before']);

        $this->assertTrue(undo::execute($result['logid'])['ok']);
        $this->assertSame('Uno', $DB->get_field('course_categories', 'name', ['id' => $one]));
    }

    /**
     * A short name freed by another course of the batch is taken after it is freed, whatever the given order.
     */
    public function test_rename_apply_orders_shortname_chain(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $first = $this->course('Uno', 'S1', $category);
        $second = $this->course('Dos', 'S2', $category);
        $changes = [
            ['type' => 'course', 'id' => $first, 'old' => 'Uno', 'new' => 'Uno', 'oldshort' => 'S1', 'newshort' => 'S2'],
            ['type' => 'course', 'id' => $second, 'old' => 'Dos', 'new' => 'Dos', 'oldshort' => 'S2', 'newshort' => 'S3'],
        ];

        $result = rename_apply::execute($changes);

        $this->assertSame(['ok', 'ok'], array_column($result['results'], 'status'));
        $this->assertSame('S2', $DB->get_field('course', 'shortname', ['id' => $first]));
        $this->assertSame('S3', $DB->get_field('course', 'shortname', ['id' => $second]));

        $this->assertTrue(undo::execute($result['logid'])['ok']);
        $this->assertSame('S1', $DB->get_field('course', 'shortname', ['id' => $first]));
        $this->assertSame('S2', $DB->get_field('course', 'shortname', ['id' => $second]));
    }

    /**
     * More than 500 rows are refused before anything is read or written.
     */
    public function test_rename_apply_rejects_over_500(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $changes = [];
        for ($id = 1; $id <= 501; $id++) {
            $changes[] = ['type' => 'category', 'id' => $id, 'old' => 'a', 'new' => 'b'];
        }

        try {
            rename_apply::execute($changes);
            $this->fail('More than 500 rows must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_toomany', $e->errorcode);
        }
    }

    /**
     * The history formats the raw summary on output.
     */
    public function test_history_escapes_summary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->category('A');
        $rd = $this->category('R&D');
        move_category::execute($rd, $a, 0);

        $result = external_api::clean_returnvalue(history::execute_returns(), history::execute(0));

        $this->assertSame(1, $result['total']);
        $this->assertSame(20, $result['perpage']);
        $this->assertStringContainsString('R&amp;D', $result['rows'][0]['summary']);
        $this->assertFalse($result['rows'][0]['undone']);
    }

    /**
     * The summary count adds the top level to the categories that recommend sorting.
     */
    public function test_get_tree_counts_root(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->category('B');
        $this->category('A');

        $result = external_api::clean_returnvalue(get_tree::execute_returns(), get_tree::execute());

        $this->assertTrue($result['rootneedsort']);
        $count = count(array_filter(array_column($result['categories'], 'needsort')));
        $this->assertSame($count + 1, $result['needsortcount']);
    }

    /**
     * get_tree counts the repeated courses and get_courses marks them, with the exact types they declare.
     */
    public function test_tree_services_return_repeated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('Facultad');
        $child = $this->category('Programa', $parent);
        $first = $this->course('Cálculo I', 'CAL1', $child);
        $copy = $this->course('Cálculo I (copia)', 'CAL1_1', $child);
        $other = $this->course('Cálculo II', 'CAL2', $child);
        $DB->set_field('course', 'timemodified', 100, ['id' => $first]);
        $DB->set_field('course', 'timemodified', 200, ['id' => $copy]);

        $tree = external_api::clean_returnvalue(get_tree::execute_returns(), get_tree::execute());
        $rows = array_column($tree['categories'], null, 'id');
        $this->assertSame(1, $rows[$child]['dupcount']);
        $this->assertSame(1, $rows[$child]['dupcounttotal']);
        $this->assertSame(0, $rows[$parent]['dupcount']);
        $this->assertSame(1, $rows[$parent]['dupcounttotal']);

        $courses = external_api::clean_returnvalue(get_courses::execute_returns(), get_courses::execute($child, '', 0));
        $byid = array_column($courses['courses'], null, 'id');
        $this->assertSame('base', $byid[$copy]['dup']);
        $this->assertSame(['key', 'shortname'], $byid[$copy]['dupreasons']);
        $this->assertSame('', $byid[$copy]['dupbase']);
        $this->assertSame('repeated', $byid[$first]['dup']);
        $this->assertSame('Cálculo I (copia)', $byid[$first]['dupbase']);
        $this->assertSame(['', [], ''], [$byid[$other]['dup'], $byid[$other]['dupreasons'], $byid[$other]['dupbase']]);
    }

    /**
     * Moving a top-level category needs category:manage in the system context, as core requires.
     */
    public function test_move_category_checks_top_level_source(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->category('X');
        $y = $this->category('Y');
        $user = $this->user_with([]);
        $this->grant($user->id, 'moodle/category:manage', \context_coursecat::instance($x));
        $this->grant($user->id, 'moodle/category:manage', \context_coursecat::instance($y));
        $this->setUser($user);

        $this->assert_refused(function () use ($x, $y): void {
            move_category::execute($x, $y, 0);
        });

        $this->assertSame(0, (int) $DB->get_field('course_categories', 'parent', ['id' => $x]));
        $this->assertSame(0, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * Moving a course needs course:changecategory on it.
     */
    public function test_move_course_requires_changecategory(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $x = $this->category('X');
        $y = $this->category('Y');
        $course = $this->course('Uno', 'U1', $x);
        $this->setUser($this->user_with(['moodle/category:manage']));

        $this->assert_refused(function () use ($course, $y): void {
            move_course::execute($course, $y, 0);
        });

        $this->assertSame($x, (int) $DB->get_field('course', 'category', ['id' => $course]));
        $this->assertSame(0, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * Renaming a course needs course:update on it.
     */
    public function test_rename_apply_requires_course_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->course('Uno', 'U1', $this->category('K'));
        $this->setUser($this->user_with(['moodle/category:manage']));

        $this->assert_refused(function () use ($course): void {
            rename_apply::execute([['type' => 'course', 'id' => $course, 'old' => 'Uno', 'new' => 'Uno nuevo']]);
        });

        $this->assertSame('Uno', $DB->get_field('course', 'fullname', ['id' => $course]));
        $this->assertSame(0, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * Sorting needs category:manage on every parent whose children change.
     */
    public function test_sort_apply_requires_category_manage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('P');
        $c = $this->category('C', $parent);
        $a = $this->category('A', $parent);
        $this->setUser($this->user_with([]));
        $preview = sort_preview::execute($parent, 'categories', false);

        $this->assert_refused(function () use ($parent, $preview): void {
            sort_apply::execute($parent, 'categories', false, $preview['hash']);
        });

        $this->assertSame([$c, $a], tree::sibling_categories($parent));
        $this->assertSame(0, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * A new short name needs the old one, and is skipped when the old one changed since the preview.
     */
    public function test_rename_apply_requires_and_compares_oldshort(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->course('Uno', 'U1', $this->category('K'));
        $change = ['type' => 'course', 'id' => $course, 'old' => 'Uno', 'new' => 'Uno', 'newshort' => 'U2'];

        try {
            rename_apply::execute([$change]);
            $this->fail('newshort without oldshort must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('oldshort', $e->debuginfo);
        }

        $result = rename_apply::execute([$change + ['oldshort' => 'OTRO']]);

        $this->assertSame('skipped', $result['results'][0]['status']);
        $this->assertSame(0, $result['logid']);
        $this->assertSame('U1', $DB->get_field('course', 'shortname', ['id' => $course]));
    }

    /**
     * Hiding courses writes one journal entry with the changed courses, before and after.
     */
    public function test_set_visibility_hides_and_records(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $one = $this->course('Uno', 'U1', $category);
        $two = $this->course('Dos', 'D2', $category);

        $result = set_visibility::execute([$one, $two], false);

        $this->assertSame(2, $result['changed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $one]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $two]));
        $this->assertSame(0, (int) $DB->get_field('course', 'visibleold', ['id' => $one]));
        $log = $DB->get_record('tool_ceaorganizador_log', ['id' => $result['logid']], '*', MUST_EXIST);
        $this->assertSame('visibility', $log->action);
        $this->assertSame(get_string('summary_hide', 'tool_ceaorganizador', 'Uno, Dos'), $log->summary);
        $payload = json_decode($log->payload, true);
        $this->assertSame([
            ['id' => $one, 'name' => 'Uno', 'before' => 1, 'after' => 0],
            ['id' => $two, 'name' => 'Dos', 'before' => 1, 'after' => 0],
        ], $payload['items']);
        $this->assertSame(1, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * Courses that already have the visibility are skipped and left out of the journal.
     */
    public function test_set_visibility_skips_unchanged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $shown = $this->course('Uno', 'U1', $category);
        $hidden = (int) $this->getDataGenerator()->create_course([
            'fullname' => 'Dos',
            'shortname' => 'D2',
            'category' => $category,
            'visible' => 0,
        ])->id;

        $result = set_visibility::execute([$shown, $hidden], false);

        $this->assertSame(1, $result['changed']);
        $this->assertSame(1, $result['skipped']);
        $payload = json_decode($DB->get_field('tool_ceaorganizador_log', 'payload', ['id' => $result['logid']]), true);
        $this->assertSame([$shown], array_column($payload['items'], 'id'));

        // Nothing to change: no journal row.
        $again = set_visibility::execute([$shown, $hidden], false);

        $this->assertSame(['changed' => 0, 'skipped' => 2, 'logid' => 0], $again);
        $this->assertSame(1, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * Without course:visibility on one course, nothing changes, not even on the other courses.
     */
    public function test_set_visibility_requires_course_visibility_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $allowed = $this->course('Uno', 'U1', $category);
        $denied = $this->course('Dos', 'D2', $category);
        $user = $this->user_with(['moodle/category:manage']);
        $this->grant($user->id, 'moodle/course:visibility', \context_course::instance($allowed));
        $this->setUser($user);

        $this->assert_refused(function () use ($allowed, $denied): void {
            set_visibility::execute([$allowed, $denied], false);
        });

        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $allowed]));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $denied]));
        $this->assertSame(0, $DB->count_records('tool_ceaorganizador_log'));
    }

    /**
     * More than 500 courses, or the site course, are refused before anything is written.
     */
    public function test_set_visibility_rejects_over_500_and_site(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            set_visibility::execute(range(2, 502), false);
            $this->fail('More than 500 courses must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_toomany', $e->errorcode);
        }
        $this->expectException(\invalid_parameter_exception::class);
        set_visibility::execute([SITEID], false);
    }

    /**
     * Undoing a visibility change gives every course its visibility back.
     */
    public function test_undo_visibility_restores(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $one = $this->course('Uno', 'U1', $category);
        $two = $this->course('Dos', 'D2', $category);
        $result = set_visibility::execute([$one, $two], false);

        $undo = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($result['logid']));

        $this->assertSame(['ok' => true, 'conflicts' => []], $undo);
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $one]));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $two]));
        $this->assertGreaterThan(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * When a course was shown again by hand, the undo writes nothing and names it.
     */
    public function test_undo_visibility_conflict(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->category('K');
        $one = $this->course('Uno', 'U1', $category);
        $two = $this->course('Curso Dos', 'D2', $category);
        $result = set_visibility::execute([$one, $two], false);
        course_change_visibility($two, true);

        $undo = undo::execute($result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertCount(1, $undo['conflicts']);
        $this->assertStringContainsString('Curso Dos', $undo['conflicts'][0]);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $one]));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $two]));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * The undo of a visibility change needs course:visibility on every course, as the change did.
     */
    public function test_undo_visibility_requires_course_visibility_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->course('Uno', 'U1', $this->category('K'));
        $result = set_visibility::execute([$course], false);
        $this->setUser($this->user_with(['moodle/category:manage']));

        $this->assert_refused(function () use ($result): void {
            undo::execute($result['logid']);
        });

        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $course]));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * Merges a source into a target as the admin, through the runner.
     *
     * @param int $target
     * @param int $source
     * @return int Journal id.
     */
    private function merged(int $target, int $source): int {
        $plan = \tool_ceaorganizador\local\mergeplan::build($target, [$source]);
        $result = \tool_ceaorganizador\local\mergerunner::run($target, [$source], [], $plan['hash']);
        $this->assertGreaterThan(0, $result['logid']);
        return (int) $result['logid'];
    }

    /**
     * The undo of a merge needs course:changecategory on every course, as the merge did.
     */
    public function test_undo_merge_requires_changecategory(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->category('Facultades');
        $course = $this->course('Uno', 'U1', $source);
        $target = $this->category('Semillas');
        $logid = $this->merged($target, $source);
        $this->setUser($this->user_with(['moodle/category:manage']));

        $this->assert_refused(function () use ($logid): void {
            undo::execute($logid);
        });

        $this->assertSame($target, (int) $DB->get_field('course', 'category', ['id' => $course]));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $logid]));
    }

    /**
     * The undo of a merge needs category:manage on the parent of every created category, here the target.
     */
    public function test_undo_merge_requires_manage_on_created_parent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->category('Facultades');
        $ing = $this->category('Ingeniería', $source);
        $course = $this->course('Uno', 'U1', $ing);
        $target = $this->category('Semillas');
        $logid = $this->merged($target, $source);
        $created = (int) $DB->get_field('course_categories', 'id', ['parent' => $target, 'name' => 'Ingeniería']);
        // Manages where the course was and where it is, but not the target the created category hangs from.
        $user = $this->user_with(['moodle/course:changecategory']);
        $this->grant((int) $user->id, 'moodle/category:manage', \context_coursecat::instance($source));
        $this->grant((int) $user->id, 'moodle/category:manage', \context_coursecat::instance($created));
        $this->setUser($user);

        $this->assert_refused(function () use ($logid): void {
            undo::execute($logid);
        });

        $this->assertSame($created, (int) $DB->get_field('course', 'category', ['id' => $course]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $created]));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'timeundone', ['id' => $logid]));
    }

    /**
     * set_visibility returns what its description declares, with and without changes.
     */
    public function test_set_visibility_clean_returnvalue(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->course('Uno', 'U1', $this->category('K'));

        $hidden = external_api::clean_returnvalue(set_visibility::execute_returns(), set_visibility::execute([$course], false));
        $this->assertSame(1, $hidden['changed']);
        $this->assertGreaterThan(0, $hidden['logid']);

        $same = external_api::clean_returnvalue(set_visibility::execute_returns(), set_visibility::execute([$course], false));
        $this->assertSame(['changed' => 0, 'skipped' => 1, 'logid' => 0], $same);
    }

    /**
     * Every function returns what its description declares.
     */
    public function test_execute_returns_validate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('P');
        $b = $this->category('B', $parent);
        $a = $this->category('A', $parent);
        $course = $this->course('Curso 1', 'C1', $parent);
        $this->course('Curso 2', 'C2', $parent);

        $tree = get_tree::execute();
        $this->assertNotEmpty(external_api::clean_returnvalue(get_tree::execute_returns(), $tree)['categories']);

        $courses = external_api::clean_returnvalue(get_courses::execute_returns(), get_courses::execute($parent, 'Curso', 0));
        $this->assertSame(2, $courses['total']);

        $preview = sort_preview::execute(0, 'both', true);
        $preview = external_api::clean_returnvalue(sort_preview::execute_returns(), $preview);
        $this->assertNotEmpty($preview['changes']);

        $applied = sort_apply::execute(0, 'both', true, $preview['hash']);
        $applied = external_api::clean_returnvalue(sort_apply::execute_returns(), $applied);
        $this->assertSame('ok', $applied['status']);

        $ops = json_encode([['type' => 'affix', 'position' => 'suffix', 'mode' => 'add', 'text' => ' (copia)']]);
        $items = [['type' => 'category', 'id' => $a], ['type' => 'course', 'id' => $course]];
        $rows = rename_preview::execute($items, $ops, json_encode(['category:' . $a => 'Ahora']), true);
        $rows = external_api::clean_returnvalue(rename_preview::execute_returns(), $rows)['rows'];
        $this->assertCount(2, $rows);

        $changes = [];
        foreach ($rows as $row) {
            $change = ['type' => $row['type'], 'id' => $row['id'], 'old' => $row['old'], 'new' => $row['new']];
            if (isset($row['newshort'])) {
                $change += ['oldshort' => $row['oldshort'], 'newshort' => $row['newshort']];
            }
            $changes[] = $change;
        }
        $renamed = external_api::clean_returnvalue(rename_apply::execute_returns(), rename_apply::execute($changes));
        $this->assertSame(['ok', 'ok'], array_column($renamed['results'], 'status'));

        $moved = external_api::clean_returnvalue(move_course::execute_returns(), move_course::execute($course, $b, 0));
        $this->assertSame($b, $moved['to']['category']);

        $movedcat = external_api::clean_returnvalue(move_category::execute_returns(), move_category::execute($b, 0, 0));
        $this->assertSame(0, $movedcat['to']['parent']);

        $undo = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($movedcat['logid']));
        $this->assertTrue($undo['ok']);

        $history = external_api::clean_returnvalue(history::execute_returns(), history::execute(0));
        $this->assertSame(4, $history['total']);
    }
}
