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
 * Journal of operations: record, undo, history and cleanup.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Records every operation and undoes it through the mover, never partially.
 *
 * Payload formats (names are the raw database values, not formatted):
 * - move_category: {id, from: {parent, afterid, visible}, to: {parent, afterid, visible}}
 * - move_course: {id, from: {category, afterid, visible}, to: {category, afterid, visible}}
 * - sort: {categories: [{parentid, before: [ids], after: [ids]}], courses: [{categoryid, before, after}]}
 * - rename: {items: [{type: category|course, id, before: {name} | {fullname, shortname}, after: {...}}]}
 *
 * Undo first compares the live state with the 'to'/'after' state of every item and
 * writes nothing when anything differs; only then it restores, in one transaction.
 */
class journal {
    /** @var string Table of the journal. */
    public const TABLE = 'tool_ceaorganizador_log';

    /** @var string[] Accepted actions. */
    public const ACTIONS = ['move_category', 'move_course', 'sort', 'rename'];

    /** @var int Days the journal keeps a row. */
    public const RETENTION_DAYS = 90;

    /**
     * Records an operation that has already been applied.
     *
     * @param string $action One of self::ACTIONS.
     * @param string $summary Short text for the history; cut to 255 characters.
     * @param array $payload See the class docblock.
     * @return int Id of the new row.
     */
    public static function record(string $action, string $summary, array $payload): int {
        global $DB, $USER;
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \coding_exception('Unknown journal action: ' . $action);
        }
        return (int) $DB->insert_record(self::TABLE, (object) [
            'userid' => (int) $USER->id,
            'action' => $action,
            'summary' => \core_text::substr($summary, 0, 255),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
            'undoneby' => 0,
            'timeundone' => 0,
        ]);
    }

    /**
     * Moves a category with the mover and records it, visibility included, in one transaction.
     *
     * @param int $id
     * @param int $newparent 0 for top level.
     * @param int $afterid 0 for first.
     * @return array ['logid' => int, 'from' => [...], 'to' => [...]] as mover::move_category().
     */
    public static function move_category(int $id, int $newparent, int $afterid): array {
        global $DB;
        $visiblebefore = (int) $DB->get_field('course_categories', 'visible', ['id' => $id], MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = mover::move_category($id, $newparent, $afterid);
            $record = $DB->get_record('course_categories', ['id' => $id], 'id, name, visible', MUST_EXIST);
            $payload = [
                'id' => $id,
                'from' => $result['from'] + ['visible' => $visiblebefore],
                'to' => $result['to'] + ['visible' => (int) $record->visible],
            ];
            $summary = get_string('summary_movecategory', 'tool_ceaorganizador', $record->name);
            $logid = self::record('move_category', $summary, $payload);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return ['logid' => $logid] + $result;
    }

    /**
     * Moves a course with the mover and records it, visibility included, in one transaction.
     *
     * @param int $id
     * @param int $categoryid
     * @param int $afterid 0 for first.
     * @return array ['logid' => int, 'from' => [...], 'to' => [...]] as mover::move_course().
     */
    public static function move_course(int $id, int $categoryid, int $afterid): array {
        global $DB;
        $visiblebefore = (int) $DB->get_field('course', 'visible', ['id' => $id], MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = mover::move_course($id, $categoryid, $afterid);
            $record = $DB->get_record('course', ['id' => $id], 'id, fullname, visible', MUST_EXIST);
            $payload = [
                'id' => $id,
                'from' => $result['from'] + ['visible' => $visiblebefore],
                'to' => $result['to'] + ['visible' => (int) $record->visible],
            ];
            $summary = get_string('summary_movecourse', 'tool_ceaorganizador', $record->fullname);
            $logid = self::record('move_course', $summary, $payload);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return ['logid' => $logid] + $result;
    }

    /**
     * Undoes a recorded operation, or reports why it cannot.
     *
     * @param int $logid
     * @return array ['ok' => bool, 'conflicts' => string[]]; nothing is written when there are conflicts.
     * @throws \dml_missing_record_exception When the row does not exist.
     * @throws \moodle_exception error_alreadyundone when it was already undone.
     */
    public static function undo(int $logid): array {
        global $DB, $USER;
        $log = $DB->get_record(self::TABLE, ['id' => $logid], '*', MUST_EXIST);
        // Time, not user: the user may have been anonymised to 0.
        if ((int) $log->timeundone > 0) {
            throw new \moodle_exception('error_alreadyundone', 'tool_ceaorganizador');
        }
        $payload = json_decode($log->payload, true);
        if (!is_array($payload)) {
            throw new \coding_exception('Invalid journal payload in row ' . $logid);
        }

        // Every check runs before any write.
        $conflicts = self::conflicts($log->action, $payload);
        if ($conflicts) {
            return ['ok' => false, 'conflicts' => $conflicts];
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            self::restore($log->action, $payload);
            $DB->update_record(self::TABLE, (object) [
                'id' => $logid,
                'undoneby' => (int) $USER->id,
                'timeundone' => time(),
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction, $e);
        }
        return ['ok' => true, 'conflicts' => []];
    }

    /**
     * One page of the history, newest first.
     *
     * @param int $page Zero-based page.
     * @param int $perpage
     * @return array ['rows' => [['id', 'action', 'summary', 'userfullname', 'timecreated', 'undone']], 'total' => int]
     */
    public static function history(int $page, int $perpage = 20): array {
        global $DB;
        $perpage = max(1, $perpage);
        $total = $DB->count_records(self::TABLE);
        $records = $DB->get_records(
            self::TABLE,
            null,
            'timecreated DESC, id DESC',
            'id, userid, action, summary, timecreated, timeundone',
            max(0, $page) * $perpage,
            $perpage
        );

        // All the users of the page in one query.
        $userids = [];
        foreach ($records as $record) {
            if ((int) $record->userid > 0) {
                $userids[(int) $record->userid] = true;
            }
        }
        $users = [];
        if ($userids) {
            $fields = 'id, ' . implode(', ', \core_user\fields::get_name_fields());
            $users = $DB->get_records_list('user', 'id', array_keys($userids), '', $fields);
        }

        $rows = [];
        foreach ($records as $record) {
            $user = $users[(int) $record->userid] ?? null;
            $rows[] = [
                'id' => (int) $record->id,
                'action' => $record->action,
                'summary' => $record->summary,
                'userfullname' => $user ? fullname($user) : '',
                'timecreated' => (int) $record->timecreated,
                'undone' => (int) $record->timeundone > 0,
            ];
        }
        return ['rows' => $rows, 'total' => (int) $total];
    }

    /**
     * Deletes the rows older than the retention period.
     *
     * @param int|null $now Current time; null for time().
     */
    public static function cleanup(?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $DB->delete_records_select(
            self::TABLE,
            'timecreated < :cutoff',
            ['cutoff' => $now - self::RETENTION_DAYS * DAYSECS]
        );
    }

    /**
     * Purges the course and category caches, then rolls back and rethrows, as the mover does.
     *
     * @param \moodle_transaction $transaction
     * @param \Throwable $e
     * @throws \Throwable Always, the given one.
     */
    private static function rollback(\moodle_transaction $transaction, \Throwable $e): void {
        \cache_helper::purge_by_event('changesincoursecat');
        \cache_helper::purge_by_event('changesincourse');
        \context_helper::reset_caches();
        $transaction->rollback($e);
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
     * A list of ids from a payload, as integers.
     *
     * @param mixed $ids
     * @return int[]
     */
    private static function ids($ids): array {
        return is_array($ids) ? array_map('intval', array_values($ids)) : [];
    }

    /**
     * A plugin string for a conflict.
     *
     * @param string $code Code without the 'conflict_' prefix.
     * @param mixed $a
     * @return string
     */
    private static function conflict(string $code, $a): string {
        return get_string('conflict_' . $code, 'tool_ceaorganizador', $a);
    }

    /**
     * Formatted name of a category, or of the top level for 0.
     *
     * @param int $id
     * @return string
     */
    private static function category_name(int $id): string {
        global $DB;
        if ($id === 0) {
            return get_string('top');
        }
        $name = $DB->get_field('course_categories', 'name', ['id' => $id]);
        if ($name === false) {
            return (string) $id;
        }
        return format_string($name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Formatted name of a course.
     *
     * @param string $fullname
     * @return string
     */
    private static function course_name(string $fullname): string {
        return format_string($fullname, true, ['context' => \context_system::instance()]);
    }

    /**
     * Differences between the live state and the state the operation left.
     *
     * @param string $action
     * @param array $payload
     * @return string[] Human-readable conflicts; empty when the undo is safe.
     */
    private static function conflicts(string $action, array $payload): array {
        switch ($action) {
            case 'move_category':
                return self::conflicts_move_category($payload);
            case 'move_course':
                return self::conflicts_move_course($payload);
            case 'sort':
                return self::conflicts_sort($payload);
            case 'rename':
                return self::conflicts_rename($payload);
        }
        throw new \coding_exception('Unknown journal action: ' . $action);
    }

    /**
     * Conflicts of a category move: it must still be where it was put, and its old place must exist.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_move_category(array $payload): array {
        global $DB;
        $id = (int) $payload['id'];
        $from = $payload['from'];
        $to = $payload['to'];
        $category = $DB->get_record('course_categories', ['id' => $id], 'id, parent, name', IGNORE_MISSING);
        if (!$category) {
            return [self::conflict('missing', $id)];
        }
        $name = self::category_name($id);
        $conflicts = [];

        $parent = (int) $category->parent;
        $previous = self::previous(tree::sibling_categories($parent), $id);
        if ($parent !== (int) $to['parent'] || $previous !== (int) $to['afterid']) {
            $conflicts[] = self::conflict('moved', $name);
        }

        $fromparent = (int) $from['parent'];
        $fromafter = (int) $from['afterid'];
        if ($fromparent !== 0) {
            $path = $DB->get_field('course_categories', 'path', ['id' => $fromparent]);
            // The old parent must exist and must not have become the category itself or a descendant.
            if ($path === false || $fromparent === $id || strpos($path . '/', '/' . $id . '/') !== false) {
                $conflicts[] = self::conflict('targetmissing', $name);
                return $conflicts;
            }
        }
        $siblings = tree::sibling_categories($fromparent);
        if ($fromafter !== 0 && ($fromafter === $id || !in_array($fromafter, $siblings, true))) {
            $conflicts[] = self::conflict('targetmissing', $name);
        }
        return $conflicts;
    }

    /**
     * Conflicts of a course move: it must still be where it was put, and its old place must exist.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_move_course(array $payload): array {
        global $DB;
        $id = (int) $payload['id'];
        $from = $payload['from'];
        $to = $payload['to'];
        $course = $DB->get_record('course', ['id' => $id], 'id, category, fullname', IGNORE_MISSING);
        if (!$course || $id === (int) SITEID) {
            return [self::conflict('missing', $id)];
        }
        $name = self::course_name($course->fullname);
        $conflicts = [];

        $category = (int) $course->category;
        $previous = self::previous(tree::category_courses($category), $id);
        if ($category !== (int) $to['category'] || $previous !== (int) $to['afterid']) {
            $conflicts[] = self::conflict('moved', $name);
        }

        $fromcategory = (int) $from['category'];
        $fromafter = (int) $from['afterid'];
        if (!$DB->record_exists('course_categories', ['id' => $fromcategory])) {
            $conflicts[] = self::conflict('targetmissing', $name);
            return $conflicts;
        }
        $courses = tree::category_courses($fromcategory);
        if ($fromafter !== 0 && ($fromafter === $id || !in_array($fromafter, $courses, true))) {
            $conflicts[] = self::conflict('targetmissing', $name);
        }
        return $conflicts;
    }

    /**
     * Conflicts of a sort: every list must still be exactly the 'after' order.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_sort(array $payload): array {
        global $DB;
        $conflicts = [];
        foreach ($payload['categories'] ?? [] as $entry) {
            $parentid = (int) $entry['parentid'];
            if ($parentid !== 0 && !$DB->record_exists('course_categories', ['id' => $parentid])) {
                $conflicts[] = self::conflict('missing', $parentid);
                continue;
            }
            $before = self::ids($entry['before'] ?? null);
            $after = self::ids($entry['after'] ?? null);
            $sorted = $before;
            $aftersorted = $after;
            sort($sorted);
            sort($aftersorted);
            if (tree::sibling_categories($parentid) !== $after || $sorted !== $aftersorted) {
                $conflicts[] = self::conflict('categoriesorder', self::category_name($parentid));
            }
        }
        foreach ($payload['courses'] ?? [] as $entry) {
            $categoryid = (int) $entry['categoryid'];
            if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
                $conflicts[] = self::conflict('missing', $categoryid);
                continue;
            }
            $before = self::ids($entry['before'] ?? null);
            $after = self::ids($entry['after'] ?? null);
            $sorted = $before;
            $aftersorted = $after;
            sort($sorted);
            sort($aftersorted);
            if (tree::category_courses($categoryid) !== $after || $sorted !== $aftersorted) {
                $conflicts[] = self::conflict('coursesorder', self::category_name($categoryid));
            }
        }
        return $conflicts;
    }

    /**
     * Conflicts of a rename: every name must still be the 'after' name, and old short names free.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_rename(array $payload): array {
        global $DB;
        $conflicts = [];
        foreach ($payload['items'] ?? [] as $item) {
            $id = (int) $item['id'];
            $after = $item['after'];
            $before = $item['before'];
            if ($item['type'] === 'category') {
                $name = $DB->get_field('course_categories', 'name', ['id' => $id]);
                if ($name === false) {
                    $conflicts[] = self::conflict('missing', $id);
                } else if ($name !== (string) $after['name']) {
                    $conflicts[] = self::conflict('renamed', self::category_name($id));
                }
            } else if ($item['type'] === 'course') {
                $course = $DB->get_record('course', ['id' => $id], 'id, fullname, shortname', IGNORE_MISSING);
                if (!$course) {
                    $conflicts[] = self::conflict('missing', $id);
                    continue;
                }
                $name = self::course_name($course->fullname);
                $shortchanged = isset($after['shortname']) && $course->shortname !== (string) $after['shortname'];
                if ($course->fullname !== (string) $after['fullname'] || $shortchanged) {
                    $conflicts[] = self::conflict('renamed', $name);
                    continue;
                }
                // Another course may have taken the old short name since.
                if (isset($before['shortname']) && (string) $before['shortname'] !== $course->shortname) {
                    $taken = $DB->record_exists_select(
                        'course',
                        'shortname = :shortname AND id <> :id',
                        ['shortname' => (string) $before['shortname'], 'id' => $id]
                    );
                    if ($taken) {
                        $conflicts[] = self::conflict('shortnametaken', $name);
                    }
                }
            } else {
                throw new \coding_exception('Unknown item type in rename payload: ' . $item['type']);
            }
        }
        return $conflicts;
    }

    /**
     * Restores the state before the operation through the mover. Runs inside the undo transaction.
     *
     * @param string $action
     * @param array $payload
     */
    private static function restore(string $action, array $payload): void {
        switch ($action) {
            case 'move_category':
                $id = (int) $payload['id'];
                $from = $payload['from'];
                mover::move_category($id, (int) $from['parent'], (int) $from['afterid']);
                if (isset($from['visible'])) {
                    self::restore_category_visibility($id, (bool) $from['visible']);
                }
                return;
            case 'move_course':
                $id = (int) $payload['id'];
                $from = $payload['from'];
                mover::move_course($id, (int) $from['category'], (int) $from['afterid']);
                if (isset($from['visible'])) {
                    self::restore_course_visibility($id, (bool) $from['visible']);
                }
                return;
            case 'sort':
                foreach ($payload['categories'] ?? [] as $entry) {
                    mover::apply_category_order((int) $entry['parentid'], self::ids($entry['before']));
                }
                foreach ($payload['courses'] ?? [] as $entry) {
                    mover::apply_course_order((int) $entry['categoryid'], self::ids($entry['before']));
                }
                return;
            case 'rename':
                foreach ($payload['items'] as $item) {
                    $before = $item['before'];
                    if ($item['type'] === 'category') {
                        mover::rename_category((int) $item['id'], (string) $before['name']);
                    } else {
                        $shortname = isset($before['shortname']) ? (string) $before['shortname'] : null;
                        mover::rename_course((int) $item['id'], (string) $before['fullname'], $shortname);
                    }
                }
                return;
        }
        throw new \coding_exception('Unknown journal action: ' . $action);
    }

    /**
     * Shows or hides a category when its visibility differs, as moving into a hidden parent hides it.
     *
     * @param int $id
     * @param bool $visible
     */
    private static function restore_category_visibility(int $id, bool $visible): void {
        global $DB;
        $current = (bool) $DB->get_field('course_categories', 'visible', ['id' => $id], MUST_EXIST);
        if ($current === $visible) {
            return;
        }
        // The cached record could hold the visibility before the move; show() and hide() trust it.
        \cache_helper::purge_by_event('changesincoursecat');
        $category = \core_course_category::get($id, MUST_EXIST, true);
        if ($visible) {
            $category->show();
        } else {
            $category->hide();
        }
    }

    /**
     * Shows or hides a course when its visibility differs, as moving into a hidden category hides it.
     *
     * @param int $id
     * @param bool $visible
     */
    private static function restore_course_visibility(int $id, bool $visible): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $current = (bool) $DB->get_field('course', 'visible', ['id' => $id], MUST_EXIST);
        if ($current !== $visible) {
            course_change_visibility($id, $visible);
        }
    }
}
