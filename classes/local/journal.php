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

/**
 * Records every operation and undoes it through the mover, never partially.
 *
 * Payload formats (names are the raw database values, not formatted):
 * - move_category: {id, from: {parent, afterid, visible}, to: {parent, afterid, visible}}
 * - move_course: {id, from: {category, afterid, visible}, to: {category, afterid, visible}}
 * - sort: {categories: [{parentid, before: [ids], after: [ids]}], courses: [{categoryid, before, after}]}
 * - rename: {items: [{type: category|course, id, before: {name} | {fullname, shortname}, after: {...}}]}
 * - clone: {periodid, label, startdate, enddate, categories: [created ids], items: [{source, newfullname,
 *   newshortname, destcategoryid, backupid, restoreid, newcourseid, error}]}; see clonerunner. It cannot be undone.
 * - visibility: {items: [{id, name (raw full name), before: 0|1, after: 0|1}]}, only the courses that changed.
 * - merge: {targetid, sourceids, created: [category ids, in creation order], items: [{id, fromcategory, tocategory,
 *   fromvisible, tovisible}]}, only the courses moved; see mergerunner. Undo moves them back and deletes the
 *   created categories that are empty then; one that received other content is kept. A merge also sends repeated
 *   courses, and target courses that lose to a newer one, to the hidden «Repetidos» folder under the target: those
 *   are items too, and the folder is in 'created' when the merge created it.
 * - dedupe: {categoryid, created: [category ids], items: [{id, fromcategory, tocategory, fromvisible, tovisible}]};
 *   see deduperunner. The same format and the same undo as merge: the repeated courses go back, their visibility is
 *   restored when the move changed it, and the «Repetidos» folder is deleted only when the batch created it and it is
 *   empty then.
 *
 * merge and dedupe are written in steps (see batchrunner): the row is recorded with the created categories and no
 * items, and every chunk of moves appends its items in the same transaction as the moves. The row always describes
 * exactly what was applied, so a batch that fails halfway can still be undone, in one transaction.
 * - delete: {items: [{id, name, idnumber, description, descriptionformat, parent, afterid, visible, visibleold,
 *   theme (only when set)}]}, in deletion order (deepest first); see deleterunner. Undo creates them again in the
 *   reverse order, with new ids: a parent deleted in the same batch is created first and its children go under
 *   the new id. Each one goes back right after its old previous sibling when that one is still there; otherwise
 *   it goes last. A category with the same normalized name under the old parent is a conflict.
 *
 * Undo first compares the live state with the 'to'/'after' state of every item (position,
 * order, names and visibility) and writes nothing when anything differs; only then it
 * restores, in one transaction. Visibility is restored only when the move itself changed it.
 */
class journal {
    /** @var string Table of the journal. */
    public const TABLE = 'tool_ceaorganizador_log';

    /** @var string[] Accepted actions. */
    public const ACTIONS = ['move_category', 'move_course', 'sort', 'rename', 'clone', 'visibility', 'merge', 'delete', 'dedupe'];

    /** @var int Length of the summary column. */
    public const SUMMARY_MAX = 255;

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
            'summary' => \core_text::substr($summary, 0, self::SUMMARY_MAX),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
            'undoneby' => 0,
            'timeundone' => 0,
        ]);
    }

    /**
     * Rewrites the summary and the payload of a row that is being written in steps (merge and dedupe).
     *
     * @param int $id Row returned by record().
     * @param string $summary Cut to 255 characters.
     * @param array $payload The whole payload so far.
     */
    public static function update(int $id, string $summary, array $payload): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'summary' => \core_text::substr($summary, 0, self::SUMMARY_MAX),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * A list of names for a summary, never cut inside a name.
     *
     * Joins the names with ", " while they fit; the names left out are counted, as in
     * "A, B y 3 más". When not even the first name fits, the list is an ellipsis and the count.
     *
     * @param string[] $names Raw names; the history formats them on output.
     * @param int $max Characters available for the list.
     * @return string
     */
    public static function list_summary(array $names, int $max = self::SUMMARY_MAX): string {
        $names = array_values(array_map('strval', $names));
        $total = count($names);
        $all = implode(', ', $names);
        if (\core_text::strlen($all) <= $max) {
            return $all;
        }
        // Fewer and fewer names, until the names and the count of the rest fit.
        for ($shown = $total - 1; $shown >= 0; $shown--) {
            $list = $shown > 0 ? implode(', ', array_slice($names, 0, $shown)) : '…';
            $text = get_string('summarymore', 'tool_ceaorganizador', (object) [
                'list' => $list,
                'count' => $total - $shown,
            ]);
            if ($shown === 0 || \core_text::strlen($text) <= $max) {
                return $text;
            }
        }
        return '';
    }

    /**
     * Characters left for the {$a} of a summary string once its own text is counted.
     *
     * @param string $identifier String of this plugin with a {$a} placeholder.
     * @return int
     */
    public static function summary_room(string $identifier): int {
        $frame = get_string($identifier, 'tool_ceaorganizador', '');
        return max(0, self::SUMMARY_MAX - \core_text::strlen($frame));
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
            guard::rollback($transaction, $e);
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
            guard::rollback($transaction, $e);
        }
        return ['logid' => $logid] + $result;
    }

    /**
     * Undoes a recorded operation, or reports why it cannot.
     *
     * @param int $logid
     * @return array ['ok' => bool, 'conflicts' => string[]]; nothing is written when there are conflicts.
     * @throws \dml_missing_record_exception When the row does not exist.
     * @throws \moodle_exception error_alreadyundone when it was already undone; error_cannotundoclone for a clone.
     */
    public static function undo(int $logid): array {
        global $DB, $USER;
        $log = $DB->get_record(self::TABLE, ['id' => $logid], '*', MUST_EXIST);
        // Undoing a clone would delete courses: never automatic.
        if ($log->action === 'clone') {
            throw new \moodle_exception('error_cannotundoclone', 'tool_ceaorganizador');
        }
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
            // Another undo may have finished between the check and this transaction.
            if ((int) $DB->get_field(self::TABLE, 'timeundone', ['id' => $logid], MUST_EXIST) > 0) {
                throw new \moodle_exception('error_alreadyundone', 'tool_ceaorganizador');
            }
            self::restore($log->action, $payload);
            $DB->update_record(self::TABLE, (object) [
                'id' => $logid,
                'undoneby' => (int) $USER->id,
                'timeundone' => time(),
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
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
            case 'visibility':
                return self::conflicts_visibility($payload);
            case 'merge':
            case 'dedupe':
                return self::conflicts_merge($payload);
            case 'delete':
                return self::conflicts_delete($payload);
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
        $category = $DB->get_record('course_categories', ['id' => $id], 'id, parent, name, visible', IGNORE_MISSING);
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
        // Someone hid or showed it on purpose after the move: undo must not override that.
        if (isset($to['visible']) && (int) $category->visible !== (int) $to['visible']) {
            $conflicts[] = self::conflict('visibility', $name);
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
        $course = $DB->get_record('course', ['id' => $id], 'id, category, fullname, visible', IGNORE_MISSING);
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
        if (isset($to['visible']) && (int) $course->visible !== (int) $to['visible']) {
            $conflicts[] = self::conflict('visibility', $name);
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
        $items = array_values($payload['items'] ?? []);
        // Courses of the batch give their names back during the undo, so they do not block one another.
        $ids = ['category' => [], 'course' => []];
        foreach ($items as $item) {
            if (isset($ids[$item['type'] ?? ''])) {
                $ids[$item['type']][] = (int) $item['id'];
            }
        }
        // Every item in one query per type and 1000 ids, not one per item.
        $categories = [];
        foreach (array_chunk($ids['category'], 1000) as $chunk) {
            $categories += $DB->get_records_list('course_categories', 'id', $chunk, '', 'id, name');
        }
        $courses = [];
        foreach (array_chunk($ids['course'], 1000) as $chunk) {
            $courses += $DB->get_records_list('course', 'id', $chunk, '', 'id, fullname, shortname');
        }

        // Conflicts by item, so they keep the order of the items; the old short names are checked together.
        $byitem = [];
        $oldshort = [];
        foreach ($items as $index => $item) {
            $id = (int) $item['id'];
            $after = $item['after'];
            $before = $item['before'];
            $byitem[$index] = [];
            if ($item['type'] === 'category') {
                if (!isset($categories[$id])) {
                    $byitem[$index][] = self::conflict('missing', $id);
                } else if ($categories[$id]->name !== (string) $after['name']) {
                    $byitem[$index][] = self::conflict('renamed', self::category_name($id));
                }
            } else if ($item['type'] === 'course') {
                $course = $courses[$id] ?? null;
                if (!$course) {
                    $byitem[$index][] = self::conflict('missing', $id);
                    continue;
                }
                $shortchanged = isset($after['shortname']) && $course->shortname !== (string) $after['shortname'];
                if ($course->fullname !== (string) $after['fullname'] || $shortchanged) {
                    $byitem[$index][] = self::conflict('renamed', self::course_name($course->fullname));
                    continue;
                }
                // Another course may have taken the old short name since.
                if (isset($before['shortname']) && (string) $before['shortname'] !== $course->shortname) {
                    $oldshort[$index] = (string) $before['shortname'];
                }
            } else {
                throw new \coding_exception('Unknown item type in rename payload: ' . $item['type']);
            }
        }
        if ($oldshort) {
            $taken = tree::taken_shortnames(array_values($oldshort), $ids['course']);
            foreach ($oldshort as $index => $shortname) {
                if (isset($taken[$shortname])) {
                    $name = self::course_name($courses[(int) $items[$index]['id']]->fullname);
                    $byitem[$index][] = self::conflict('shortnametaken', $name);
                }
            }
        }
        return $byitem ? array_merge(...array_values($byitem)) : [];
    }

    /**
     * Conflicts of a visibility change: every course must exist and still have the 'after' visibility.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_visibility(array $payload): array {
        global $DB;
        $ids = [0];
        foreach ($payload['items'] ?? [] as $item) {
            $ids[] = (int) $item['id'];
        }
        $courses = $DB->get_records_list('course', 'id', $ids, '', 'id, fullname, visible');
        $conflicts = [];
        foreach ($payload['items'] ?? [] as $item) {
            $id = (int) $item['id'];
            $course = $courses[$id] ?? null;
            if (!$course || $id === (int) SITEID) {
                $conflicts[] = self::conflict('missing', $id);
            } else if ((int) $course->visible !== (int) $item['after']) {
                $conflicts[] = self::conflict('visibility', self::course_name($course->fullname));
            }
        }
        return $conflicts;
    }

    /**
     * Conflicts of a merge: every course must still be where it was put, and its old category must exist.
     *
     * The visibility counts only when the move changed it, since only then the undo writes it.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_merge(array $payload): array {
        global $DB;
        $ids = [0];
        $fromids = [0];
        foreach ($payload['items'] ?? [] as $item) {
            $ids[] = (int) $item['id'];
            $fromids[] = (int) $item['fromcategory'];
        }
        $courses = [];
        $categories = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $courses += $DB->get_records_list('course', 'id', $chunk, '', 'id, category, fullname, visible');
        }
        foreach (array_chunk(array_unique($fromids), 1000) as $chunk) {
            $categories += $DB->get_records_list('course_categories', 'id', $chunk, '', 'id');
        }
        $conflicts = [];
        foreach ($payload['items'] ?? [] as $item) {
            $id = (int) $item['id'];
            $course = $courses[$id] ?? null;
            if (!$course || $id === (int) SITEID) {
                $conflicts[] = self::conflict('missing', $id);
                continue;
            }
            $name = self::course_name($course->fullname);
            if ((int) $course->category !== (int) $item['tocategory']) {
                $conflicts[] = self::conflict('moved', $name);
            }
            if (self::merge_visibility_changed($item) && (int) $course->visible !== (int) $item['tovisible']) {
                $conflicts[] = self::conflict('visibility', $name);
            }
            if (!isset($categories[(int) $item['fromcategory']])) {
                $conflicts[] = self::conflict('targetmissing', $name);
            }
        }
        return $conflicts;
    }

    /**
     * Whether moving a merged course changed its visibility (moving into a hidden category hides it).
     *
     * @param array $item Item of a merge payload.
     * @return bool
     */
    private static function merge_visibility_changed(array $item): bool {
        return isset($item['fromvisible'], $item['tovisible']) && (int) $item['fromvisible'] !== (int) $item['tovisible'];
    }

    /**
     * Undoes a merge: the courses go back, then the created categories left empty are deleted, deepest first.
     *
     * Categories are created parent before child, so the reverse creation order is deepest first.
     * A created category that holds a course or a subcategory now is kept: it is not a conflict.
     *
     * @param array $payload
     */
    private static function restore_merge(array $payload): void {
        $groups = [];
        foreach ($payload['items'] ?? [] as $item) {
            $groups[(int) $item['fromcategory']][] = (int) $item['id'];
        }
        foreach ($groups as $categoryid => $ids) {
            mover::move_courses_into($ids, $categoryid);
        }
        foreach ($payload['items'] ?? [] as $item) {
            if (self::merge_visibility_changed($item)) {
                self::restore_course_visibility((int) $item['id'], (bool) $item['fromvisible']);
            }
        }
        foreach (array_reverse(self::ids($payload['created'] ?? null)) as $id) {
            mover::delete_empty_category($id);
        }
    }

    /**
     * Conflicts of a deletion: the old parent must still exist, unless the same batch deleted it, the old idnumber
     * must be free, and no category with the same normalized name may be under that parent now.
     *
     * A parent deleted in the same batch is created again by the undo, empty but for the batch, so only the
     * parents that were not deleted are compared by name.
     *
     * @param array $payload
     * @return string[]
     */
    private static function conflicts_delete(array $payload): array {
        global $DB;
        $batch = [];
        foreach ($payload['items'] ?? [] as $item) {
            $batch[(int) $item['id']] = true;
        }
        $siblings = [];
        $conflicts = [];
        foreach ($payload['items'] ?? [] as $item) {
            $name = format_string((string) $item['name'], true, ['context' => \context_system::instance()]);
            $parent = (int) $item['parent'];
            if (!isset($batch[$parent])) {
                if ($parent !== 0 && !$DB->record_exists('course_categories', ['id' => $parent])) {
                    $conflicts[] = self::conflict('parentmissing', $name);
                    continue;
                }
                if (!isset($siblings[$parent])) {
                    // Compared in PHP, normalized as the plugin compares names, whatever the database collation.
                    $siblings[$parent] = [];
                    foreach ($DB->get_records('course_categories', ['parent' => $parent], '', 'id, name') as $record) {
                        $siblings[$parent][sorter::normalize($record->name)] = true;
                    }
                }
                if (isset($siblings[$parent][sorter::normalize((string) $item['name'])])) {
                    $conflicts[] = self::conflict('nametaken', $name);
                }
            }
            // Compared as core_course_category::create() does, which would refuse it.
            $idnumber = (string) ($item['idnumber'] ?? '');
            if ($idnumber !== '' && $DB->record_exists('course_categories', ['idnumber' => $idnumber])) {
                $conflicts[] = self::conflict('idnumbertaken', $name);
            }
        }
        return $conflicts;
    }

    /**
     * Undoes a deletion: creates the categories again, then puts each parent's children in order once.
     *
     * The items are in deletion order, deepest first, so the reverse order creates every parent before its
     * children; the new ids are mapped from the old ones. Each category is created last; the final order of
     * every parent is then computed from the 'afterid' chain, replaying the deletions backwards: each category
     * goes right after its old previous sibling (its new id when it was in the batch), first when it was first,
     * or last when that sibling is no longer there. One apply_category_order() per parent, one cleanup at the end.
     *
     * A category hidden on purpose (visibleold 0) is created hidden; any other one is created visible and
     * inherits the visibility of its parent, as the core does, so one hidden only by its parent shows again
     * with it.
     *
     * @param array $payload
     */
    private static function restore_delete(array $payload): void {
        $items = array_reverse($payload['items'] ?? []);
        $map = [];
        $created = [];
        foreach ($items as $item) {
            $oldparent = (int) $item['parent'];
            $hidden = !(int) ($item['visible'] ?? 1) && !(int) ($item['visibleold'] ?? 0);
            $data = [
                'name' => (string) $item['name'],
                'idnumber' => (string) ($item['idnumber'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'descriptionformat' => (int) ($item['descriptionformat'] ?? FORMAT_MOODLE),
                'parent' => $map[$oldparent] ?? $oldparent,
                'visible' => $hidden ? 0 : 1,
            ];
            if ((string) ($item['theme'] ?? '') !== '') {
                $data['theme'] = (string) $item['theme'];
            }
            $id = mover::create_category($data);
            $map[(int) $item['id']] = $id;
            $created[$id] = true;
        }

        // The siblings each parent had before the undo, then the created ones inserted in creation order.
        $orders = [];
        foreach ($items as $item) {
            $id = $map[(int) $item['id']];
            $parent = $map[(int) $item['parent']] ?? (int) $item['parent'];
            if (!isset($orders[$parent])) {
                $orders[$parent] = array_values(array_filter(
                    tree::sibling_categories($parent),
                    static function (int $sibling) use ($created): bool {
                        return !isset($created[$sibling]);
                    }
                ));
            }
            $afterid = (int) ($item['afterid'] ?? 0);
            $afterid = $map[$afterid] ?? $afterid;
            $list = $orders[$parent];
            if ($afterid === 0) {
                $position = 0;
            } else {
                $index = array_search($afterid, $list, true);
                $position = $index === false ? count($list) : $index + 1;
            }
            array_splice($list, $position, 0, [$id]);
            $orders[$parent] = $list;
        }
        foreach ($orders as $parent => $list) {
            mover::apply_category_order((int) $parent, $list, false);
        }
        \core_course_category::resort_categories_cleanup();
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
                if (self::visibility_changed($payload)) {
                    self::restore_category_visibility($id, (bool) $from['visible']);
                }
                return;
            case 'move_course':
                $id = (int) $payload['id'];
                $from = $payload['from'];
                mover::move_course($id, (int) $from['category'], (int) $from['afterid']);
                if (self::visibility_changed($payload)) {
                    self::restore_course_visibility($id, (bool) $from['visible']);
                }
                return;
            case 'sort':
                // Courses first, then categories, and one site-wide cleanup at the end (see mover).
                foreach ($payload['courses'] ?? [] as $entry) {
                    mover::apply_course_order((int) $entry['categoryid'], self::ids($entry['before']), false);
                }
                foreach ($payload['categories'] ?? [] as $entry) {
                    mover::apply_category_order((int) $entry['parentid'], self::ids($entry['before']), false);
                }
                \core_course_category::resort_categories_cleanup(true);
                return;
            case 'rename':
                // Reverse order, so a chain such as B to C, then A to B, frees each name before it is reused.
                foreach (array_reverse($payload['items']) as $item) {
                    $before = $item['before'];
                    if ($item['type'] === 'category') {
                        mover::rename_category((int) $item['id'], (string) $before['name']);
                    } else {
                        $shortname = isset($before['shortname']) ? (string) $before['shortname'] : null;
                        mover::rename_course((int) $item['id'], (string) $before['fullname'], $shortname);
                    }
                }
                return;
            case 'visibility':
                foreach ($payload['items'] ?? [] as $item) {
                    mover::set_course_visibility((int) $item['id'], (bool) $item['before']);
                }
                return;
            case 'merge':
            case 'dedupe':
                self::restore_merge($payload);
                return;
            case 'delete':
                self::restore_delete($payload);
                return;
        }
        throw new \coding_exception('Unknown journal action: ' . $action);
    }

    /**
     * Whether the move itself changed the visibility (moving into a hidden parent hides the item).
     *
     * @param array $payload
     * @return bool
     */
    private static function visibility_changed(array $payload): bool {
        return isset($payload['from']['visible'], $payload['to']['visible'])
            && (int) $payload['from']['visible'] !== (int) $payload['to']['visible'];
    }

    /**
     * Shows or hides a category when its visibility differs, as moving into a hidden parent hides it.
     *
     * It is not shown again under a parent that is hidden now: that would expose hidden content.
     *
     * @param int $id
     * @param bool $visible
     */
    private static function restore_category_visibility(int $id, bool $visible): void {
        global $DB;
        $record = $DB->get_record('course_categories', ['id' => $id], 'id, parent, visible', MUST_EXIST);
        if ((bool) $record->visible === $visible) {
            return;
        }
        $parent = (int) $record->parent;
        if ($visible && $parent !== 0 && !$DB->get_field('course_categories', 'visible', ['id' => $parent])) {
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
     * It is not shown again in a category that is hidden now: that would expose hidden content.
     *
     * @param int $id
     * @param bool $visible
     */
    private static function restore_course_visibility(int $id, bool $visible): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $id], 'id, category, visible', MUST_EXIST);
        if ((bool) $course->visible === $visible) {
            return;
        }
        if ($visible && !$DB->get_field('course_categories', 'visible', ['id' => $course->category])) {
            return;
        }
        mover::set_course_visibility($id, $visible);
    }
}
