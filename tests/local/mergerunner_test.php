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
#[\PHPUnit\Framework\Attributes\CoversClass(mergerunner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(journal::class)]
/**
 * Tests for running and undoing a merge of seed categories.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\mergerunner
 * @covers     \tool_ceaorganizador\local\journal
 */
final class mergerunner_test extends \advanced_testcase {
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
     * @return int
     */
    private function course(string $fullname, \core_course_category $category, int $timemodified = 0): int {
        global $DB;
        static $n = 0;
        $n++;
        $course = $this->getDataGenerator()->create_course([
            'fullname' => $fullname,
            'shortname' => 'MR' . $n,
            'category' => $category->id,
        ]);
        if ($timemodified > 0) {
            $DB->set_field('course', 'timemodified', $timemodified, ['id' => $course->id]);
        }
        return (int) $course->id;
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
     * Previews and applies a merge with the hash of that preview.
     *
     * @param \core_course_category $target
     * @param \core_course_category[] $sources
     * @param array $rows Rows sent: [['courseid' => int, 'action' => 'move'|'repeated'|'skip']].
     * @return array Result of mergerunner::run().
     */
    private function apply_merge(\core_course_category $target, array $sources, array $rows = []): array {
        $sourceids = array_map(static function (\core_course_category $c): int {
            return (int) $c->id;
        }, $sources);
        $plan = mergeplan::build((int) $target->id, $sourceids);
        return mergerunner::run((int) $target->id, $sourceids, $rows, $plan['hash']);
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
     * The courses are moved to the mirrored path; the missing categories are created once; it is recorded.
     */
    public function test_run_moves_and_creates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $facultades = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $facultades);
        $sem1 = $this->cat('Semestre I', $ing);
        $a = $this->course('Cálculo I', $sem1);
        $b = $this->course('Física I', $sem1);
        $target = $this->cat('Semillas');
        // Same faculty with other accents: it is reused, not repeated.
        $existing = $this->cat('Ingenieria', $target);

        $result = $this->apply_merge($target, [$facultades]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['moved']);
        $this->assertSame(0, $result['skipped']);
        $this->assertGreaterThan(0, $result['logid']);
        $new = $this->child((int) $existing->id, 'Semestre I');
        $this->assertGreaterThan(0, $new);
        $this->assertSame(1, $DB->count_records('course_categories', ['parent' => $target->id]));
        $this->assertSame($new, $this->category_of($a));
        $this->assertSame($new, $this->category_of($b));

        $log = $DB->get_record(journal::TABLE, ['id' => $result['logid']], '*', MUST_EXIST);
        $this->assertSame('merge', $log->action);
        $this->assertSame(
            get_string('summary_merge', 'tool_ceaorganizador', (object) ['target' => 'Semillas', 'count' => 2]),
            $log->summary
        );
        $payload = $this->payload((int) $result['logid']);
        $this->assertSame((int) $target->id, $payload['targetid']);
        $this->assertSame([(int) $facultades->id], $payload['sourceids']);
        $this->assertSame([$new], $payload['created']);
        $items = array_column($payload['items'], null, 'id');
        $this->assertEqualsCanonicalizing([$a, $b], array_keys($items));
        $this->assertSame((int) $sem1->id, $items[$a]['fromcategory']);
        $this->assertSame($new, $items[$a]['tocategory']);

        // The source tree is left empty, deepest first.
        $this->assertSame(
            [(int) $sem1->id, (int) $ing->id, (int) $facultades->id],
            array_column($result['emptied'], 'id')
        );
    }

    /**
     * By default an older repeated course goes to a hidden «Repetidos» folder created under the target, and is
     * hidden; the others move; undo brings it back visible and deletes the folder.
     */
    public function test_run_sends_repeated_to_hidden_folder(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $dup = $this->course('Cálculo I', $ing, 1000);
        $other = $this->course('Física I', $ing);
        $target = $this->cat('Semillas');
        $ting = $this->cat('Ingeniería', $target);
        $kept = $this->course('Cálculo I', $ting, 2000);

        $result = $this->apply_merge($target, [$source]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['moved']);
        $this->assertSame(1, $result['repeated']);
        $this->assertSame(0, $result['skipped']);
        $folder = $this->child((int) $target->id, duplicates::FOLDER);
        $this->assertGreaterThan(0, $folder);
        $this->assertSame(0, $this->visible('course_categories', $folder));
        $this->assertSame($folder, $this->category_of($dup));
        $this->assertSame(0, $this->visible('course', $dup));
        $this->assertSame((int) $ting->id, $this->category_of($other));
        $this->assertSame((int) $ting->id, $this->category_of($kept));
        $this->assertSame([(int) $ing->id, (int) $source->id], array_column($result['emptied'], 'id'));
        $payload = $this->payload((int) $result['logid']);
        $this->assertSame([$folder], $payload['created']);
        $items = array_column($payload['items'], null, 'id');
        $this->assertSame(1, $items[$dup]['fromvisible']);
        $this->assertSame(0, $items[$dup]['tovisible']);

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $ing->id, $this->category_of($dup));
        $this->assertSame(1, $this->visible('course', $dup));
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $folder]));
    }

    /**
     * A repeated course sent with 'move' is moved to the destination next to the other one.
     */
    public function test_run_override_moves_duplicate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $dup = $this->course('Cálculo I', $ing, 1000);
        $target = $this->cat('Semillas');
        $ting = $this->cat('Ingeniería', $target);
        $this->course('Cálculo I', $ting, 2000);

        $result = $this->apply_merge($target, [$source], [['courseid' => $dup, 'action' => 'move']]);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(0, $result['repeated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame((int) $ting->id, $this->category_of($dup));
        $this->assertSame(0, $this->child((int) $target->id, duplicates::FOLDER));
        $this->assertSame([(int) $ing->id, (int) $source->id], array_column($result['emptied'], 'id'));
    }

    /**
     * A target course that loses to a newer source course goes to the existing «Repetidos» folder, which is
     * reused; undo brings it back and keeps the folder, which the merge did not create.
     */
    public function test_run_existing_target_course_loses(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $new = $this->course('Cálculo I 2026-1', $ing, 2000);
        $target = $this->cat('Semillas');
        $ting = $this->cat('Ingeniería', $target);
        $folder = $this->cat(duplicates::FOLDER, $target);
        $folder->hide();
        $old = $this->course('Cálculo I', $ting, 1000);
        $categories = $DB->count_records('course_categories');

        $result = $this->apply_merge($target, [$source]);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(1, $result['repeated']);
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame((int) $ting->id, $this->category_of($new));
        $this->assertSame((int) $folder->id, $this->category_of($old));
        $this->assertSame(0, $this->visible('course', $old));
        $items = array_column($this->payload((int) $result['logid'])['items'], null, 'id');
        $this->assertSame((int) $ting->id, $items[$old]['fromcategory']);
        $this->assertSame((int) $folder->id, $items[$old]['tocategory']);

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $ting->id, $this->category_of($old));
        $this->assertSame(1, $this->visible('course', $old));
        $this->assertSame((int) $ing->id, $this->category_of($new));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $folder->id]));
    }

    /**
     * Repeated courses from two sources: the newest goes to the destination, the other to Repetidos; a course
     * left in the source with 'skip' stays and keeps its category from being reported empty.
     */
    public function test_run_winner_across_sources_and_leave_in_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = $this->cat('Facultades');
        $second = $this->cat('Semillas viejas');
        $ing1 = $this->cat('Ingeniería', $first);
        $ing2 = $this->cat('Ingeniería', $second);
        $old = $this->course('Cálculo I', $ing1, 1000);
        $new = $this->course('Cálculo I (copia)', $ing2, 2000);
        $stay = $this->course('Física I', $ing1);
        $target = $this->cat('Semillas');

        $result = $this->apply_merge($target, [$first, $second], [['courseid' => $stay, 'action' => 'skip']]);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(1, $result['repeated']);
        $this->assertSame(1, $result['skipped']);
        $newing = $this->child((int) $target->id, 'Ingeniería');
        $folder = $this->child((int) $target->id, duplicates::FOLDER);
        $this->assertSame($newing, $this->category_of($new));
        $this->assertSame($folder, $this->category_of($old));
        $this->assertSame((int) $ing1->id, $this->category_of($stay));
        $this->assertSame([(int) $ing2->id, (int) $second->id], array_column($result['emptied'], 'id'));
    }

    /**
     * An action that is not move or skip, or a course outside the merge, is rejected before any write.
     */
    public function test_run_rejects_invalid_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $a = $this->course('A', $source);
        $target = $this->cat('Semillas');

        try {
            $this->apply_merge($target, [$source], [['courseid' => $a, 'action' => 'delete']]);
            $this->fail('An invalid action must be rejected');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame((int) $source->id, $this->category_of($a));
        }
        $this->expectException(\invalid_parameter_exception::class);
        $this->apply_merge($target, [$source], [['courseid' => $a + 1000, 'action' => 'move']]);
    }

    /**
     * A preview that no longer matches returns 'stale' and writes nothing.
     */
    public function test_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $a = $this->course('A', $source);
        $target = $this->cat('Semillas');
        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);
        // A course added after the preview changes the plan.
        $this->course('B', $source);

        $result = mergerunner::run((int) $target->id, [(int) $source->id], [], $plan['hash']);

        $this->assertSame('stale', $result['status']);
        $this->assertSame(0, $result['logid']);
        $this->assertSame((int) $source->id, $this->category_of($a));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * Without every capability nothing is created, moved or recorded.
     */
    public function test_requires_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $a = $this->course('A', $ing);
        $target = $this->cat('Semillas');
        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);
        $categories = $DB->count_records('course_categories');

        // Manages every category but cannot change the category of a course.
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $system = \context_system::instance();
        assign_capability('moodle/category:manage', CAP_ALLOW, $roleid, $system->id);
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);
        try {
            mergerunner::run((int) $target->id, [(int) $source->id], [], $plan['hash']);
            $this->fail('moodle/course:changecategory must be required');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/course:changecategory'), $e->a);
        }

        // Can move courses and manage the source, but not the target.
        $other = $this->getDataGenerator()->create_user();
        $partial = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:changecategory', CAP_ALLOW, $partial, $system->id);
        assign_capability('moodle/category:manage', CAP_ALLOW, $partial, \context_coursecat::instance($source->id)->id);
        role_assign($partial, $other->id, $system->id);
        $this->setUser($other);
        try {
            mergerunner::run((int) $target->id, [(int) $source->id], [], $plan['hash']);
            $this->fail('moodle/category:manage on the target must be required');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('moodle/category:manage'), $e->a);
        }

        $this->assertSame((int) $ing->id, $this->category_of($a));
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * When every course stays, nothing is recorded.
     */
    public function test_nothing_to_move(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $a = $this->course('A', $source);
        $target = $this->cat('Semillas');

        $result = $this->apply_merge($target, [$source], [['courseid' => $a, 'action' => 'skip']]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['logid']);
        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $DB->count_records(journal::TABLE));
        $this->assertSame((int) $source->id, $this->category_of($a));
    }

    /**
     * Undo moves every course back and deletes the categories the merge created.
     */
    public function test_undo_restores_and_deletes_created(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $sem1 = $this->cat('Semestre I', $ing);
        $a = $this->course('A', $sem1);
        $b = $this->course('B', $ing);
        $target = $this->cat('Semillas');
        $result = $this->apply_merge($target, [$source]);
        $this->assertCount(2, $this->payload((int) $result['logid'])['created']);

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame([], $undo['conflicts']);
        $this->assertSame((int) $sem1->id, $this->category_of($a));
        $this->assertSame((int) $ing->id, $this->category_of($b));
        $this->assertSame(0, $DB->count_records('course_categories', ['parent' => $target->id]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $target->id]));
        $this->assertGreaterThan(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * Undo keeps a created category that received other content after the merge, and deletes the empty ones.
     */
    public function test_undo_keeps_nonempty_created_category(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $sem1 = $this->cat('Semestre I', $ing);
        $sem2 = $this->cat('Semestre II', $ing);
        $a = $this->course('A', $sem1);
        $b = $this->course('B', $sem2);
        $target = $this->cat('Semillas');
        $result = $this->apply_merge($target, [$source]);
        $newing = $this->child((int) $target->id, 'Ingeniería');
        $newsem1 = $this->child($newing, 'Semestre I');
        $newsem2 = $this->child($newing, 'Semestre II');
        // Someone adds a course to a created category after the merge.
        $c = $this->course('C', \core_course_category::get($newsem1, MUST_EXIST, true));

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $sem1->id, $this->category_of($a));
        $this->assertSame((int) $sem2->id, $this->category_of($b));
        $this->assertSame($newsem1, $this->category_of($c));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newsem1]));
        // Its parent has a subcategory, so it is kept too; the empty sibling is deleted.
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newing]));
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $newsem2]));
    }

    /**
     * Undo keeps a created category whose recycle bin holds a course deleted after the merge.
     */
    public function test_undo_keeps_created_category_with_recycle_bin_items(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!$DB->get_manager()->table_exists('tool_recyclebin_category')) {
            $this->markTestSkipped('The recycle bin is not installed');
        }
        // The recycle bin backs up the course before deleting it; quiet loggers, as the core tests do.
        $CFG->backup_error_log_logger_level = \backup::LOG_NONE;
        $CFG->backup_output_indented_logger_level = \backup::LOG_NONE;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $CFG->backup_database_logger_level = \backup::LOG_NONE;
        $CFG->backup_file_logger_level_extra = \backup::LOG_NONE;
        set_config('categorybinenable', 1, 'tool_recyclebin');
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $a = $this->course('A', $ing);
        $target = $this->cat('Semillas');
        $result = $this->apply_merge($target, [$source]);
        $newing = $this->child((int) $target->id, 'Ingeniería');
        // A course is created in the new category and deleted: its backup waits in the category bin.
        $c = $this->course('C', \core_course_category::get($newing, MUST_EXIST, true));
        ob_start();
        try {
            delete_course($c, false);
        } finally {
            ob_end_clean();
        }
        $this->assertTrue($DB->record_exists('tool_recyclebin_category', ['categoryid' => $newing]));

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $ing->id, $this->category_of($a));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newing]));
        $this->assertTrue($DB->record_exists('tool_recyclebin_category', ['categoryid' => $newing]));
    }

    /**
     * Undo keeps a created category with any question category in its context, an empty «top» too.
     */
    public function test_undo_keeps_created_category_with_questions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $sem1 = $this->cat('Semestre I', $ing);
        $sem2 = $this->cat('Semestre II', $ing);
        $sem3 = $this->cat('Semestre III', $ing);
        $this->course('A', $sem1);
        $this->course('B', $sem2);
        $this->course('C', $sem3);
        $target = $this->cat('Semillas');
        $result = $this->apply_merge($target, [$source]);
        $newing = $this->child((int) $target->id, 'Ingeniería');
        $newsem1 = $this->child($newing, 'Semestre I');
        $newsem2 = $this->child($newing, 'Semestre II');
        $newsem3 = $this->child($newing, 'Semestre III');
        // Inserted directly: question banks in category contexts differ between Moodle versions.
        $top1 = $this->question_category(\context_coursecat::instance($newsem1)->id, 'top', 0);
        $this->question_category(\context_coursecat::instance($newsem1)->id, 'Banco', $top1);
        $this->question_category(\context_coursecat::instance($newsem2)->id, 'top', 0);

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newsem1]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newing]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newsem2]));
        // Without any question category it is deleted.
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $newsem3]));
    }

    /**
     * Inserts a question category.
     *
     * @param int $contextid
     * @param string $name
     * @param int $parent 0 for the top category of the context.
     * @return int
     */
    private function question_category(int $contextid, string $name, int $parent): int {
        global $DB;
        return (int) $DB->insert_record('question_categories', (object) [
            'name' => $name,
            'contextid' => $contextid,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => $parent,
            'sortorder' => 999,
        ]);
    }

    /**
     * Undo is refused, and writes nothing, when a course moved since or its old category is gone.
     */
    public function test_undo_conflict(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $sem1 = $this->cat('Semestre I', $ing);
        $a = $this->course('Curso A', $ing);
        $b = $this->course('Curso B', $sem1);
        $target = $this->cat('Semillas');
        $elsewhere = $this->cat('Otra');
        $result = $this->apply_merge($target, [$source]);
        $newing = $this->child((int) $target->id, 'Ingeniería');
        $newsem1 = $this->child($newing, 'Semestre I');
        // A moves on; the old category of B is deleted.
        move_courses([$a], (int) $elsewhere->id);
        \core_course_category::get((int) $sem1->id, MUST_EXIST, true)->delete_full(false);

        $undo = journal::undo((int) $result['logid']);

        $this->assertFalse($undo['ok']);
        $this->assertContains(get_string('conflict_moved', 'tool_ceaorganizador', 'Curso A'), $undo['conflicts']);
        $this->assertContains(get_string('conflict_targetmissing', 'tool_ceaorganizador', 'Curso B'), $undo['conflicts']);
        $this->assertSame((int) $elsewhere->id, $this->category_of($a));
        $this->assertSame($newsem1, $this->category_of($b));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $newing]));
        $this->assertSame(0, (int) $DB->get_field(journal::TABLE, 'timeundone', ['id' => $result['logid']]));
    }

    /**
     * A course hidden by moving it into a hidden target is shown again by the undo.
     */
    public function test_undo_restores_visibility(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $a = $this->course('A', $source);
        $target = $this->cat('Semillas');
        $target->hide();
        $result = $this->apply_merge($target, [$source]);
        $this->assertSame(0, (int) $DB->get_field('course', 'visible', ['id' => $a]));

        $undo = journal::undo((int) $result['logid']);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $source->id, $this->category_of($a));
        $this->assertSame(1, (int) $DB->get_field('course', 'visible', ['id' => $a]));
    }

    /**
     * A failure while recording the journal row rolls back the categories: no category, move or row is left.
     */
    public function test_failure_rolls_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $a = $this->course('A', $ing);
        $target = $this->cat('Semillas');
        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);
        $categories = $DB->count_records('course_categories');
        // Fails at the last step of the first transaction, after the categories are created.
        $runner = new class extends mergerunner {
            /**
             * Fails instead of recording.
             *
             * @param array $payload
             * @return int
             */
            protected static function record_batch(array $payload): int {
                throw new \coding_exception('Simulated failure');
            }
        };

        try {
            $runner::run((int) $target->id, [(int) $source->id], [], $plan['hash']);
            $this->fail('The failure must reach the caller');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Simulated failure', $e->getMessage());
        }

        $this->assertSame((int) $ing->id, $this->category_of($a));
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));
    }

    /**
     * A failure in a later chunk rolls back only that chunk: what moved before is in the journal row and undoes.
     */
    public function test_chunk_failure_keeps_what_was_recorded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $derecho = $this->cat('Derecho', $source);
        $ing = $this->cat('Ingeniería', $source);
        $law = $this->course('Penal', $derecho);
        $calc = $this->course('Cálculo', $ing);
        $target = $this->cat('Semillas');
        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);
        $categories = $DB->count_records('course_categories');
        // One chunk per destination, in path order: Derecho first, then Ingeniería, which fails.
        $runner = new class extends mergerunner {
            /** @var int Chunks recorded so far. */
            private static $chunks = 0;

            /**
             * Fails on the second chunk.
             *
             * @param int $logid
             * @param array $payload
             */
            protected static function update_batch(int $logid, array $payload): void {
                self::$chunks++;
                if (self::$chunks === 2) {
                    throw new \coding_exception('Simulated failure');
                }
                parent::update_batch($logid, $payload);
            }
        };

        $logid = 0;
        try {
            $runner::run((int) $target->id, [(int) $source->id], [], $plan['hash']);
            $this->fail('The failure must reach the caller');
        } catch (partial_exception $e) {
            // Half done: the caller gets the journal row and the counts, and the cause in the debug information.
            $this->assertSame('error_partialbatch', $e->errorcode);
            $this->assertSame(1, $e->moved);
            $this->assertSame(2, $e->total);
            $this->assertStringContainsString('Simulated failure', (string) $e->debuginfo);
            $logid = $e->logid;
        }

        $newlaw = $this->child((int) $target->id, 'Derecho');
        $newing = $this->child((int) $target->id, 'Ingeniería');
        $this->assertSame($newlaw, $this->category_of($law));
        $this->assertSame((int) $ing->id, $this->category_of($calc));
        $this->assertSame($categories + 2, $DB->count_records('course_categories'));
        $this->assertSame($logid, (int) $DB->get_field(journal::TABLE, 'id', ['action' => 'merge'], MUST_EXIST));
        $payload = $this->payload($logid);
        $this->assertSame([$law], array_column($payload['items'], 'id'));
        $this->assertEqualsCanonicalizing([$newlaw, $newing], $payload['created']);

        $undo = journal::undo($logid);

        $this->assertTrue($undo['ok']);
        $this->assertSame((int) $derecho->id, $this->category_of($law));
        $this->assertSame($categories, $DB->count_records('course_categories'));
    }

    /**
     * A group with a course in the target must leave one in the destination: leaving the source winner in the
     * source, or sending everyone to Repetidos, is refused before any write, naming the course.
     */
    public function test_group_without_winner_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $new = $this->course('Cálculo I', $ing, 2000);
        $target = $this->cat('Semillas');
        $ting = $this->cat('Ingeniería', $target);
        $old = $this->course('Cálculo I', $ting, 1000);
        $categories = $DB->count_records('course_categories');

        $cases = [
            // The newer source course stays in the source; the target course goes to Repetidos by default.
            [['courseid' => $new, 'action' => 'skip']],
            // Everyone to Repetidos.
            [['courseid' => $new, 'action' => 'repeated']],
        ];
        foreach ($cases as $rows) {
            try {
                $this->apply_merge($target, [$source], $rows);
                $this->fail('A group without a course in the destination must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_groupwithoutwinner', $e->errorcode);
                $this->assertSame(get_string('error_groupwithoutwinner', 'tool_ceaorganizador', 'Cálculo I'), $e->getMessage());
            }
        }
        $this->assertSame((int) $ing->id, $this->category_of($new));
        $this->assertSame((int) $ting->id, $this->category_of($old));
        $this->assertSame($categories, $DB->count_records('course_categories'));
        $this->assertSame(0, $DB->count_records(journal::TABLE));

        // Keeping the target course is fine: the source course stays in the source.
        $result = $this->apply_merge($target, [$source], [
            ['courseid' => $new, 'action' => 'skip'],
            ['courseid' => $old, 'action' => 'move'],
        ]);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['logid']);
        $this->assertSame((int) $ting->id, $this->category_of($old));
    }

    /**
     * A confirmed possible group is accepted only when it is a possible group of the preview, and it must leave a
     * course in the destination like a sure group; then the target course can go to Repetidos.
     */
    public function test_confirmed_possible_group(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $source = $this->cat('Facultades');
        $ing = $this->cat('Ingeniería', $source);
        $new = $this->course('Física Mecánica', $ing, 2000);
        $target = $this->cat('Semillas');
        $ting = $this->cat('Ingeniería', $target);
        $old = $this->course('Física', $ting, 1000);
        $plan = mergeplan::build((int) $target->id, [(int) $source->id]);
        $key = $plan['possible'][0]['key'];
        $rows = [['courseid' => $new, 'action' => 'move'], ['courseid' => $old, 'action' => 'repeated']];

        try {
            mergerunner::run((int) $target->id, [(int) $source->id], $rows, $plan['hash'], [$new . '-0']);
            $this->fail('A key that is not a possible group of the preview must be refused');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame(0, $DB->count_records(journal::TABLE));
        }
        try {
            $everyone = [['courseid' => $new, 'action' => 'repeated'], ['courseid' => $old, 'action' => 'repeated']];
            mergerunner::run((int) $target->id, [(int) $source->id], $everyone, $plan['hash'], [$key]);
            $this->fail('A confirmed group must leave a course in the destination');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_groupwithoutwinner', $e->errorcode);
        }

        $result = mergerunner::run((int) $target->id, [(int) $source->id], $rows, $plan['hash'], [$key]);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(1, $result['repeated']);
        $this->assertSame((int) $ting->id, $this->category_of($new));
        $this->assertSame($this->child((int) $target->id, duplicates::FOLDER), $this->category_of($old));
    }
}
