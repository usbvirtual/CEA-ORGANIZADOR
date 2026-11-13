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

/**
 * Moves, reorders and renames categories and courses with the core API.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * The single write gate of the plugin: every change goes through the core API.
 *
 * The methods do not check capabilities; callers use check_category() and
 * check_course() first, so the same methods serve the undo of the journal.
 * category_content() is the single rule of what an empty category is: delete_empty_category()
 * and the deletion of categories (deleterunner) both rely on it.
 */
class mover {
    /**
     * Loads the course library, which defines move_courses() and update_course().
     */
    private static function require_course_lib(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
    }

    /**
     * Throws one of the plugin errors.
     *
     * @param string $code Code without the 'error_' prefix.
     * @param mixed $a Value for the string placeholder.
     * @throws \moodle_exception Always.
     */
    private static function fail(string $code, $a = null): void {
        throw new \moodle_exception('error_' . $code, 'tool_ceaorganizador', '', $a);
    }

    /**
     * Id of the item right before another in a list, or 0 when it is first or missing.
     *
     * @param int[] $ids
     * @param int $id
     * @return int
     */
    private static function previous(array $ids, int $id): int {
        $index = array_search($id, $ids, true);
        if ($index === false || $index === 0) {
            return 0;
        }
        return $ids[$index - 1];
    }

    /**
     * Inserts an id right after another in a list, or first when the other is 0.
     *
     * @param int[] $ids List without $id.
     * @param int $id
     * @param int $afterid
     * @return int[]
     */
    private static function insert_after(array $ids, int $id, int $afterid): array {
        $position = 0;
        if ($afterid !== 0) {
            $index = array_search($afterid, $ids, true);
            if ($index === false) {
                self::fail('aftermismatch');
            }
            $position = $index + 1;
        }
        array_splice($ids, $position, 0, [$id]);
        return $ids;
    }

    /**
     * Whether a list of ids is exactly a permutation of another.
     *
     * @param int[] $ids
     * @param int[] $current
     * @return bool
     */
    private static function same_set(array $ids, array $current): bool {
        if (count($ids) !== count($current) || count(array_unique($ids)) !== count($ids)) {
            return false;
        }
        sort($ids);
        sort($current);
        return $ids === $current;
    }

    /**
     * Moves a category under a parent (0 for top level), right after a sibling (0 for first).
     *
     * @param int $id
     * @param int $newparent
     * @param int $afterid
     * @return array ['from' => ['parent' => int, 'afterid' => int], 'to' => [...]]
     */
    public static function move_category(int $id, int $newparent, int $afterid): array {
        global $DB;
        $category = \core_course_category::get($id, MUST_EXIST, true);
        // Ensures the new parent exists; 0 returns the top pseudo-category.
        \core_course_category::get($newparent, MUST_EXIST, true);
        // Read fresh: the category record may come from the cache.
        $oldparent = (int) $DB->get_field('course_categories', 'parent', ['id' => $id], MUST_EXIST);

        // Checked now, not when the tree was loaded: another admin may have moved things since.
        $descendants = array_map('intval', $category->get_all_children_ids());
        if ($newparent === $id || in_array($newparent, $descendants, true)) {
            self::fail('cannotmoveintodescendant');
        }
        if ($afterid === $id) {
            self::fail('aftermismatch');
        }
        if ($afterid !== 0 && !in_array($afterid, tree::sibling_categories($newparent), true)) {
            self::fail('aftermismatch');
        }

        $from = ['parent' => $oldparent, 'afterid' => self::previous(tree::sibling_categories($oldparent), $id)];

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($newparent !== $oldparent) {
                $category->change_parent($newparent);
            }
            $current = tree::sibling_categories($newparent);
            $order = self::insert_after(array_values(array_diff($current, [$id])), $id, $afterid);
            // The core change_parent() already ran fix_course_sortorder() and purged the caches, leaving the category
            // last. When the list is already the order asked for (always when it goes last), a second pass would only
            // write the same order and repeat the site-wide cleanup: fix_course_sortorder() numbers the categories from
            // their order alone, so skipping it leaves exactly the same sortorders. Otherwise the order is written and
            // cleaned up once more: skipping that cleanup would leave the local sortorders of apply_category_order().
            if ($current !== $order) {
                self::apply_category_order($newparent, $order);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }

        $to = ['parent' => $newparent, 'afterid' => self::previous(tree::sibling_categories($newparent), $id)];
        return ['from' => $from, 'to' => $to];
    }

    /**
     * Moves a course into a category, right after another course (0 for first).
     *
     * @param int $id
     * @param int $categoryid
     * @param int $afterid
     * @return array ['from' => ['category' => int, 'afterid' => int], 'to' => [...]]
     */
    public static function move_course(int $id, int $categoryid, int $afterid): array {
        global $DB;
        self::require_course_lib();
        if ($id === (int) SITEID) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        $course = $DB->get_record('course', ['id' => $id], 'id, category', MUST_EXIST);
        $DB->get_record('course_categories', ['id' => $categoryid], 'id', MUST_EXIST);
        $oldcategory = (int) $course->category;

        if ($afterid === $id) {
            self::fail('aftermismatch');
        }
        if ($afterid !== 0 && !in_array($afterid, tree::category_courses($categoryid), true)) {
            self::fail('aftermismatch');
        }

        $from = ['category' => $oldcategory, 'afterid' => self::previous(tree::category_courses($oldcategory), $id)];

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($categoryid !== $oldcategory) {
                move_courses([$id], $categoryid);
            }
            // A fresh record, so the category compared inside the core function is the new one.
            $fresh = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
            if (!course_change_sortorder_after_course($fresh, $afterid)) {
                self::fail('aftermismatch');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }

        $to = ['category' => $categoryid, 'afterid' => self::previous(tree::category_courses($categoryid), $id)];
        return ['from' => $from, 'to' => $to];
    }

    /**
     * Sets the exact order of the child categories of a parent.
     *
     * Replicates core_course_category::resort_subcategories() with a given order.
     *
     * With $cleanup false, the site-wide fix_course_sortorder() is skipped, as in
     * core_course_category::resort_subcategories($field, $cleanup): the caller must then call
     * \core_course_category::resort_categories_cleanup(true) once, after its last list.
     * Events are triggered either way.
     *
     * @param int $parentid 0 for top level.
     * @param int[] $ids All current children, in the new order.
     * @param bool $cleanup Whether to fix the sortorders and purge the caches now.
     */
    public static function apply_category_order(int $parentid, array $ids, bool $cleanup = true): void {
        global $DB;
        $ids = array_map('intval', array_values($ids));
        $current = tree::sibling_categories($parentid);
        if (!self::same_set($ids, $current)) {
            self::fail('ordermismatch');
        }
        if (!$ids) {
            return;
        }
        $records = $DB->get_records_list('course_categories', 'id', $ids, '', 'id, coursecount');

        $transaction = $DB->start_delegated_transaction();
        try {
            // Category sortorders leave room for the courses of each category.
            $i = 1;
            foreach ($ids as $id) {
                $i++;
                $DB->set_field('course_categories', 'sortorder', $i, ['id' => $id]);
                $i += (int) $records[$id]->coursecount;
            }
            if ($cleanup) {
                \core_course_category::resort_categories_cleanup();
            }

            foreach ($ids as $index => $id) {
                if ($current[$index] !== $id) {
                    $event = \core\event\course_category_updated::create([
                        'objectid' => $id,
                        'context' => \context_coursecat::instance($id),
                    ]);
                    $event->trigger();
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Sets the exact order of the courses of a category.
     *
     * Replicates core_course_category::resort_courses() with a given order.
     *
     * With $cleanup false, the site-wide fix_course_sortorder() is skipped, as in
     * core_course_category::resort_courses($field, $cleanup): the caller must then call
     * \core_course_category::resort_categories_cleanup(true) once, after its last list.
     * A batch must apply its course lists before its category lists: a category order written
     * without cleanup leaves temporary category sortorders that a course order must not build on.
     *
     * @param int $categoryid
     * @param int[] $ids All current courses of the category, in the new order.
     * @param bool $cleanup Whether to fix the sortorders and purge the caches now.
     */
    public static function apply_course_order(int $categoryid, array $ids, bool $cleanup = true): void {
        global $DB;
        $ids = array_map('intval', array_values($ids));
        $categorysortorder = (int) $DB->get_field('course_categories', 'sortorder', ['id' => $categoryid], MUST_EXIST);
        if (!self::same_set($ids, tree::category_courses($categoryid))) {
            self::fail('ordermismatch');
        }
        if (!$ids) {
            return;
        }
        // Beyond the limit, course sortorders would overflow into the next category.
        $limit = (int) get_max_courses_in_category();
        if (count($ids) > $limit) {
            self::fail('toomanycourses', $limit);
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $i = 1;
            foreach ($ids as $id) {
                $DB->set_field('course', 'sortorder', $categorysortorder + $i, ['id' => $id]);
                $i++;
            }
            if ($cleanup) {
                \core_course_category::resort_categories_cleanup(true);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Renames a category.
     *
     * @param int $id
     * @param string $name
     */
    public static function rename_category(int $id, string $name): void {
        global $DB;
        $category = \core_course_category::get($id, MUST_EXIST, true);
        $transaction = $DB->start_delegated_transaction();
        try {
            $category->update(['name' => $name]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Renames a course, and its short name when given.
     *
     * update_course() throws 'shortnametaken' when the short name is in use.
     *
     * @param int $id
     * @param string $fullname
     * @param string|null $shortname Null keeps the current short name.
     */
    public static function rename_course(int $id, string $fullname, ?string $shortname): void {
        global $DB;
        self::require_course_lib();
        $data = (object) ['id' => $id, 'fullname' => $fullname];
        if ($shortname !== null) {
            $data->shortname = $shortname;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            update_course($data);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Shows or hides a course, as the course management page does.
     *
     * course_change_visibility() sets visible and visibleold, then calls update_course(), which
     * triggers course_updated and purges the caches; it checks no capability.
     *
     * @param int $id
     * @param bool $visible
     */
    public static function set_course_visibility(int $id, bool $visible): void {
        global $DB;
        self::require_course_lib();
        if ($id === (int) SITEID) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            course_change_visibility($id, $visible);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Writes the visibility of many categories or courses at once: one UPDATE per (visible, visibleold) pair and
     * 1000 ids, as core_course_category::hide_raw() and show_raw() write the courses of a category.
     *
     * It is the write of «Ocultar todo» / «Mostrar todo» and of their undo. It checks no capability, triggers no
     * event and purges no cache: the caller checks first, triggers course_category_updated with
     * category_updated_events() and calls purge_visibility_caches() once at the end. course_updated is not triggered
     * per course, as the core does not when it hides or shows a category with its courses.
     *
     * @param string $table 'course_categories' or 'course'.
     * @param array $states Map id => ['visible' => 0|1, 'visibleold' => 0|1].
     */
    public static function set_visibility_states(string $table, array $states): void {
        global $DB;
        if (!in_array($table, ['course_categories', 'course'], true)) {
            throw new \coding_exception('Invalid table for set_visibility_states: ' . $table);
        }
        $groups = [];
        foreach ($states as $id => $state) {
            $id = (int) $id;
            if ($table === 'course' && $id === (int) SITEID) {
                throw new \moodle_exception('invalidcourseid', 'error');
            }
            $visible = (int) (bool) $state['visible'];
            $visibleold = (int) (bool) $state['visibleold'];
            $groups[$visible . '|' . $visibleold][] = $id;
        }
        if (!$groups) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($groups as $pair => $ids) {
                [$visible, $visibleold] = array_map('intval', explode('|', $pair));
                foreach (array_chunk($ids, 1000) as $chunk) {
                    [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'vis');
                    $params['visible'] = $visible;
                    $params['visibleold'] = $visibleold;
                    $DB->execute("UPDATE {{$table}} SET visible = :visible, visibleold = :visibleold WHERE id $in", $params);
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Triggers course_category_updated for every category whose visibility changed, as hide() and show() do.
     *
     * @param int[] $ids
     */
    public static function category_updated_events(array $ids): void {
        foreach ($ids as $id) {
            \core\event\course_category_updated::create([
                'objectid' => (int) $id,
                'context' => \context_coursecat::instance((int) $id),
            ])->trigger();
        }
    }

    /**
     * Purges the category and course caches once, after set_visibility_states().
     */
    public static function purge_visibility_caches(): void {
        \cache_helper::purge_by_event('changesincoursecat');
        \cache_helper::purge_by_event('changesincourse');
    }

    /**
     * Requires moodle/category:manage on many categories and on their parents (the system context for the top
     * level), as core_course_category::can_change_visibility() does. Categories that no longer exist are left out.
     *
     * @param int[] $ids
     */
    public static function check_categories_and_parents(array $ids): void {
        global $DB;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $check = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->get_records_list('course_categories', 'id', $chunk, '', 'id, parent') as $record) {
                $check[(int) $record->id] = true;
                $check[(int) $record->parent] = true;
            }
        }
        foreach (array_keys($check) as $id) {
            self::check_category((int) $id);
        }
    }

    /**
     * Requires a capability on many courses, loading their contexts in one query per 1000 ids.
     *
     * Courses that no longer exist are left out: the caller reports them.
     *
     * @param int[] $ids
     * @param string $cap Such as 'moodle/course:visibility'.
     */
    public static function check_courses(array $ids, string $cap): void {
        global $DB;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $select = \context_helper::get_preload_record_columns_sql('ctx');
        foreach (array_chunk($ids, 1000) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'course');
            $params['level'] = CONTEXT_COURSE;
            $records = $DB->get_records_sql(
                "SELECT c.id, $select
                   FROM {course} c
                   JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
                  WHERE c.id $in",
                $params
            );
            foreach ($records as $record) {
                \context_helper::preload_from_record($record);
                self::check_course((int) $record->id, $cap);
            }
        }
    }

    /**
     * Creates the missing categories level by level, once each, and sets the final destination of every row.
     *
     * Names are matched by parent and normalized name, so rows that need the same path share its categories.
     * The caller has checked moodle/category:manage on every anchor and holds the transaction.
     *
     * @param array $rows Rows with 'anchor' (int, existing category), 'missing' (raw names to create below it) and,
     *      optionally, 'hidden' (true creates its missing categories hidden, as the «Repetidos» folder). A category
     *      already created for an earlier row keeps the visibility it was created with.
     * @return array [rows with 'destcategoryid' set, int[] ids of the categories created, in creation order]
     */
    public static function create_categories(array $rows): array {
        $created = [];
        $cache = [];
        foreach ($rows as &$row) {
            $parent = (int) $row['anchor'];
            foreach ($row['missing'] as $name) {
                $key = $parent . '|' . sorter::normalize($name);
                if (!isset($cache[$key])) {
                    $data = ['name' => $name, 'parent' => $parent];
                    if (!empty($row['hidden'])) {
                        $data['visible'] = 0;
                    }
                    $category = \core_course_category::create($data);
                    $cache[$key] = (int) $category->id;
                    $created[] = (int) $category->id;
                }
                $parent = $cache[$key];
            }
            $row['destcategoryid'] = $parent;
        }
        unset($row);
        return [$rows, $created];
    }

    /**
     * Moves several courses into one category with move_courses(), which keeps no position: they go first.
     *
     * Moving into a hidden category hides the course (core behaviour).
     *
     * @param int[] $ids
     * @param int $categoryid
     */
    public static function move_courses_into(array $ids, int $categoryid): void {
        global $DB;
        self::require_course_lib();
        $ids = array_map('intval', array_values($ids));
        if (!$ids) {
            return;
        }
        if (in_array((int) SITEID, $ids, true)) {
            throw new \moodle_exception('invalidcourseid', 'error');
        }
        $DB->get_record('course_categories', ['id' => $categoryid], 'id', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!move_courses($ids, $categoryid)) {
                throw new \moodle_exception('invalidcourseid', 'error');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
    }

    /**
     * Deletes a category only when nothing would be lost: no courses, subcategories or data in its context.
     *
     * delete_full() deletes, besides the category and its context, what hangs from that context. A category that
     * holds anything (see category_content()) is kept. If delete_full() still deletes a course (one added between
     * the check and the call, by another tool), it throws, so the caller's transaction rolls everything back.
     *
     * @param int $id
     * @return bool Whether it was deleted.
     * @throws \moodle_exception error_notempty when delete_full() deleted courses.
     */
    public static function delete_empty_category(int $id): bool {
        global $DB;
        if (!$DB->record_exists('course_categories', ['id' => $id]) || self::category_has_content($id)) {
            return false;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $deletedcourses = \core_course_category::get($id, MUST_EXIST, true)->delete_full(false);
            if (!empty($deletedcourses)) {
                self::fail('notempty');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
        return true;
    }

    /**
     * Whether a category holds anything delete_full() would destroy.
     *
     * @param int $id
     * @return bool
     */
    private static function category_has_content(int $id): bool {
        return self::category_content($id) !== '';
    }

    /**
     * Why deleting a category would lose something, or '' when it is empty.
     *
     * Empty means no courses, no subcategories and nothing of its own that delete_full() would destroy or move
     * and an undo could not bring back: recycle bin items, events, and in its context content bank items, grade
     * letters, question categories, role assignments, permission overrides, cohorts (delete_full() moves them to
     * the parent), blocks, files (description images too), comments, filter settings and competency frameworks
     * or templates. The default category for course requests counts as content too. Any question category
     * counts, even an empty «top» one: from Moodle 5.0 delete_full() no longer deletes question categories and
     * would leave the row orphaned. The cost is that, rarely, an empty category is kept and must be deleted by
     * hand. One indexed existence query per table.
     *
     * @param int $id
     * @param int[] $gone Subcategories that do not count, because the caller deletes them first.
     * @return string '', 'hascourses', 'haschildren' or 'hascontent'.
     */
    public static function category_content(int $id, array $gone = []): string {
        global $CFG, $DB;
        if ($DB->record_exists('course', ['category' => $id])) {
            return 'hascourses';
        }
        $children = $DB->get_fieldset_select('course_categories', 'id', 'parent = :parent', ['parent' => $id]);
        $children = array_map('intval', $children);
        if (array_diff($children, array_map('intval', $gone))) {
            return 'haschildren';
        }
        $manager = $DB->get_manager();
        if (
            $manager->table_exists('tool_recyclebin_category')
            && $DB->record_exists('tool_recyclebin_category', ['categoryid' => $id])
        ) {
            return 'hascontent';
        }
        if ($DB->record_exists('event', ['categoryid' => $id])) {
            return 'hascontent';
        }
        // Moodle needs it for course requests; core_course_category::can_delete() refuses it too.
        if (!empty($CFG->defaultrequestcategory) && (int) $CFG->defaultrequestcategory === $id) {
            return 'hascontent';
        }
        $contextid = $DB->get_field('context', 'id', ['contextlevel' => CONTEXT_COURSECAT, 'instanceid' => $id]);
        if ($contextid === false) {
            return '';
        }
        $contextid = (int) $contextid;
        // Any question category counts, an empty «top» too: questions hang from these categories.
        $tables = [
            'contentbank_content' => 'contextid',
            'grade_letters' => 'contextid',
            'question_categories' => 'contextid',
            'role_assignments' => 'contextid',
            'role_capabilities' => 'contextid',
            'cohort' => 'contextid',
            'block_instances' => 'parentcontextid',
            'comments' => 'contextid',
            'filter_active' => 'contextid',
            'filter_config' => 'contextid',
            'competency_framework' => 'contextid',
            'competency_template' => 'contextid',
        ];
        foreach ($tables as $table => $field) {
            if ($DB->record_exists($table, [$field => $contextid])) {
                return 'hascontent';
            }
        }
        // Directory entries ('.') do not count: they are left by areas that are empty now.
        $files = $DB->record_exists_select('files', "contextid = :contextid AND filename <> '.'", ['contextid' => $contextid]);
        return $files ? 'hascontent' : '';
    }

    /**
     * Creates a category with the core API; it is appended last among its siblings.
     *
     * The caller has checked moodle/category:manage on the parent. A visible value of 0 creates it hidden;
     * otherwise it inherits the visibility of the parent, as in the core.
     *
     * @param array $data Fields for core_course_category::create(): name, idnumber, description,
     *      descriptionformat, parent, visible and, when the site allows category themes, theme.
     * @return int Id of the new category.
     */
    public static function create_category(array $data): int {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $category = \core_course_category::create($data);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
        return (int) $category->id;
    }

    /**
     * Requires the capability to manage a category (0 for top level, in the system context).
     *
     * @param int $id
     */
    public static function check_category(int $id): void {
        $context = $id === 0 ? \context_system::instance() : \context_coursecat::instance($id);
        require_capability('moodle/category:manage', $context);
    }

    /**
     * Requires a capability in the context of a course.
     *
     * @param int $id
     * @param string $cap Such as 'moodle/course:changecategory' or 'moodle/course:update'.
     */
    public static function check_course(int $id, string $cap): void {
        require_capability($cap, \context_course::instance($id));
    }
}
