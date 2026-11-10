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
            $others = array_values(array_diff(tree::sibling_categories($newparent), [$id]));
            self::apply_category_order($newparent, self::insert_after($others, $id, $afterid));
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
     * @param int $parentid 0 for top level.
     * @param int[] $ids All current children, in the new order.
     */
    public static function apply_category_order(int $parentid, array $ids): void {
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
            \core_course_category::resort_categories_cleanup();

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
     * @param int $categoryid
     * @param int[] $ids All current courses of the category, in the new order.
     */
    public static function apply_course_order(int $categoryid, array $ids): void {
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
            \core_course_category::resort_categories_cleanup(true);
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
