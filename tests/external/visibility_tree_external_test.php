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
use tool_ceaorganizador\local\journal;

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(visibility_tree_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(visibility_tree_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(undo::class)]
/**
 * Tests for the web services of «Ocultar todo» and «Mostrar todo», and their undo.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\visibility_tree_preview
 * @covers     \tool_ceaorganizador\external\visibility_tree_apply
 * @covers     \tool_ceaorganizador\external\undo
 */
final class visibility_tree_external_test extends \advanced_testcase {
    /**
     * A category with a subcategory and a course in each.
     *
     * @return int[] ['root', 'sub', 'a', 'b']
     */
    private function fixture(): array {
        $generator = $this->getDataGenerator();
        $root = (int) $generator->create_category(['name' => 'Facultad'])->id;
        $sub = (int) $generator->create_category(['name' => 'Programa', 'parent' => $root])->id;
        $a = (int) $generator->create_course(['category' => $root, 'shortname' => 'VTE-A'])->id;
        $b = (int) $generator->create_course(['category' => $sub, 'shortname' => 'VTE-B'])->id;
        return ['root' => $root, 'sub' => $sub, 'a' => $a, 'b' => $b];
    }

    /**
     * Visibility of a course or a category.
     *
     * @param string $table
     * @param int $id
     * @return int
     */
    private function visible(string $table, int $id): int {
        global $DB;
        return (int) $DB->get_field($table, 'visible', ['id' => $id], MUST_EXIST);
    }

    /**
     * The preview counts what would change; the apply hides it all, and the undo service shows it again.
     */
    public function test_preview_apply_and_undo(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();

        $preview = external_api::clean_returnvalue(
            visibility_tree_preview::execute_returns(),
            visibility_tree_preview::execute([$ids['root']], false)
        );
        $this->assertSame(2, $preview['categories']);
        $this->assertSame(2, $preview['courses']);

        $result = external_api::clean_returnvalue(
            visibility_tree_apply::execute_returns(),
            visibility_tree_apply::execute([$ids['root']], false, $preview['hash'])
        );
        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['categories']);
        $this->assertSame(2, $result['courses']);
        $this->assertGreaterThan(0, $result['logid']);
        foreach (['root', 'sub'] as $name) {
            $this->assertSame(0, $this->visible('course_categories', $ids[$name]));
        }
        foreach (['a', 'b'] as $name) {
            $this->assertSame(0, $this->visible('course', $ids[$name]));
        }

        $undo = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($result['logid']));
        $this->assertSame(['ok' => true, 'conflicts' => []], $undo);
        $this->assertSame(1, $this->visible('course_categories', $ids['sub']));
        $this->assertSame(1, $this->visible('course', $ids['b']));
    }

    /**
     * An old hash comes back as stale and nothing is written.
     */
    public function test_apply_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $preview = visibility_tree_preview::execute([$ids['root']], false);
        $DB->set_field('course', 'visible', 0, ['id' => $ids['a']]);

        $result = visibility_tree_apply::execute([$ids['root']], false, $preview['hash']);

        $this->assertSame('stale', $result['status']);
        $this->assertSame(1, $this->visible('course_categories', $ids['root']));
        $this->assertSame(1, $this->visible('course', $ids['b']));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Both services need the plugin capability.
     */
    public function test_requires_plugin_capability(): void {
        $this->resetAfterTest();
        $ids = $this->fixture();
        $this->setUser($this->getDataGenerator()->create_user());
        $calls = [
            fn() => visibility_tree_preview::execute([$ids['root']], false),
            fn() => visibility_tree_apply::execute([$ids['root']], false, 'abc'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('The plugin capability is required');
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string('tool/ceaorganizador:manage'), $e->a);
            }
        }
    }

    /**
     * Undoing needs the same native capabilities as the operation: without moodle/course:visibility it is refused
     * before any write.
     */
    public function test_undo_requires_native_capabilities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = $this->fixture();
        $preview = visibility_tree_preview::execute([$ids['root']], false);
        $result = visibility_tree_apply::execute([$ids['root']], false, $preview['hash']);

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        $system = \context_system::instance();
        foreach (['tool/ceaorganizador:manage', 'moodle/category:manage'] as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
        }
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);

        try {
            undo::execute($result['logid']);
            $this->fail('moodle/course:visibility is required');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:visibility'), $e->a);
        }
        $this->assertSame(0, $this->visible('course', $ids['a']));
        $this->assertSame(0, $this->visible('course_categories', $ids['sub']));
    }
}
