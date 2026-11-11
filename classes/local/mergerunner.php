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
 * Runs a merge of seed categories: creates the missing categories, moves the courses and records it.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Applies a merge preview that is still current, all or nothing.
 *
 * Every capability is checked before any write. Categories, moves and the journal row are written in one
 * transaction, so a failure leaves nothing behind. It never deletes a course or a category.
 */
class mergerunner {
    /** @var string[] Accepted row actions. */
    public const ROW_ACTIONS = ['move', 'skip'];

    /**
     * Runs a merge under the write lock.
     *
     * @param int $targetid
     * @param int[] $sourceids
     * @param array $rows List of ['courseid' => int, 'action' => 'move'|'skip']; plan rows not listed keep their
     *      default action.
     * @param string $hash Hash of the preview the user saw.
     * @return array ['status' => 'ok'|'stale', 'logid' => int, 'moved' => int, 'skipped' => int,
     *      'emptied' => [['id' => int, 'path' => string]]]; logid is 0 when nothing was recorded.
     * @throws \required_capability_exception When a category or course cannot be changed; nothing is written.
     * @throws \invalid_parameter_exception For an unknown course or action in $rows.
     */
    public static function run(int $targetid, array $sourceids, array $rows, string $hash): array {
        global $DB;
        // Up to mergeplan::MAX_ROWS courses are moved in one request.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $lock = guard::lock();
        try {
            $plan = mergeplan::build($targetid, $sourceids, true);
            if (!hash_equals($plan['hash'], $hash)) {
                return ['status' => 'stale', 'logid' => 0, 'moved' => 0, 'skipped' => 0, 'emptied' => []];
            }
            $planrows = self::actions($plan['rows'], $rows);
            $todo = array_values(array_filter($planrows, static function (array $row): bool {
                return $row['action'] === 'move';
            }));
            $skipped = count($planrows) - count($todo);
            if (!$todo) {
                return [
                    'status' => 'ok',
                    'logid' => 0,
                    'moved' => 0,
                    'skipped' => $skipped,
                    'emptied' => self::emptied($sourceids),
                ];
            }
            self::check($targetid, $sourceids, $todo);

            $transaction = $DB->start_delegated_transaction();
            try {
                [$todo, $created] = mover::create_categories($todo);
                $groups = [];
                $items = [];
                foreach ($todo as $row) {
                    $groups[(int) $row['destcategoryid']][] = (int) $row['courseid'];
                    $items[] = [
                        'id' => (int) $row['courseid'],
                        'fromcategory' => (int) $row['fromid'],
                        'tocategory' => (int) $row['destcategoryid'],
                    ];
                }
                $visiblebefore = self::visibility(array_column($items, 'id'));
                foreach ($groups as $categoryid => $ids) {
                    mover::move_courses_into($ids, $categoryid);
                }
                // Moving into a hidden category hides the course: recorded so undo can show it again.
                $visibleafter = self::visibility(array_column($items, 'id'));
                foreach ($items as &$item) {
                    $item['fromvisible'] = $visiblebefore[$item['id']];
                    $item['tovisible'] = $visibleafter[$item['id']];
                }
                unset($item);
                $payload = [
                    'targetid' => $targetid,
                    'sourceids' => array_map('intval', array_values($sourceids)),
                    'created' => $created,
                    'items' => $items,
                ];
                $logid = static::record_merge($targetid, $payload);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                guard::rollback($transaction, $e);
            }
            $emptied = self::emptied($sourceids);
        } finally {
            $lock->release();
        }
        return ['status' => 'ok', 'logid' => $logid, 'moved' => count($items), 'skipped' => $skipped, 'emptied' => $emptied];
    }

    /**
     * Applies the actions sent to the plan rows.
     *
     * @param array $planrows
     * @param array $rows
     * @return array Plan rows with the final action.
     */
    private static function actions(array $planrows, array $rows): array {
        $byid = [];
        foreach ($planrows as $index => $row) {
            $byid[$row['courseid']] = $index;
        }
        foreach ($rows as $row) {
            $courseid = (int) ($row['courseid'] ?? 0);
            $action = (string) ($row['action'] ?? '');
            if (!in_array($action, self::ROW_ACTIONS, true)) {
                throw new \invalid_parameter_exception('Invalid action: ' . $action);
            }
            if (!isset($byid[$courseid])) {
                throw new \invalid_parameter_exception('Course not in the merge: ' . $courseid);
            }
            $planrows[$byid[$courseid]]['action'] = $action;
        }
        return $planrows;
    }

    /**
     * Requires every capability the merge needs. It throws, so nothing is written.
     *
     * New categories inherit the permissions of the existing category they hang from, so that one is checked.
     *
     * @param int $targetid
     * @param int[] $sourceids
     * @param array $todo Rows to move, with 'anchor' and 'missing'.
     */
    private static function check(int $targetid, array $sourceids, array $todo): void {
        $categories = [$targetid => true];
        foreach ($sourceids as $sourceid) {
            $categories[(int) $sourceid] = true;
        }
        foreach ($todo as $row) {
            if ($row['missing']) {
                $categories[(int) $row['anchor']] = true;
            }
        }
        foreach (array_keys($categories) as $id) {
            mover::check_category($id);
        }
        foreach ($todo as $row) {
            mover::check_course((int) $row['courseid'], 'moodle/course:changecategory');
        }
    }

    /**
     * Visibility of some courses.
     *
     * @param int[] $ids
     * @return int[] Map id => 0|1.
     */
    private static function visibility(array $ids): array {
        global $DB;
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id, visible') as $course) {
                $result[(int) $course->id] = (int) $course->visible;
            }
        }
        return $result;
    }

    /**
     * Categories of the sources, sources included, that are empty now, deepest first.
     *
     * A category is empty when it has no courses and every subcategory is empty too, so deleting them in
     * this order leaves nothing behind. Read from the database after the moves.
     *
     * @param int[] $sourceids
     * @return array List of ['id' => int, 'path' => string].
     */
    private static function emptied(array $sourceids): array {
        global $DB;
        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name, path');
        $cats = [];
        $children = [];
        foreach ($records as $record) {
            $cats[(int) $record->id] = ['id' => (int) $record->id, 'parent' => (int) $record->parent, 'name' => $record->name];
            $children[(int) $record->parent][] = (int) $record->id;
        }
        $depth = [];
        $stack = array_map('intval', array_values($sourceids));
        while ($stack) {
            $id = array_pop($stack);
            if (!isset($records[$id]) || isset($depth[$id])) {
                continue;
            }
            $depth[$id] = substr_count($records[$id]->path, '/');
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        if (!$depth) {
            return [];
        }
        $withcourses = [];
        foreach (array_chunk(array_keys($depth), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            foreach ($DB->get_fieldset_sql("SELECT DISTINCT category FROM {course} WHERE category $insql", $params) as $id) {
                $withcourses[(int) $id] = true;
            }
        }
        $order = array_keys($depth);
        usort($order, static function ($a, $b) use ($depth): int {
            return [$depth[$b], $a] <=> [$depth[$a], $b];
        });
        $empty = [];
        $paths = [];
        $result = [];
        foreach ($order as $id) {
            $isempty = !isset($withcourses[$id]);
            foreach ($children[$id] ?? [] as $child) {
                $isempty = $isempty && !empty($empty[$child]);
            }
            if ($isempty) {
                $empty[$id] = true;
                $result[] = ['id' => $id, 'path' => cloneplan::path_of($id, $cats, $paths)];
            }
        }
        return $result;
    }

    /**
     * Records the merge in the journal. Runs inside the transaction; protected so the tests can make it fail.
     *
     * @param int $targetid
     * @param array $payload
     * @return int Journal id.
     */
    protected static function record_merge(int $targetid, array $payload): int {
        return journal::record('merge', self::summary($targetid, count($payload['items'])), $payload);
    }

    /**
     * Summary of the merge for the history, cut as journal::record() does.
     *
     * @param int $targetid
     * @param int $count Courses moved.
     * @return string
     */
    private static function summary(int $targetid, int $count): string {
        global $DB;
        $target = (string) $DB->get_field('course_categories', 'name', ['id' => $targetid], MUST_EXIST);
        $summary = get_string('summary_merge', 'tool_ceaorganizador', (object) ['target' => $target, 'count' => $count]);
        return \core_text::substr($summary, 0, journal::SUMMARY_MAX);
    }
}
