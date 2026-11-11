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
#[\PHPUnit\Framework\Attributes\CoversClass(clonerunner::class)]
/**
 * Tests for running a cloning batch.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\clonerunner
 */
final class clonerunner_test extends \advanced_testcase {
    /** @var int Start date used by the tests (2027-01-01). */
    private const START = 1798761600;

    /** @var int End date used by the tests. */
    private const END = 1814486400;

    /** @var string Adhoc task class of the course copies. */
    private const TASK = '\core\task\asynchronous_copy_task';

    /**
     * Previews and runs a batch with the hash of that preview.
     *
     * @param array $items
     * @param int $periodid
     * @param array|null $edits Rows sent, as clone_apply passes them; null sends every row of the preview unchanged.
     * @param string $container Folder under the period, or ''.
     * @return array Result of clonerunner::run().
     */
    private function run_batch(array $items, int $periodid, ?array $edits = null, string $container = ''): array {
        $plan = cloneplan::build($items, $periodid, '2027-01', self::START, self::END, $container);
        $edits = $edits ?? $this->all_rows($plan);
        return clonerunner::run($items, $periodid, '2027-01', self::START, self::END, $edits, $plan['hash'], $container);
    }

    /**
     * Every row of a preview, with its names unchanged, as the panel sends them.
     *
     * @param array $plan Result of cloneplan::build().
     * @return array Map courseid => names.
     */
    private function all_rows(array $plan): array {
        $edits = [];
        foreach ($plan['rows'] as $row) {
            $edits[$row['courseid']] = ['newfullname' => $row['newfullname'], 'newshortname' => $row['newshortname']];
        }
        return $edits;
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
     * Id of the child category with that name, or 0.
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
     * Silences the backup loggers, as the core copy tests do.
     */
    private function quiet_backup(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $CFG->backup_error_log_logger_level = \backup::LOG_NONE;
        $CFG->backup_output_indented_logger_level = \backup::LOG_NONE;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $CFG->backup_database_logger_level = \backup::LOG_NONE;
        $CFG->backup_file_logger_level_extra = \backup::LOG_NONE;
    }

    /**
     * Runs the queued copies the way core's own copy tests do, with the trace output buffered.
     */
    private function run_copy_tasks(): void {
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
     * One copy is queued per course, the missing categories are created once and the batch is recorded.
     */
    public function test_run_queues_one_task_per_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $sis = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $dos = $gen->create_category(['name' => 'Programa Dos', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $sis->id]);
        $fis = $gen->create_course(['fullname' => 'Física I', 'shortname' => 'FIS1', 'category' => $dos->id]);

        $result = $this->run_batch([['type' => 'category', 'id' => $root->id]], (int) $period->id);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['queued']);
        $this->assertSame(0, $result['skipped']);
        $this->assertGreaterThan(0, $result['batchid']);
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(self::TASK));

        // Without a container both programs hang from the period; the seed root is not copied.
        $newsis = $this->child((int) $period->id, 'Programa Sistemas');
        $newdos = $this->child((int) $period->id, 'Programa Dos');
        $this->assertSame(0, $this->child((int) $period->id, 'Facultades'));
        $this->assertGreaterThan(0, $newsis);
        $this->assertGreaterThan(0, $newdos);
        $this->assertSame(2, $DB->count_records('course_categories', ['parent' => $period->id]));

        $log = $DB->get_record(journal::TABLE, ['id' => $result['batchid']], '*', MUST_EXIST);
        $this->assertSame('clone', $log->action);
        $this->assertSame(
            get_string('summary_clone', 'tool_ceaorganizador', (object) ['label' => '2027-01', 'count' => 2]),
            $log->summary
        );
        $payload = $this->payload((int) $result['batchid']);
        $this->assertSame((int) $period->id, $payload['periodid']);
        $this->assertSame('2027-01', $payload['label']);
        $this->assertSame(self::START, $payload['startdate']);
        $this->assertSame(self::END, $payload['enddate']);
        $created = $payload['categories'];
        sort($created);
        $expected = [$newsis, $newdos];
        sort($expected);
        $this->assertSame($expected, $created);

        $bysource = array_column($payload['items'], null, 'source');
        $this->assertEqualsCanonicalizing([(int) $calc->id, (int) $fis->id], array_keys($bysource));
        $this->assertSame($newsis, $bysource[$calc->id]['destcategoryid']);
        $this->assertSame($newdos, $bysource[$fis->id]['destcategoryid']);
        $this->assertSame('CALC1-2027-01', $bysource[$calc->id]['newshortname']);
        foreach ($payload['items'] as $item) {
            $this->assertNull($item['error']);
            $this->assertNotEmpty($item['backupid']);
            $this->assertNotEmpty($item['restoreid']);
            // The provisional course already exists, hidden, in its destination.
            $course = $DB->get_record('course', ['id' => $item['newcourseid']], 'id, category, visible', MUST_EXIST);
            $this->assertSame($item['destcategoryid'], (int) $course->category);
            $this->assertSame(0, (int) $course->visible);
        }
    }

    /**
     * Edited names are checked again; a row that fails now is skipped and the others go on.
     */
    public function test_edits_are_revalidated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $other = $gen->create_category(['name' => 'Otros']);
        $a = $gen->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $root->id]);
        $b = $gen->create_course(['fullname' => 'B', 'shortname' => 'B', 'category' => $root->id]);
        $gen->create_course(['fullname' => 'Ocupado', 'shortname' => 'TAKEN', 'category' => $other->id]);

        $edits = [
            $a->id => ['newfullname' => 'A 2027-01', 'newshortname' => 'TAKEN'],
            $b->id => ['newfullname' => '  Nuevo B  ', 'newshortname' => 'B-NUEVO'],
        ];
        $result = $this->run_batch([['type' => 'category', 'id' => $root->id]], (int) $period->id, $edits);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $payload = $this->payload((int) $result['batchid']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame((int) $b->id, $payload['items'][0]['source']);
        $this->assertSame('Nuevo B', $payload['items'][0]['newfullname']);
        $this->assertSame('B-NUEVO', $payload['items'][0]['newshortname']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::TASK));
        $this->assertFalse($DB->record_exists('course', ['shortname' => 'A-2027-01']));
    }

    /**
     * The rows sent are authoritative: a preview row that was not sent is not queued, and its short name
     * does not make a sent row a duplicate.
     */
    public function test_row_not_sent_is_not_queued(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $a = $gen->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $root->id]);
        $gen->create_course(['fullname' => 'B', 'shortname' => 'B', 'category' => $root->id]);

        // Only A is sent, with the short name the preview proposed for B.
        $edits = [$a->id => ['newfullname' => 'A 2027-01', 'newshortname' => 'B-2027-01']];
        $result = $this->run_batch([['type' => 'category', 'id' => $root->id]], (int) $period->id, $edits);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $payload = $this->payload((int) $result['batchid']);
        $this->assertSame([(int) $a->id], array_column($payload['items'], 'source'));
        $this->assertSame('B-2027-01', $payload['items'][0]['newshortname']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::TASK));
        $this->assertSame(
            get_string('summary_clone', 'tool_ceaorganizador', (object) ['label' => '2027-01', 'count' => 1]),
            $DB->get_field(journal::TABLE, 'summary', ['id' => $result['batchid']])
        );
    }

    /**
     * No row sent: nothing is queued and nothing is recorded.
     */
    public function test_no_row_sent_queues_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $gen->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $prog->id]);
        $categories = $DB->count_records('course_categories');

        $result = $this->run_batch([['type' => 'category', 'id' => $root->id]], (int) $period->id, []);

        $this->assertSame(['status' => 'ok', 'batchid' => 0, 'queued' => 0, 'skipped' => 1], $result);
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(self::TASK));
    }

    /**
     * Rows sent with the names of the preview, unchanged, are all queued with those names.
     */
    public function test_sent_rows_with_unchanged_names_are_queued(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $a = $gen->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $root->id]);
        $b = $gen->create_course(['fullname' => 'B', 'shortname' => 'B', 'category' => $root->id]);
        $items = [['type' => 'category', 'id' => $root->id]];
        $plan = cloneplan::build($items, (int) $period->id, '2027-01', self::START, self::END);

        $result = clonerunner::run(
            $items,
            (int) $period->id,
            '2027-01',
            self::START,
            self::END,
            $this->all_rows($plan),
            $plan['hash']
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['queued']);
        $this->assertSame(0, $result['skipped']);
        $bysource = array_column($this->payload((int) $result['batchid'])['items'], null, 'source');
        $this->assertSame('A-2027-01', $bysource[$a->id]['newshortname']);
        $this->assertSame('B 2027-01', $bysource[$b->id]['newfullname']);
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(self::TASK));
    }

    /**
     * A hash that no longer matches writes nothing.
     */
    public function test_run_stale_hash(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $prog->id]);
        $categories = $DB->count_records('course_categories');
        $courses = $DB->count_records('course');

        $items = [['type' => 'course', 'id' => $calc->id]];
        $result = clonerunner::run($items, (int) $period->id, '2027-01', self::START, self::END, [], sha1('other'));

        $this->assertSame(['status' => 'stale', 'batchid' => 0, 'queued' => 0, 'skipped' => 0], $result);
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame($courses, $DB->count_records('course'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(self::TASK));
    }

    /**
     * The copy, once run, is hidden, renamed, dated, without users and with the activities of the seed.
     */
    public function test_end_to_end_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->quiet_backup();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course([
            'fullname' => 'Cálculo I 2026-02',
            'shortname' => 'CALC1-2026-02',
            'category' => $prog->id,
            'startdate' => self::START - 200 * DAYSECS,
        ]);
        $page = $gen->create_module('page', ['course' => $calc->id, 'name' => 'Bienvenida']);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $calc->id, 'student');

        $result = $this->run_batch([['type' => 'course', 'id' => $calc->id]], (int) $period->id);
        $this->assertSame(1, $result['queued']);
        $newid = (int) $this->payload((int) $result['batchid'])['items'][0]['newcourseid'];

        $this->run_copy_tasks();

        $course = $DB->get_record('course', ['id' => $newid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $course->visible);
        $this->assertSame('Cálculo I 2027-01', $course->fullname);
        $this->assertSame('CALC1-2027-01', $course->shortname);
        $this->assertSame(self::START, (int) $course->startdate);
        $this->assertSame(self::END, (int) $course->enddate);
        $this->assertSame('', (string) $course->idnumber);
        $destination = $this->child((int) $period->id, 'Programa Sistemas');
        $this->assertSame($destination, (int) $course->category);

        $pages = get_fast_modinfo($newid)->get_instances_of('page');
        $this->assertCount(1, $pages);
        $this->assertSame($page->name, reset($pages)->name);
        $this->assertSame(0, count_enrolled_users(\context_course::instance($newid)));
        // The seed keeps its student.
        $this->assertSame(1, count_enrolled_users(\context_course::instance($calc->id)));
    }

    /**
     * A clone batch cannot be undone.
     */
    public function test_undo_clone_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $root->id]);
        $result = $this->run_batch([['type' => 'course', 'id' => $calc->id]], (int) $period->id);

        try {
            journal::undo((int) $result['batchid']);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_cannotundoclone', $e->errorcode);
        }
        $this->assertSame(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['batchid']]));
        // Nothing was deleted: the provisional course (renamed only when the cron runs the copy) is still there.
        $newid = (int) $this->payload((int) $result['batchid'])['items'][0]['newcourseid'];
        $this->assertGreaterThan(0, $newid);
        $this->assertTrue($DB->record_exists('course', ['id' => $newid]));
    }

    /**
     * A copy that fails after it was created rolls back whole: no provisional course, controllers or task
     * for that row, and the other rows are queued.
     */
    public function test_failed_row_leaves_no_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $pa = $gen->create_category(['name' => 'Programa A', 'parent' => $root->id]);
        $pb = $gen->create_category(['name' => 'Programa B', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $a = $gen->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $pa->id]);
        $b = $gen->create_course(['fullname' => 'B', 'shortname' => 'B', 'category' => $pb->id]);

        // Fails right after create_copy() has written the provisional course, the controllers and the task.
        $runner = new class extends clonerunner {
            /** @var int Source course whose copy fails. */
            public static $failsource = 0;

            /**
             * Queues the copy, then fails for one source.
             *
             * @param array $item
             * @param int $startdate
             * @param int $enddate
             * @return array
             */
            protected static function queue(array $item, int $startdate, int $enddate): array {
                $ids = parent::queue($item, $startdate, $enddate);
                if ((int) $item['source'] === self::$failsource) {
                    throw new \moodle_exception('error_generic', 'tool_ceaorganizador');
                }
                return $ids;
            }
        };
        $runner::$failsource = (int) $b->id;
        $courses = $DB->count_records('course');

        $items = [['type' => 'category', 'id' => $root->id]];
        $plan = cloneplan::build($items, (int) $period->id, '2027-01', self::START, self::END);
        $edits = $this->all_rows($plan);
        $result = $runner::run($items, (int) $period->id, '2027-01', self::START, self::END, $edits, $plan['hash']);
        $this->assertDebuggingCalled();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $bysource = array_column($this->payload((int) $result['batchid'])['items'], null, 'source');
        $failed = $bysource[$b->id];
        $this->assertSame('queuefailed', $failed['error']);
        // The cause is kept, short, for the administrator.
        $this->assertMatchesRegularExpression('/(^|\\\\)moodle_exception: /', $failed['reason']);
        $this->assertLessThanOrEqual(255, \core_text::strlen($failed['reason']));
        $this->assertArrayNotHasKey('reason', $bysource[$a->id]);
        $this->assertSame(0, $failed['newcourseid']);
        $this->assertSame('', $failed['restoreid']);
        $this->assertGreaterThan(0, $failed['destcategoryid']);
        $this->assertSame(0, $DB->count_records('course', ['category' => $failed['destcategoryid']]));
        $this->assertNull($bysource[$a->id]['error']);
        $this->assertTrue($DB->record_exists('course', ['id' => $bysource[$a->id]['newcourseid']]));
        $this->assertSame($courses + 1, $DB->count_records('course'));
        $this->assertSame(2, $DB->count_records('backup_controllers'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::TASK));
        $this->assertSame(
            get_string('summary_clone', 'tool_ceaorganizador', (object) ['label' => '2027-01', 'count' => 1]),
            $DB->get_field(journal::TABLE, 'summary', ['id' => $result['batchid']])
        );
    }

    /**
     * Copies still waiting for the cron count as already cloned: a second run queues nothing.
     */
    public function test_inflight_copies_are_already_cloned(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $prog = $gen->create_category(['name' => 'Programa Sistemas', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $prog->id]);
        $items = [['type' => 'course', 'id' => $calc->id]];

        $first = $this->run_batch($items, (int) $period->id);
        $this->assertSame(1, $first['queued']);
        // The cron has not run: the new course is still the provisional one, without its short name.
        $this->assertFalse($DB->record_exists('course', ['shortname' => 'CALC1-2027-01']));

        $plan = cloneplan::build($items, (int) $period->id, '2027-01', self::START, self::END);
        $this->assertSame('alreadycloned', $plan['rows'][0]['error']);

        $second = $this->run_batch($items, (int) $period->id);
        $this->assertSame(['status' => 'ok', 'batchid' => 0, 'queued' => 0, 'skipped' => 1], $second);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::TASK));
        $this->assertSame(1, $DB->count_records(journal::TABLE));
    }

    /**
     * A parent without moodle/category:manage stops the whole batch: no category, no copy, no journal row.
     */
    public function test_category_creation_rolls_back_on_error(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $pa = $gen->create_category(['name' => 'Programa A', 'parent' => $root->id]);
        $pb = $gen->create_category(['name' => 'Programa B', 'parent' => $root->id]);
        $sub = $gen->create_category(['name' => 'Sub', 'parent' => $pb->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $periodroot = $gen->create_category(['name' => 'Facultades', 'parent' => $period->id]);
        $periodpb = $gen->create_category(['name' => 'Programa B', 'parent' => $periodroot->id]);
        $gen->create_course(['fullname' => 'A', 'shortname' => 'A', 'category' => $pa->id]);
        $gen->create_course(['fullname' => 'B', 'shortname' => 'B', 'category' => $sub->id]);

        // Allowed everywhere, except managing categories inside the period's «Programa B».
        $user = $gen->create_user();
        $roleid = $gen->create_role();
        $system = \context_system::instance();
        $caps = [
            'tool/ceaorganizador:manage',
            'moodle/backup:backupcourse',
            'moodle/restore:restorecourse',
            'moodle/course:create',
            'moodle/category:manage',
        ];
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $system->id, true);
        }
        assign_capability('moodle/category:manage', CAP_PROHIBIT, $roleid, \context_coursecat::instance($periodpb->id)->id, true);
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);

        $categories = $DB->count_records('course_categories');
        $courses = $DB->count_records('course');
        try {
            $this->run_batch([['type' => 'category', 'id' => $root->id]], (int) $period->id, null, 'Facultades');
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/category:manage'), $e->a);
        }

        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(0, $this->child((int) $periodroot->id, 'Programa A'));
        $this->assertSame($courses, $DB->count_records('course'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(self::TASK));
        // The lock was released: another run can take it.
        guard::lock()->release();
    }

    /**
     * A missing container is created once under the period, last among its siblings, and holds the mirrored paths.
     */
    public function test_container_creates_folder(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $eng = $gen->create_category(['name' => 'Facultad de Ingeniería', 'parent' => $root->id]);
        $law = $gen->create_category(['name' => 'Facultad de Derecho', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $other = $gen->create_category(['name' => 'Otros', 'parent' => $period->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $eng->id]);
        $penal = $gen->create_course(['fullname' => 'Penal I', 'shortname' => 'PEN1', 'category' => $law->id]);
        $intro = $gen->create_course(['fullname' => 'Inducción', 'shortname' => 'IND', 'category' => $root->id]);

        $result = $this->run_batch([['type' => 'category', 'id' => $root->id]], (int) $period->id, null, 'Facultades');
        $this->assertSame(3, $result['queued']);

        $folder = $this->child((int) $period->id, 'Facultades');
        $this->assertGreaterThan(0, $folder);
        $children = array_map('intval', array_keys(
            $DB->get_records('course_categories', ['parent' => $period->id], 'sortorder ASC', 'id')
        ));
        $this->assertSame([(int) $other->id, $folder], $children);
        $neweng = $this->child($folder, 'Facultad de Ingeniería');
        $newlaw = $this->child($folder, 'Facultad de Derecho');
        $this->assertGreaterThan(0, $neweng);
        $this->assertGreaterThan(0, $newlaw);

        $payload = $this->payload((int) $result['batchid']);
        $created = $payload['categories'];
        sort($created);
        $expected = [$folder, $neweng, $newlaw];
        sort($expected);
        $this->assertSame($expected, $created);
        $bysource = array_column($payload['items'], null, 'source');
        $this->assertSame($neweng, $bysource[$calc->id]['destcategoryid']);
        $this->assertSame($newlaw, $bysource[$penal->id]['destcategoryid']);
        // The course directly in the seed root goes to the container itself.
        $this->assertSame($folder, $bysource[$intro->id]['destcategoryid']);
    }

    /**
     * An existing folder that differs only in accents or case is reused: no duplicate.
     */
    public function test_container_existing_accent_insensitive(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $eng = $gen->create_category(['name' => 'Facultad de Ingeniería', 'parent' => $root->id]);
        $period = $gen->create_category(['name' => '2027-01']);
        $existing = $gen->create_category(['name' => 'FACULTADES', 'parent' => $period->id]);
        $periodeng = $gen->create_category(['name' => 'Facultad de Ingenieria', 'parent' => $existing->id]);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $eng->id]);
        $categories = $DB->count_records('course_categories');

        $items = [['type' => 'course', 'id' => $calc->id]];
        $plan = cloneplan::build($items, (int) $period->id, '2027-01', self::START, self::END, 'Facultades');
        $this->assertSame((int) $periodeng->id, $plan['rows'][0]['destcategoryid']);
        $this->assertSame([], $plan['rows'][0]['newcategories']);

        $result = $this->run_batch($items, (int) $period->id, null, 'Facultades');
        $this->assertSame(1, $result['queued']);
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(1, $DB->count_records('course_categories', ['parent' => $period->id]));
        $payload = $this->payload((int) $result['batchid']);
        $this->assertSame([], $payload['categories']);
        $this->assertSame((int) $periodeng->id, $payload['items'][0]['destcategoryid']);
    }

    /**
     * A preview made with one container is stale for a run with another one.
     */
    public function test_container_change_is_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $root = $gen->create_category(['name' => 'Facultades']);
        $period = $gen->create_category(['name' => '2027-01']);
        $calc = $gen->create_course(['fullname' => 'Cálculo I', 'shortname' => 'CALC1', 'category' => $root->id]);
        $items = [['type' => 'course', 'id' => $calc->id]];

        $plan = cloneplan::build($items, (int) $period->id, '2027-01', self::START, self::END);
        $edits = $this->all_rows($plan);
        $result = clonerunner::run($items, (int) $period->id, '2027-01', self::START, self::END, $edits, $plan['hash'], 'Otra');
        $this->assertSame(['status' => 'stale', 'batchid' => 0, 'queued' => 0, 'skipped' => 0], $result);
        $this->assertSame(0, $DB->count_records('course_categories', ['parent' => $period->id]));
    }
}
