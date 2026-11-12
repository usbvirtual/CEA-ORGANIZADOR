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
#[\PHPUnit\Framework\Attributes\CoversClass(dedupe_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dedupe_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(undo::class)]
/**
 * Tests for the «Quitar repetidos» web services, against the test database.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\dedupe_preview
 * @covers     \tool_ceaorganizador\external\dedupe_apply
 * @covers     \tool_ceaorganizador\external\undo
 */
final class dedupe_external_test extends \advanced_testcase {
    /**
     * A category with two repeated courses (the second one newer) and another course.
     *
     * @return array [category, older course, newer course]
     */
    private function seeds(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $category = $gen->create_category(['name' => 'Programa A']);
        $old = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $category->id]);
        $new = $gen->create_course(['fullname' => 'Cálculo I copia 1', 'shortname' => 'CALC1_1', 'category' => $category->id]);
        $gen->create_course(['fullname' => 'Física I', 'shortname' => 'FIS1', 'category' => $category->id]);
        $DB->set_field('course', 'timemodified', 1000, ['id' => $old->id]);
        $DB->set_field('course', 'timemodified', 2000, ['id' => $new->id]);
        return [$category, (int) $old->id, (int) $new->id];
    }

    /**
     * Previews through the service and cleans the result.
     *
     * @param int $categoryid
     * @return array
     */
    private function preview_of(int $categoryid): array {
        return external_api::clean_returnvalue(dedupe_preview::execute_returns(), dedupe_preview::execute([$categoryid]));
    }

    /**
     * The preview survives the return definition: raw names, formatted path, the default course that stays.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$category, $old, $new] = $this->seeds();

        $result = $this->preview_of((int) $category->id);

        $this->assertCount(1, $result['groups']);
        $group = $result['groups'][0];
        $this->assertSame((int) $category->id, $group['categoryid']);
        $this->assertSame($old . '-' . $new, $group['key']);
        $courses = array_column($group['courses'], null, 'courseid');
        $this->assertSame('Cálculo I copia 1', $courses[$new]['fullname']);
        $this->assertSame('CALC1_1', $courses[$new]['shortname']);
        $this->assertTrue($courses[$new]['keep']);
        $this->assertFalse($courses[$old]['keep']);
        $this->assertSame(['key', 'shortname'], $courses[$old]['reasons']);
        $this->assertSame(0, $result['folders'][0]['id']);
        $this->assertFalse($result['folders'][0]['visible']);
        $this->assertTrue($courses[$new]['visible']);
        $this->assertNotEmpty($result['hash']);
    }

    /**
     * Apply moves the courses that do not stay and records an undoable entry; the undo service restores them.
     */
    public function test_apply_and_undo(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$category, $old, $new] = $this->seeds();
        $preview = $this->preview_of((int) $category->id);

        $result = external_api::clean_returnvalue(
            dedupe_apply::execute_returns(),
            dedupe_apply::execute(
                [(int) $category->id],
                [['key' => $preview['groups'][0]['key'], 'keep' => $new]],
                $preview['hash']
            )
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['moved']);
        $this->assertGreaterThan(0, $result['logid']);
        $this->assertSame(1, $DB->count_records(journal::TABLE, ['action' => 'dedupe']));
        $folder = (int) $DB->get_field('course', 'category', ['id' => $old]);
        $this->assertSame((int) $category->id, (int) $DB->get_field('course_categories', 'parent', ['id' => $folder]));

        $undo = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($result['logid']));

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $category->id, (int) $DB->get_field('course', 'category', ['id' => $old]));
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $folder]));
    }

    /**
     * No groups sent: nothing is moved or recorded. A wrong hash is stale.
     */
    public function test_apply_nothing_and_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$category] = $this->seeds();
        $preview = $this->preview_of((int) $category->id);

        $none = dedupe_apply::execute([(int) $category->id], [], $preview['hash']);
        $stale = dedupe_apply::execute([(int) $category->id], [], sha1('other'));

        $this->assertSame(['status' => 'ok', 'logid' => 0, 'moved' => 0], $none);
        $this->assertSame(['status' => 'stale', 'logid' => 0, 'moved' => 0], $stale);
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Without the plugin capability neither service answers.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$category] = $this->seeds();
        $preview = $this->preview_of((int) $category->id);
        $this->setUser($this->getDataGenerator()->create_user());

        $calls = [
            static function () use ($category): void {
                dedupe_preview::execute([(int) $category->id]);
            },
            static function () use ($category, $preview): void {
                dedupe_apply::execute([(int) $category->id], [], $preview['hash']);
            },
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected required_capability_exception');
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string('tool/ceaorganizador:manage'), $e->a);
            }
        }
    }
}
