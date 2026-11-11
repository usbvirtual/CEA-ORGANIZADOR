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
#[\PHPUnit\Framework\Attributes\CoversClass(delete_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(delete_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(undo::class)]
/**
 * Tests for the web services that delete categories, against the test database.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\delete_preview
 * @covers     \tool_ceaorganizador\external\delete_apply
 * @covers     \tool_ceaorganizador\external\undo
 */
final class delete_external_test extends \advanced_testcase {
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
     * The preview lists what would be deleted and what kept, and survives its return definition.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('Padre');
        $empty = $this->category('Vacía', $parent);
        $full = $this->category('Llena', $parent);
        $this->getDataGenerator()->create_course(['category' => $full]);

        $result = external_api::clean_returnvalue(
            delete_preview::execute_returns(),
            delete_preview::execute([$empty, $full, $parent])
        );

        $this->assertSame([['id' => $empty, 'path' => 'Padre / Vacía']], $result['delete']);
        $reasons = array_column($result['skipped'], 'reason', 'id');
        $this->assertSame(['hascourses', 'haschildren'], [$reasons[$full], $reasons[$parent]]);
        $paths = array_column($result['skipped'], 'path', 'id');
        $this->assertSame('Padre / Llena', $paths[$full]);
    }

    /**
     * Apply deletes the empty ones, returns the skipped ones with their raw names, and undo creates them again.
     */
    public function test_apply_and_undo(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('Padre');
        $empty = $this->category('Vacía', $parent);
        $child = $this->category('Hija', $parent);
        $grandchild = $this->category('Nieta', $child);
        $this->getDataGenerator()->create_course(['category' => $grandchild]);

        $result = external_api::clean_returnvalue(delete_apply::execute_returns(), delete_apply::execute([$empty, $child]));

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['deleted']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame([['id' => $child, 'name' => 'Hija', 'reason' => 'haschildren']], $result['skipped']);
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $empty]));
        $this->assertSame(1, $DB->count_records(journal::TABLE, ['action' => 'delete']));

        $undo = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($result['logid']));

        $this->assertSame(['ok' => true, 'conflicts' => []], $undo);
        $this->assertTrue($DB->record_exists('course_categories', ['parent' => $parent, 'name' => 'Vacía']));
    }

    /**
     * Nothing to delete records nothing; a category that no longer exists is reported as such.
     */
    public function test_apply_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $missing = $this->category('Temporal');
        \core_course_category::get($missing, MUST_EXIST, true)->delete_full(false);

        $result = external_api::clean_returnvalue(delete_apply::execute_returns(), delete_apply::execute([$missing]));

        $this->assertSame([
            'status' => 'ok',
            'logid' => 0,
            'deleted' => 0,
            'skipped' => [['id' => $missing, 'name' => '', 'reason' => 'notfound']],
        ], $result);
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Without the plugin capability neither service answers.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $empty = $this->category('Vacía');
        $this->setUser($this->getDataGenerator()->create_user());

        $calls = [
            static function () use ($empty): void {
                delete_preview::execute([$empty]);
            },
            static function () use ($empty): void {
                delete_apply::execute([$empty]);
            },
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected required_capability_exception');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * With the plugin capability but without moodle/category:manage, the category is skipped, not deleted.
     */
    public function test_apply_skips_without_manage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $empty = $this->category('Vacía');
        $this->setUser($this->user_with([]));

        $result = delete_apply::execute([$empty]);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame('nopermission', $result['skipped'][0]['reason']);
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $empty]));
    }

    /**
     * The undo of a deletion needs moodle/category:manage on the old parent, as creating it does.
     */
    public function test_undo_requires_manage_on_parent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->category('Padre');
        $empty = $this->category('Vacía', $parent);
        $result = delete_apply::execute([$empty]);
        $this->setUser($this->user_with([]));

        try {
            undo::execute($result['logid']);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/category:manage'), $e->a);
        }

        $this->assertSame(0, $DB->count_records('course_categories', ['parent' => $parent]));
        $this->assertSame(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['logid']]));
    }
}
