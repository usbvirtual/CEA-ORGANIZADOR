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
 * Applies a merge preview that is still current.
 *
 * Every capability is checked before any write. The writes follow batchrunner: the categories (the «Repetidos»
 * folder too, created hidden) and the journal row in one short transaction, then the moves by destination in
 * chunks, each one recorded as it commits. It never deletes a course or a category.
 *
 * Undo (journal, action 'merge') moves every course back to its original category, but not to its original
 * position inside it: move_courses() keeps no order, so the courses come back first in the category. It restores
 * the visibility the move changed (the «Repetidos» folder is hidden) and then deletes the created categories that
 * hold nothing (see mover::delete_empty_category()).
 */
class mergerunner extends batchrunner {
    /** @var string[] Accepted row actions. */
    public const ROW_ACTIONS = ['move', 'repeated', 'skip'];

    /** @var string Journal action. */
    protected const ACTION = 'merge';

    /**
     * Runs a merge under the write lock.
     *
     * @param int $targetid
     * @param int[] $sourceids
     * @param array $rows List of ['courseid' => int, 'action' => 'move'|'repeated'|'skip']; plan rows not listed keep
     *      their default action. For a course already in the target, 'move' and 'skip' leave it where it is.
     * @param string $hash Hash of the preview the user saw.
     * @param string[] $confirmed Keys of the possible groups («Posibles repetidos») the user confirmed; each must be
     *      in the possible list of that preview. A confirmed group must leave a course in the destination, as a sure
     *      one; the actions of its rows come in $rows.
     * @return array ['status' => 'ok'|'stale', 'logid' => int, 'moved' => int, 'repeated' => int, 'skipped' => int,
     *      'emptied' => [['id' => int, 'path' => string]]]; logid is 0 when nothing was recorded.
     * @throws \required_capability_exception When a category or course cannot be changed; nothing is written.
     * @throws \invalid_parameter_exception For an unknown course or action in $rows.
     * @throws \moodle_exception error_groupwithoutwinner when a group with courses in the target would leave none of
     *      them in the destination; nothing is written.
     * @throws partial_exception When it stops halfway; what moved is recorded and can be undone.
     */
    public static function run(int $targetid, array $sourceids, array $rows, string $hash, array $confirmed = []): array {
        // Up to mergeplan::MAX_ROWS courses are moved in one request.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $lock = guard::lock();
        try {
            $plan = mergeplan::build($targetid, $sourceids, true);
            if (!hash_equals($plan['hash'], $hash)) {
                return ['status' => 'stale', 'logid' => 0, 'moved' => 0, 'repeated' => 0, 'skipped' => 0, 'emptied' => []];
            }
            $planrows = self::actions($plan['rows'], $rows, self::confirmed($plan['possible'], $confirmed));
            $folderid = (int) $plan['folder']['id'];
            $todo = [];
            $moved = 0;
            $repeated = 0;
            $skipped = 0;
            foreach ($planrows as $row) {
                if ($row['action'] === 'repeated') {
                    // Straight into the hidden folder under the target, created when it is missing.
                    $todo[] = [
                        'anchor' => $folderid ?: $targetid,
                        'missing' => $folderid ? [] : [duplicates::FOLDER],
                        'hidden' => true,
                    ] + $row;
                    $repeated++;
                } else if ($row['existing']) {
                    // A course already in the target stays where it is.
                    continue;
                } else if ($row['action'] === 'skip') {
                    $skipped++;
                } else {
                    $todo[] = $row;
                    $moved++;
                }
            }
            if (!$todo) {
                return [
                    'status' => 'ok',
                    'logid' => 0,
                    'moved' => 0,
                    'repeated' => 0,
                    'skipped' => $skipped,
                    'emptied' => self::emptied($sourceids),
                ];
            }
            self::check($targetid, $sourceids, $todo);

            $logid = static::write([
                'targetid' => $targetid,
                'sourceids' => array_map('intval', array_values($sourceids)),
            ], $todo);
            $emptied = self::emptied($sourceids);
        } finally {
            $lock->release();
        }
        return [
            'status' => 'ok',
            'logid' => $logid,
            'moved' => $moved,
            'repeated' => $repeated,
            'skipped' => $skipped,
            'emptied' => $emptied,
        ];
    }

    /**
     * Applies the actions sent to the plan rows.
     *
     * @param array $planrows
     * @param array $rows
     * @param array $confirmed Confirmed possible groups, key => true.
     * @return array Plan rows with the final action.
     */
    private static function actions(array $planrows, array $rows, array $confirmed): array {
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
        self::check_winners($planrows, $confirmed);
        return $planrows;
    }

    /**
     * The confirmed possible groups, checked against the preview.
     *
     * @param array $possible Possible groups of the plan.
     * @param string[] $confirmed Keys sent.
     * @return array Map key => true.
     * @throws \invalid_parameter_exception For a key that is not a possible group of this preview.
     */
    private static function confirmed(array $possible, array $confirmed): array {
        $keys = array_flip(array_column($possible, 'key'));
        $result = [];
        foreach ($confirmed as $key) {
            $key = (string) $key;
            if (!isset($keys[$key])) {
                throw new \invalid_parameter_exception('Possible group not in the preview: ' . $key);
            }
            $result[$key] = true;
        }
        return $result;
    }

    /**
     * Rejects the actions when a group with courses in the target would leave none of them in the destination.
     *
     * A course already in the target stays unless it goes to «Repetidos»; a source course reaches the destination
     * only with 'move'. When the source winner is left in the source, or every member goes to «Repetidos», the
     * destination would lose the course: that is refused, before any write.
     *
     * @param array $planrows Plan rows with the final action.
     * @param array $confirmed Confirmed possible groups, key => true: they count as groups.
     * @throws \moodle_exception error_groupwithoutwinner, naming the course that stays by default.
     */
    private static function check_winners(array $planrows, array $confirmed): void {
        $groups = [];
        foreach ($planrows as $row) {
            if ($row['group'] !== '') {
                $groups[$row['group']][] = $row;
            } else if ($row['possible'] !== '' && isset($confirmed[$row['possible']])) {
                $groups['p' . $row['possible']][] = $row;
            }
        }
        foreach ($groups as $members) {
            $intarget = false;
            $kept = false;
            $name = $members[0]['fullname'];
            foreach ($members as $row) {
                $intarget = $intarget || $row['existing'];
                $kept = $kept || ($row['existing'] ? $row['action'] !== 'repeated' : $row['action'] === 'move');
                if ($row['winner']) {
                    $name = $row['fullname'];
                }
            }
            if ($intarget && !$kept) {
                throw new \moodle_exception('error_groupwithoutwinner', 'tool_ceaorganizador', '', $name);
            }
        }
    }

    /**
     * Requires every capability the merge needs. It throws, so nothing is written.
     *
     * moodle/category:manage on the target, every source, the category of every course moved and every
     * destination that exists; new categories inherit the permissions of the existing category they hang from,
     * so that one is checked. moodle/course:changecategory on every course moved.
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
            // The category each course leaves, and its destination: the existing one, or the anchor of the
            // categories to create. Undo checks the same categories.
            $categories[(int) $row['fromid']] = true;
            $categories[(int) $row['anchor']] = true;
        }
        foreach (array_keys($categories) as $id) {
            mover::check_category($id);
        }
        foreach ($todo as $row) {
            mover::check_course((int) $row['courseid'], 'moodle/course:changecategory');
        }
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
     * Summary of the merge for the history, cut as journal::record() does.
     *
     * @param array $payload With 'targetid' and the 'items' moved so far.
     * @return string
     */
    protected static function summary(array $payload): string {
        global $DB;
        $target = (string) $DB->get_field('course_categories', 'name', ['id' => (int) $payload['targetid']], MUST_EXIST);
        $summary = get_string('summary_merge', 'tool_ceaorganizador', (object) [
            'target' => $target,
            'count' => count($payload['items'] ?? []),
        ]);
        return \core_text::substr($summary, 0, journal::SUMMARY_MAX);
    }
}
