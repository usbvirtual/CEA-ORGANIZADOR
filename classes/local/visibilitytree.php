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
 * Only the items whose visibility changes are written and recorded; the others are left exactly as they are. The
 * values follow core_course_category::hide_raw(): a chosen category that is hidden gets visibleold 0 («hidden on
 * purpose»), a subcategory or a course hidden with it keeps visibleold 1, so the core «Show» of the category brings
 * them back. «Mostrar todo» is not the core «Show»: every item ends visible, with visibleold 1.
 *
 * The writes are bulk updates through mover::set_visibility_states(), CHUNK items per transaction (categories first,
 * then courses), with the journal row rewritten in the same transaction, as batchrunner does: a failure rolls back
 * only its chunk, and what was committed before stays recorded and can be undone (partial_exception). One
 * course_category_updated per changed category, no course_updated per course (as the core hide() and show()), and one
 * purge of the category and course caches at the end. The course_modinfo cache does not hold the visibility of the
 * course, so it is not rebuilt.
 *
 * Journal action 'visibilitytree': {categoryids, show: 0|1, categories: [{id, visible, visibleold}], courses: [{id,
 * visible, visibleold}]}, with the state of every changed item BEFORE the operation; after it, every item has
 * visible = show. Undo writes those states back, in one transaction, and is refused when any item no longer has the
 * visibility the operation left.
 */
class visibilitytree {
    /** @var int Most courses changed in one operation. */
    public const MAX_COURSES = 2000;

    /** @var int Most categories changed in one operation. */
    public const MAX_CATEGORIES = 500;

    /** @var int Most items written in one transaction. */
    public const CHUNK = 200;

    /**
     * What an operation would change, read from the database. It writes nothing.
     *
     * @param int[] $categoryids Chosen categories; one inside another chosen one is covered by it, a missing one is
     *      ignored.
     * @param bool $show True for «Mostrar todo», false for «Ocultar todo».
     * @return array ['roots' => int[] (chosen categories that exist and are not inside another one, sorted),
     *      'categories' => array map id => ['visible', 'visibleold'] (current state of the categories that change),
     *      'courses' => array (the same for the courses), 'hash' => string]
     * @throws \moodle_exception error_toomanyvisibilitytree beyond MAX_CATEGORIES or MAX_COURSES changes.
     */
    public static function plan(array $categoryids, bool $show): array {
        global $DB;
        $roots = self::roots($categoryids);
        $target = $show ? 1 : 0;

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
            if ((int) $record->visible !== $target) {
                $categories[$id] = ['visible' => (int) $record->visible, 'visibleold' => (int) $record->visibleold];
            }
        }

        // The courses are counted before they are loaded.
        $chunks = array_chunk(array_keys($subtree), 1000);
        $count = 0;
        foreach ($chunks as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cat');
            $params['target'] = $target;
            $params['site'] = SITEID;
            $count += $DB->count_records_select('course', "category $in AND visible <> :target AND id <> :site", $params);
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
            $params['target'] = $target;
            $params['site'] = SITEID;
            $records = $DB->get_records_select(
                'course',
                "category $in AND visible <> :target AND id <> :site",
                $params,
                '',
                'id, visible, visibleold'
            );
            foreach ($records as $record) {
                $courses[(int) $record->id] = ['visible' => (int) $record->visible, 'visibleold' => (int) $record->visibleold];
            }
        }
        ksort($categories);
        ksort($courses);
        $rootids = array_keys($roots);
        sort($rootids);
        // Ids only: what would change, not how it is named.
        $hash = sha1(json_encode([$target, $rootids, array_keys($categories), array_keys($courses)]));
        return ['roots' => $rootids, 'categories' => $categories, 'courses' => $courses, 'hash' => $hash];
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
     * moodle/category:manage on every category that changes and moodle/course:visibility on every course that
     * changes are checked before any write: one missing permission refuses the whole operation.
     *
     * @param int[] $categoryids
     * @param bool $show
     * @param string $hash Hash of the preview the user saw.
     * @return array ['status' => 'ok'|'stale', 'logid' => int (0 when nothing changed), 'categories' => int,
     *      'courses' => int]
     * @throws partial_exception When a chunk fails after another was committed; what changed is recorded.
     */
    public static function run(array $categoryids, bool $show, string $hash): array {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $plan = static::plan($categoryids, $show);
        if (!hash_equals($plan['hash'], $hash)) {
            return ['status' => 'stale', 'logid' => 0, 'categories' => 0, 'courses' => 0];
        }
        foreach (array_keys($plan['categories']) as $id) {
            mover::check_category($id);
        }
        mover::check_courses(array_keys($plan['courses']), 'moodle/course:visibility');
        if (!$plan['categories'] && !$plan['courses']) {
            return ['status' => 'ok', 'logid' => 0, 'categories' => 0, 'courses' => 0];
        }
        $logid = static::write($plan, $show);
        return [
            'status' => 'ok',
            'logid' => $logid,
            'categories' => count($plan['categories']),
            'courses' => count($plan['courses']),
        ];
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
        $target = $show ? 1 : 0;
        $roots = array_flip($plan['roots']);
        $payload = ['categoryids' => $plan['roots'], 'show' => $target, 'categories' => [], 'courses' => []];

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
                    foreach ($chunk as $id => $before) {
                        // The core hide() marks the chosen category hidden on purpose; what it hides with it, not.
                        $visibleold = ($show || !isset($roots[$id]) || $kind === 'courses') ? 1 : 0;
                        $states[$id] = ['visible' => $target, 'visibleold' => $visibleold];
                        $items[] = ['id' => (int) $id, 'visible' => $before['visible'], 'visibleold' => $before['visibleold']];
                    }
                    if ($kind === 'categories') {
                        mover::set_visibility_states('course_categories', $states);
                        mover::category_updated_events(array_keys($states));
                    } else {
                        mover::set_visibility_states('course', $states);
                    }
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
                        throw $failure;
                    }
                }
                $payload[$kind] = $items;
            }
        } finally {
            // Once, whatever happened: the chunks committed must be seen.
            mover::purge_visibility_caches();
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
        return journal::record('visibilitytree', self::summary($payload), $payload);
    }

    /**
     * Rewrites the journal row after a chunk. Runs inside the chunk transaction; protected for the tests.
     *
     * @param int $logid
     * @param array $payload
     */
    protected static function update_batch(int $logid, array $payload): void {
        journal::update($logid, self::summary($payload), $payload);
    }

    /**
     * Summary for the history: the chosen categories and the counts so far.
     *
     * @param array $payload
     * @return string
     */
    public static function summary(array $payload): string {
        global $DB;
        $names = [];
        foreach ($payload['categoryids'] as $id) {
            $name = $DB->get_field('course_categories', 'name', ['id' => (int) $id]);
            $names[] = $name === false ? (string) $id : (string) $name;
        }
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
