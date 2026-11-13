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
 * «Ocultar todo» and «Mostrar todo»: a category with all its subcategories and all its courses.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Hides or shows whole subtrees: the chosen categories, every subcategory at any depth and every course below them.
 *
 * «Ocultar todo» leaves what core_course_category::hide_raw() would leave, on the whole subtree: every chosen
 * category ends 0/0 (visible/visibleold: «hidden on purpose»); every other category or course that was visible ends
 * 0/1 (hidden with its category, so the core «Show» of the category brings it back), and every one that was already
 * hidden but would come back with the core «Show» (0/1) ends 0/0, so it stays hidden then. Items already 0/0 are not
 * touched. Those silent visibleold fixes are written and recorded too, but they are not counted as hidden items.
 * «Mostrar todo» is not the core «Show»: every hidden item ends 1/1; the visible ones are not touched.
 *
 * The writes are bulk updates through mover::set_visibility_states(), CHUNK items per transaction (categories first,
 * then courses), with the journal row rewritten in the same transaction, as batchrunner does: a failure rolls back
 * only its chunk, and what was committed before stays recorded and can be undone (partial_exception); when the first
 * chunk fails, the empty row is deleted. After the chunks, once: the purge of the category and course caches, then one
 * course_category_updated per changed category (no course_updated per course, as the core hide() and show()). The
 * course_modinfo cache does not hold the visibility of the course, so it is not rebuilt.
 *
 * Journal action 'visibilitytree': {categoryids, show: 0|1, categories: [{id, visible, visibleold, tovisible,
 * tovisibleold}], courses: [{id, category, visible, visibleold, tovisible, tovisibleold}]}: the state of every item
 * before and after. Undo writes the state before back, in one transaction, and is refused when any item no longer
 * has the state after, or a course is no longer in its category.
 */
class visibilitytree {
    /** @var int Most courses written in one operation. */
    public const MAX_COURSES = 2000;

    /** @var int Most categories written in one operation. */
    public const MAX_CATEGORIES = 500;

    /** @var int Most items written in one transaction. */
    public const CHUNK = 200;

    /** @var string[]|null Raw names of the chosen categories, read once per write() for the summaries. */
    protected static $rootnames = null;

    /**
     * The state an item ends with.
     *
     * @param bool $show
     * @param bool $root Whether it is a chosen category.
     * @param int $visible Its visibility now.
     * @return int[] [visible, visibleold]
     */
    private static function target(bool $show, bool $root, int $visible): array {
        if ($show) {
            return [1, 1];
        }
        // As hide_raw(): the chosen category is hidden on purpose; the rest keeps what it had as visibleold.
        return [0, ($root || !$visible) ? 0 : 1];
    }

    /**
     * Adds an item to a plan list when its state changes.
     *
     * @param array $list
     * @param \stdClass $record With id, visible and visibleold (and category for courses).
     * @param bool $show
     * @param bool $root
     */
    private static function add(array &$list, \stdClass $record, bool $show, bool $root): void {
        $visible = (int) $record->visible;
        $visibleold = (int) $record->visibleold;
        [$tovisible, $tovisibleold] = self::target($show, $root, $visible);
        if ($show ? $visible === 1 : ($visible === 0 && $visibleold === $tovisibleold)) {
            return;
        }
        $item = [
            'visible' => $visible,
            'visibleold' => $visibleold,
            'tovisible' => $tovisible,
            'tovisibleold' => $tovisibleold,
        ];
        if (isset($record->category)) {
            $item = ['category' => (int) $record->category] + $item;
        }
        $list[(int) $record->id] = $item;
    }

    /**
     * What an operation would write, read from the database. It writes nothing.
     *
     * @param int[] $categoryids Chosen categories; one inside another chosen one is covered by it, a missing one is
     *      ignored.
     * @param bool $show True for «Mostrar todo», false for «Ocultar todo».
     * @return array ['roots' => int[] (chosen categories that exist and are not inside another one, sorted),
     *      'categories' => array map id => [visible, visibleold, tovisible, tovisibleold] of the categories written,
     *      'courses' => array (the same for the courses, with category first), 'changes' => ['categories' => int,
     *      'courses' => int] (items whose visibility changes; the rest are visibleold fixes), 'hash' => string]
     * @throws \moodle_exception error_toomanyvisibilitytree beyond MAX_CATEGORIES or MAX_COURSES items written.
     */
    public static function plan(array $categoryids, bool $show): array {
        global $DB;
        $roots = self::roots($categoryids);

        // Every category of the subtrees, with its state.
        $subtree = [];
        foreach ($roots as $id => $path) {
            $like = $DB->sql_like('path', ':path');
            $records = $DB->get_records_select(
                'course_categories',
                "id = :id OR $like",
                ['id' => $id, 'path' => $path . '/%'],
                '',
                'id, visible, visibleold'
            );
            foreach ($records as $record) {
                $subtree[(int) $record->id] = $record;
            }
        }
        $categories = [];
        foreach ($subtree as $id => $record) {
            self::add($categories, $record, $show, isset($roots[$id]));
        }

        // The courses are counted before they are loaded: the same condition as add().
        $where = $show ? 'visible = 0' : '(visible = 1 OR visibleold = 1)';
        $chunks = array_chunk(array_keys($subtree), 1000);
        $count = 0;
        foreach ($chunks as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cat');
            $params['site'] = SITEID;
            $count += $DB->count_records_select('course', "category $in AND $where AND id <> :site", $params);
        }
        if (count($categories) > static::MAX_CATEGORIES || $count > static::MAX_COURSES) {
            throw new \moodle_exception('error_toomanyvisibilitytree', 'tool_ceaorganizador', '', (object) [
                'categories' => count($categories),
                'courses' => $count,
                'maxcategories' => static::MAX_CATEGORIES,
                'maxcourses' => static::MAX_COURSES,
            ]);
        }
        $courses = [];
        foreach ($chunks as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cat');
            $params['site'] = SITEID;
            $records = $DB->get_records_select(
                'course',
                "category $in AND $where AND id <> :site",
                $params,
                '',
                'id, category, visible, visibleold'
            );
            foreach ($records as $record) {
                self::add($courses, $record, $show, false);
            }
        }
        ksort($categories);
        ksort($courses);
        $rootids = array_keys($roots);
        sort($rootids);
        $changes = ['categories' => 0, 'courses' => 0];
        $signed = ['categories' => [], 'courses' => []];
        foreach (['categories' => $categories, 'courses' => $courses] as $kind => $list) {
            foreach ($list as $id => $item) {
                $changes[$kind] += $item['visible'] !== $item['tovisible'] ? 1 : 0;
                $signed[$kind][] = [$id, $item['tovisible'], $item['tovisibleold']];
            }
        }
        // Ids and the states to write only: not how the items are named.
        $hash = sha1(json_encode([(int) $show, $rootids, $signed['categories'], $signed['courses']]));
        return [
            'roots' => $rootids,
            'categories' => $categories,
            'courses' => $courses,
            'changes' => $changes,
            'hash' => $hash,
        ];
    }

    /**
     * The chosen categories that exist, without those inside another chosen one.
     *
     * @param int[] $categoryids
     * @return string[] Map id => path.
     */
    private static function roots(array $categoryids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryids))));
        if (!$ids) {
            return [];
        }
        $paths = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->get_records_list('course_categories', 'id', $chunk, '', 'id, path') as $record) {
                $paths[(int) $record->id] = (string) $record->path;
            }
        }
        $roots = [];
        foreach ($paths as $id => $path) {
            $ancestors = array_map('intval', array_filter(explode('/', $path)));
            $inside = false;
            foreach ($ancestors as $ancestor) {
                if ($ancestor !== $id && isset($paths[$ancestor])) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                $roots[$id] = $path;
            }
        }
        return $roots;
    }

    /**
     * Applies a preview that is still current. The caller holds the write lock.
     *
     * moodle/category:manage on every category written and on its parent (as can_change_visibility() of the core
     * category), and moodle/course:visibility on every course written, are checked before any write: one missing
     * permission refuses the whole operation.
     *
     * @param int[] $categoryids
     * @param bool $show
     * @param string $hash Hash of the preview the user saw.
     * @return array ['status' => 'ok'|'stale', 'logid' => int (0 when nothing was written), 'categories' => int,
     *      'courses' => int] (the items whose visibility changed).
     * @throws partial_exception When a chunk fails after another was committed; what changed is recorded.
     */
    public static function run(array $categoryids, bool $show, string $hash): array {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $plan = static::plan($categoryids, $show);
        if (!hash_equals($plan['hash'], $hash)) {
            return ['status' => 'stale', 'logid' => 0, 'categories' => 0, 'courses' => 0];
        }
        mover::check_categories_and_parents(array_keys($plan['categories']));
        mover::check_courses(array_keys($plan['courses']), 'moodle/course:visibility');
        if (!$plan['categories'] && !$plan['courses']) {
            return ['status' => 'ok', 'logid' => 0, 'categories' => 0, 'courses' => 0];
        }
        $logid = static::write($plan, $show);
        return ['status' => 'ok', 'logid' => $logid] + $plan['changes'];
    }

    /**
     * Records the row, then writes the chunks, each with the row rewritten in its transaction.
     *
     * @param array $plan From plan().
     * @param bool $show
     * @return int Journal id.
     * @throws partial_exception When a chunk fails after another was committed.
     */
    protected static function write(array $plan, bool $show): int {
        global $DB;
        $payload = ['categoryids' => $plan['roots'], 'show' => (int) $show, 'categories' => [], 'courses' => []];
        // The names of the chosen categories, once for every summary of this write.
        static::$rootnames = self::names($plan['roots']);
        $changed = [];
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $logid = static::record_batch($payload);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                guard::rollback($transaction, $e);
            }

            $chunks = [];
            foreach (array_chunk($plan['categories'], static::CHUNK, true) as $chunk) {
                $chunks[] = ['categories', $chunk];
            }
            foreach (array_chunk($plan['courses'], static::CHUNK, true) as $chunk) {
                $chunks[] = ['courses', $chunk];
            }
            $total = count($plan['categories']) + count($plan['courses']);
            try {
                foreach ($chunks as [$kind, $chunk]) {
                    $transaction = $DB->start_delegated_transaction();
                    try {
                        $states = [];
                        $items = $payload[$kind];
                        foreach ($chunk as $id => $item) {
                            $states[$id] = ['visible' => $item['tovisible'], 'visibleold' => $item['tovisibleold']];
                            $items[] = ['id' => (int) $id] + $item;
                        }
                        mover::set_visibility_states($kind === 'categories' ? 'course_categories' : 'course', $states);
                        static::update_batch($logid, [$kind => $items] + $payload);
                        $transaction->allow_commit();
                    } catch (\Throwable $e) {
                        try {
                            guard::rollback($transaction, $e);
                        } catch (\Throwable $failure) {
                            $done = count($payload['categories']) + count($payload['courses']);
                            if ($done > 0) {
                                throw new partial_exception($logid, $done, $total, $failure);
                            }
                            // Nothing was written: no row that undoes nothing.
                            journal::discard($logid);
                            throw $failure;
                        }
                    }
                    $payload[$kind] = $items;
                    if ($kind === 'categories') {
                        foreach ($chunk as $id => $item) {
                            if ($item['visible'] !== $item['tovisible']) {
                                $changed[] = (int) $id;
                            }
                        }
                    }
                }
            } finally {
                // Once, whatever happened, and after the commits: first the caches, then the events, as hide() does.
                mover::purge_visibility_caches();
                mover::category_updated_events($changed);
            }
        } finally {
            static::$rootnames = null;
        }
        return $logid;
    }

    /**
     * Records the journal row. Runs inside the first transaction; protected so the tests can make it fail.
     *
     * @param array $payload
     * @return int Journal id.
     */
    protected static function record_batch(array $payload): int {
        return journal::record('visibilitytree', self::summary($payload, static::$rootnames), $payload);
    }

    /**
     * Rewrites the journal row after a chunk. Runs inside the chunk transaction; protected for the tests.
     *
     * @param int $logid
     * @param array $payload
     */
    protected static function update_batch(int $logid, array $payload): void {
        journal::update($logid, self::summary($payload, static::$rootnames), $payload);
    }

    /**
     * Raw names of some categories, in the given order; the id stands in for one that no longer exists.
     *
     * @param int[] $ids
     * @return string[]
     */
    private static function names(array $ids): array {
        global $DB;
        $records = $ids ? $DB->get_records_list('course_categories', 'id', $ids, '', 'id, name') : [];
        $names = [];
        foreach ($ids as $id) {
            $names[] = isset($records[$id]) ? (string) $records[$id]->name : (string) $id;
        }
        return $names;
    }

    /**
     * Summary for the history: the chosen categories and the counts so far (all the items written).
     *
     * @param array $payload
     * @param string[]|null $names Raw names of $payload['categoryids']; null reads them.
     * @return string
     */
    public static function summary(array $payload, ?array $names = null): string {
        $names = $names ?? self::names(array_map('intval', $payload['categoryids']));
        $a = (object) [
            'list' => '',
            'categories' => count($payload['categories'] ?? []),
            'courses' => count($payload['courses'] ?? []),
        ];
        $show = !empty($payload['show']);
        $frame = $show
            ? get_string('summary_showtree', 'tool_ceaorganizador', $a)
            : get_string('summary_hidetree', 'tool_ceaorganizador', $a);
        $a->list = journal::list_summary($names, max(0, journal::SUMMARY_MAX - \core_text::strlen($frame)));
        $summary = $show
            ? get_string('summary_showtree', 'tool_ceaorganizador', $a)
            : get_string('summary_hidetree', 'tool_ceaorganizador', $a);
        return \core_text::substr($summary, 0, journal::SUMMARY_MAX);
    }
}
