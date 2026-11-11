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
#[\PHPUnit\Framework\Attributes\CoversClass(clone_preview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(clone_apply::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(clone_status::class)]
/**
 * Tests for the cloning web services, against the test database.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\external\clone_preview
 * @covers     \tool_ceaorganizador\external\clone_apply
 * @covers     \tool_ceaorganizador\external\clone_status
 */
final class clone_external_test extends \advanced_testcase {
    /** @var int Start date used by the tests (2027-01-01). */
    private const START = 1798761600;

    /** @var int End date used by the tests. */
    private const END = 1814486400;

    /**
     * Runs the queued copies the way core's own copy tests do, with the trace output buffered.
     */
    private function run_copy_tasks(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $CFG->backup_error_log_logger_level = \backup::LOG_NONE;
        $CFG->backup_output_indented_logger_level = \backup::LOG_NONE;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $CFG->backup_database_logger_level = \backup::LOG_NONE;
        $CFG->backup_file_logger_level_extra = \backup::LOG_NONE;
        ob_start();
        try {
            while ($task = \core\task\manager::get_next_adhoc_task(time())) {
                $task->execute();
                \core\task\manager::adhoc_task_complete($task);
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Two seed courses in two programs and an empty period.
     *
     * @return array [root category, period category, course A, course B]
     */
    private function seeds(): array {
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $pa = $gen->create_category(['name' => 'Programa A', 'parent' => $root->id]);
        $pb = $gen->create_category(['name' => 'Programa B', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $a = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $pa->id]);
        $b = $gen->create_course(['fullname' => 'Física I', 'shortname' => 'FIS1', 'category' => $pb->id]);
        return [$root, $period, $a, $b];
    }

    /**
     * Previews a batch through the service and cleans the result.
     *
     * @param \stdClass $root
     * @param \stdClass $period
     * @return array
     */
    private function preview(\stdClass $root, \stdClass $period): array {
        $result = clone_preview::execute(
            [['type' => 'category', 'id' => (int) $root->id]],
            (int) $period->id,
            '2027-01',
            self::START,
            self::END
        );
        return external_api::clean_returnvalue(clone_preview::execute_returns(), $result);
    }

    /**
     * Applies the batch of a preview through the service and cleans the result.
     *
     * @param \stdClass $root
     * @param \stdClass $period
     * @param array $preview
     * @param array $rows Edited rows.
     * @return array
     */
    private function apply(\stdClass $root, \stdClass $period, array $preview, array $rows = []): array {
        $result = clone_apply::execute(
            [['type' => 'category', 'id' => (int) $root->id]],
            (int) $period->id,
            '2027-01',
            self::START,
            self::END,
            $rows,
            $preview['hash']
        );
        return external_api::clean_returnvalue(clone_apply::execute_returns(), $result);
    }

    /**
     * Status of a batch, cleaned.
     *
     * @param int $batchid
     * @return array
     */
    private function status(int $batchid): array {
        return external_api::clean_returnvalue(clone_status::execute_returns(), clone_status::execute($batchid));
    }

    /**
     * The preview is the plan, and its output survives the return definition.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period, $a, $b] = $this->seeds();

        $result = $this->preview($root, $period);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['rows']);
        $this->assertNotEmpty($result['hash']);
        $bycourse = array_column($result['rows'], null, 'courseid');
        $this->assertSame('CALC1-2027-01', $bycourse[$a->id]['newshortname']);
        $this->assertSame('Cálculo I 2027-01', $bycourse[$a->id]['newfullname']);
        $this->assertNull($bycourse[$a->id]['error']);
        $this->assertSame(0, $bycourse[$a->id]['destcategoryid']);
        $this->assertCount(2, $bycourse[$a->id]['newcategories']);
        $this->assertSame('FIS1-2027-01', $bycourse[$b->id]['newshortname']);
    }

    /**
     * A type that is not category or course is rejected.
     */
    public function test_preview_rejects_unknown_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $period, $a] = $this->seeds();

        $this->expectException(\invalid_parameter_exception::class);
        clone_preview::execute([['type' => 'user', 'id' => (int) $a->id]], (int) $period->id, '2027-01', self::START, self::END);
    }

    /**
     * Apply queues the copies, with the edited names, and the status reports them as pending.
     */
    public function test_apply_and_status(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period, $a, $b] = $this->seeds();
        $preview = $this->preview($root, $period);

        $rows = [['courseid' => (int) $a->id, 'newfullname' => 'Cálculo Uno', 'newshortname' => 'CALC-UNO']];
        $result = $this->apply($root, $period, $preview, $rows);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['queued']);
        $this->assertSame(0, $result['skipped']);
        $this->assertGreaterThan(0, $result['batchid']);

        $status = $this->status($result['batchid']);
        $this->assertSame(2, $status['pending']);
        $this->assertSame(0, $status['done']);
        $this->assertSame(0, $status['failed']);
        $bysource = array_column($status['rows'], null, 'source');
        $this->assertSame('Cálculo Uno', $bysource[$a->id]['newfullname']);
        $this->assertSame('pending', $bysource[$a->id]['status']);
        $this->assertGreaterThan(0, $bysource[$a->id]['newcourseid']);
        $this->assertSame(
            (new \moodle_url('/course/view.php', ['id' => $bysource[$a->id]['newcourseid']]))->out(false),
            $bysource[$a->id]['url']
        );
    }

    /**
     * A hash that no longer matches queues nothing.
     */
    public function test_apply_stale(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period] = $this->seeds();

        $result = $this->apply($root, $period, ['hash' => sha1('other')]);

        $this->assertSame(['status' => 'stale', 'batchid' => 0, 'queued' => 0, 'skipped' => 0], $result);
    }

    /**
     * Once the cron has run the copies, the status reports them as done, with their course.
     */
    public function test_status_progresses_after_running_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period, $a] = $this->seeds();
        $result = $this->apply($root, $period, $this->preview($root, $period));

        $before = $this->status($result['batchid']);
        $this->assertSame(2, $before['pending']);

        $this->run_copy_tasks();

        $after = $this->status($result['batchid']);
        $this->assertSame(0, $after['pending']);
        $this->assertSame(2, $after['done']);
        $this->assertSame(0, $after['failed']);
        foreach ($after['rows'] as $row) {
            $this->assertSame('done', $row['status']);
            $this->assertSame(1, $DB->count_records('course', ['id' => $row['newcourseid'], 'visible' => 0]));
        }
        $this->assertTrue($DB->record_exists('course', ['shortname' => 'CALC1-2027-01']));
    }

    /**
     * Without controllers the state is deduced from the course: done when it carries the expected name.
     */
    public function test_status_without_controllers(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period] = $this->seeds();
        $result = $this->apply($root, $period, $this->preview($root, $period));
        $items = json_decode($DB->get_field(journal::TABLE, 'payload', ['id' => $result['batchid']], MUST_EXIST), true)['items'];

        // Before the copy runs the provisional course has another name: the row has not finished.
        $DB->delete_records('backup_controllers');
        $this->assertSame(2, $this->status($result['batchid'])['failed']);

        // The course now carries the expected name for one of the items only.
        $DB->set_field('course', 'shortname', $items[0]['newshortname'], ['id' => $items[0]['newcourseid']]);
        $status = $this->status($result['batchid']);
        $this->assertSame(1, $status['done']);
        $this->assertSame(1, $status['failed']);
        $this->assertSame(0, $status['pending']);
        $this->assertSame('done', $status['rows'][0]['status']);
        $this->assertSame('failed', $status['rows'][1]['status']);
    }

    /**
     * Only clone batches can be queried.
     */
    public function test_status_rejects_other_actions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $logid = journal::record('sort', 'Sorted', ['categories' => [], 'courses' => []]);

        $this->expectException(\invalid_parameter_exception::class);
        clone_status::execute($logid);
    }

    /**
     * A batch that does not exist is rejected.
     */
    public function test_status_unknown_batch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        clone_status::execute(987654);
    }

    /**
     * Without the plugin capability none of the three services answers.
     */
    public function test_requires_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period] = $this->seeds();
        $preview = $this->preview($root, $period);
        $this->setUser($this->getDataGenerator()->create_user());

        $calls = [
                static function () use ($root, $period): void {
                    clone_preview::execute([['type' => 'category', 'id' => (int) $root->id]], (int) $period->id, '2027-01', 1, 2);
                },
                static function () use ($root, $period, $preview): void {
                    clone_apply::execute(
                        [['type' => 'category', 'id' => (int) $root->id]],
                        (int) $period->id,
                        '2027-01',
                        self::START,
                        self::END,
                        [],
                        $preview['hash']
                    );
                },
                static function (): void {
                    clone_status::execute(1);
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
     * A user with the plugin capability and the copy capabilities, but who cannot create courses in the
     * destination, cannot queue copies: nothing is written.
     */
    public function test_apply_requires_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$root, $period] = $this->seeds();

        $gen = $this->getDataGenerator();
        $user = $gen->create_user();
        $roleid = $gen->create_role();
        $system = \context_system::instance();
        $caps = array_merge(
            ['tool/ceaorganizador:manage'],
            \core_course\management\helper::get_course_copy_capabilities()
        );
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
        }
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);
        $preview = $this->preview($root, $period);
        $this->assertSame(2, $preview['total']);

        try {
            $this->apply($root, $period, $preview);
            $this->fail('Expected required_capability_exception');
        } catch (equired_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(journal::TABLE));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks('\core\task\asynchronous_copy_task'));
    }
}
