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
#[\PHPUnit\Framework\Attributes\CoversClass(merge_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(merge_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(create_seedroot::class)]
/**
 * Tests for the merge web services, against the test database.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\merge_preview
 * @covers     \tool_ceaorganizador\external\merge_apply
 * @covers     \tool_ceaorganizador\external\create_seedroot
 */
final class merge_external_test extends \advanced_testcase {
    /**
     * A source with two courses, one of them repeated in the target, and an empty target.
     *
     * @return array [source category, target category, course A, course B]
     */
    private function seeds(): array {
        $gen = $this->getDataGenerator();
        $source = $gen->create_category(['name' => 'Facultades']);
        $program = $gen->create_category(['name' => 'Programa A', 'parent' => $source->id]);
        $target = $gen->create_category(['name' => 'Semillas']);
        $targetprogram = $gen->create_category(['name' => 'programa á', 'parent' => $target->id]);
        $a = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $program->id]);
        $b = $gen->create_course(['fullname' => 'Física I', 'shortname' => 'FIS1', 'category' => $program->id]);
        $gen->create_course(['fullname' => 'Física I', 'shortname' => 'FIS1B', 'category' => $targetprogram->id]);
        return [$source, $target, $a, $b];
    }

    /**
     * Previews a merge through the service and cleans the result.
     *
     * @param \core_course_category $source
     * @param \core_course_category $target
     * @return array
     */
    private function preview_of(\core_course_category $source, \core_course_category $target): array {
        $result = merge_preview::execute((int) $target->id, [(int) $source->id]);
        return external_api::clean_returnvalue(merge_preview::execute_returns(), $result);
    }

    /**
     * Applies a merge through the service and cleans the result.
     *
     * @param \core_course_category $source
     * @param \core_course_category $target
     * @param array $rows
     * @param string $hash
     * @return array
     */
    private function apply_to(\core_course_category $source, \core_course_category $target, array $rows, string $hash): array {
        $result = merge_apply::execute((int) $target->id, [(int) $source->id], $rows, $hash);
        return external_api::clean_returnvalue(merge_apply::execute_returns(), $result);
    }

    /**
     * The preview is the plan: the repeated course is flagged and skipped, and the output survives the return definition.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target, $a, $b] = $this->seeds();

        $result = $this->preview_of($source, $target);

        $this->assertCount(2, $result['rows']);
        $this->assertNotEmpty($result['hash']);
        $bycourse = array_column($result['rows'], null, 'courseid');
        $this->assertFalse($bycourse[$a->id]['duplicate']);
        $this->assertSame('move', $bycourse[$a->id]['action']);
        $this->assertSame([], $bycourse[$a->id]['newcategories']);
        $this->assertTrue($bycourse[$b->id]['duplicate']);
        $this->assertSame('skip', $bycourse[$b->id]['action']);
        $this->assertArrayNotHasKey('toid', $bycourse[$a->id]);
        $this->assertSame('Facultades / Programa A', $bycourse[$a->id]['from']);
        $this->assertSame('Semillas / programa á', $bycourse[$a->id]['to']);
        $this->assertSame([], $result['emptied']);
    }

    /**
     * Apply moves the courses that stay as move, honours skip, and returns what was left empty.
     */
    public function test_apply(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target, $a, $b] = $this->seeds();
        $preview = $this->preview_of($source, $target);

        // The repeated course is moved too, as the user chose.
        $rows = [
            ['courseid' => (int) $a->id, 'action' => 'move'],
            ['courseid' => (int) $b->id, 'action' => 'move'],
        ];
        $result = $this->apply_to($source, $target, $rows, $preview['hash']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['moved']);
        $this->assertSame(0, $result['skipped']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame(1, $DB->count_records(journal::TABLE, ['action' => 'merge']));
        $this->assertSame(
            [(int) $source->id, (int) $DB->get_field('course_categories', 'id', ['name' => 'Programa A', 'parent' => $source->id])],
            array_reverse(array_column($result['emptied'], 'id'))
        );
        $this->assertSame('Facultades / Programa A', $result['emptied'][0]['path']);
    }

    /**
     * Rows left as skip are not moved and are counted.
     */
    public function test_apply_skip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target, $a, $b] = $this->seeds();
        $preview = $this->preview_of($source, $target);

        $rows = [
            ['courseid' => (int) $a->id, 'action' => 'skip'],
            ['courseid' => (int) $b->id, 'action' => 'skip'],
        ];
        $result = $this->apply_to($source, $target, $rows, $preview['hash']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['moved']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, $result['logid']);
        $this->assertSame([], $result['emptied']);
    }

    /**
     * A hash that no longer matches moves nothing.
     */
    public function test_apply_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target] = $this->seeds();

        $result = $this->apply_to($source, $target, [], sha1('other'));

        $this->assertSame(['status' => 'stale', 'logid' => 0, 'moved' => 0, 'skipped' => 0, 'emptied' => []], $result);
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * An action other than move or skip is rejected by the runner, and nothing is written.
     */
    public function test_apply_rejects_unknown_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target, $a] = $this->seeds();
        $preview = $this->preview_of($source, $target);

        $this->expectException(\invalid_parameter_exception::class);
        $this->apply_to($source, $target, [['courseid' => (int) $a->id, 'action' => 'delete']], $preview['hash']);
    }

    /**
     * The seed category is created at the top level, with the name written.
     */
    public function test_create_seedroot_creates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = external_api::clean_returnvalue(create_seedroot::execute_returns(), create_seedroot::execute('  Mis semillas '));

        $this->assertTrue($result['created']);
        $this->assertSame('Mis semillas', $result['name']);
        $record = $DB->get_record('course_categories', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame(0, (int) $record->parent);
        $this->assertSame('Mis semillas', $record->name);
        $this->assertSame(0, $DB->count_records(journal::TABLE));

        $default = external_api::clean_returnvalue(create_seedroot::execute_returns(), create_seedroot::execute());
        $this->assertSame('Semillas', $default['name']);
    }

    /**
     * A top-level category with the same normalized name is returned, not duplicated.
     */
    public function test_create_seedroot_returns_existing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $existing = $this->getDataGenerator()->create_category(['name' => 'Semillas']);
        $before = $DB->count_records('course_categories');

        $result = external_api::clean_returnvalue(create_seedroot::execute_returns(), create_seedroot::execute('SEMILLÁS'));

        $this->assertFalse($result['created']);
        $this->assertSame((int) $existing->id, $result['id']);
        $this->assertSame('Semillas', $result['name']);
        $this->assertSame($before, $DB->count_records('course_categories'));
    }

    /**
     * A subcategory with that name does not count: only the top level is looked at.
     */
    public function test_create_seedroot_ignores_subcategories(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parent = $this->getDataGenerator()->create_category(['name' => 'Otra']);
        $this->getDataGenerator()->create_category(['name' => 'Semillas', 'parent' => $parent->id]);

        $result = create_seedroot::execute('Semillas');

        $this->assertTrue($result['created']);
    }

    /**
     * An empty name is rejected.
     */
    public function test_create_seedroot_rejects_empty_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        create_seedroot::execute('   ');
    }

    /**
     * Without the plugin capability none of the three services answers.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target] = $this->seeds();
        $preview = $this->preview_of($source, $target);
        $this->setUser($this->getDataGenerator()->create_user());

        $calls = [
            static function () use ($source, $target): void {
                merge_preview::execute((int) $target->id, [(int) $source->id]);
            },
            static function () use ($source, $target, $preview): void {
                merge_apply::execute((int) $target->id, [(int) $source->id], [], $preview['hash']);
            },
            static function (): void {
                create_seedroot::execute('Semillas');
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
     * With the plugin capability but without moodle/category:manage, a seed category is not created, and a merge
     * is not applied (the runner checks the native capabilities).
     */
    public function test_native_capabilities_are_required(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $target] = $this->seeds();
        $preview = $this->preview_of($source, $target);

        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $roleid = $gen->create_role();
        $system = \context_system::instance();
        assign_capability('tool/ceaorganizador:manage', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);
        $before = $DB->count_records('course_categories');

        try {
            create_seedroot::execute('Nueva');
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('course_categories'));

        try {
            $this->apply_to($source, $target, [], $preview['hash']);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }
}
