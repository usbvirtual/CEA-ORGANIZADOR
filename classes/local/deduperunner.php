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
 * Runs «Quitar repetidos»: moves the repeated courses to the hidden «Repetidos» folder and records it.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Applies a «Quitar repetidos» preview that is still current.
 *
 * Only the groups sent are touched: sure groups of the preview, and possible groups («Posibles repetidos») the user
 * confirmed, which are accepted only when they are in the possible list of that same preview. In each group, every
 * course but the one chosen to stay goes, flat, to the hidden folder «Repetidos» directly under the chosen category
 * it belongs to (created hidden when missing, reused when there). Moving into a hidden category hides the course.
 * Every capability is checked before any write; the writes follow batchrunner, one journal row for every chosen
 * category, so one undo. It never deletes a course.
 *
 * Undo (journal, action 'dedupe', same format as 'merge') moves the courses back, restores the visibility the move
 * changed and deletes each folder only when this batch created it and it is empty then.
 */
class deduperunner extends batchrunner {
    /** @var int Most courses moved in one batch. */
    public const MAX_MOVES = 1000;

    /** @var string Journal action. */
    protected const ACTION = 'dedupe';

    /**
     * Runs it under the write lock.
     *
     * @param int[] $categoryids Chosen categories, as for dedupeplan::build().
     * @param array $groups List of ['key' => string, 'keep' => int]: the groups to clean (sure or confirmed possible)
     *      and the course that stays in each. A group of the preview that is not listed is left untouched.
     * @param string $hash Hash of the preview the user saw.
     * @return array ['status' => 'ok'|'stale', 'logid' => int, 'moved' => int]; logid is 0 when nothing was recorded.
     * @throws \invalid_parameter_exception For an unknown or repeated group, a course outside its group, or a course
     *      that is no longer in the category of its group.
     * @throws \moodle_exception error_toomanydedupe beyond MAX_MOVES courses.
     * @throws \required_capability_exception When a category or course cannot be changed; nothing is written.
     * @throws partial_exception When it stops halfway; what moved is recorded and can be undone.
     */
    public static function run(array $categoryids, array $groups, string $hash): array {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $lock = guard::lock();
        try {
            $plan = dedupeplan::build($categoryids);
            if (!hash_equals($plan['hash'], $hash)) {
                return ['status' => 'stale', 'logid' => 0, 'moved' => 0];
            }
            $folders = array_column($plan['folders'], 'id', 'rootid');
            $todo = [];
            foreach (self::chosen(array_merge($plan['groups'], $plan['possible']), $groups) as [$group, $keep]) {
                $folderid = (int) $folders[$group['rootid']];
                foreach ($group['courses'] as $course) {
                    if ($course['courseid'] === $keep) {
                        continue;
                    }
                    $todo[] = [
                        'courseid' => $course['courseid'],
                        'fromid' => $group['categoryid'],
                        'anchor' => $folderid ?: $group['rootid'],
                        'missing' => $folderid ? [] : [duplicates::FOLDER],
                        'hidden' => true,
                    ];
                }
            }
            if (count($todo) > static::MAX_MOVES) {
                throw new \moodle_exception('error_toomanydedupe', 'tool_ceaorganizador', '', static::MAX_MOVES);
            }
            if (!$todo) {
                return ['status' => 'ok', 'logid' => 0, 'moved' => 0];
            }
            self::still_there($todo);
            self::check($plan['roots'], array_values(array_filter($folders)), $todo);
            $logid = static::write(['categoryids' => $plan['roots']], $todo);
        } finally {
            $lock->release();
        }
        return ['status' => 'ok', 'logid' => $logid, 'moved' => count($todo)];
    }

    /**
     * The groups of the plan the user sent, with the course that stays.
     *
     * @param array $plangroups Sure and possible groups of the plan.
     * @param array $groups
     * @return array List of [plan group, int course that stays].
     */
    private static function chosen(array $plangroups, array $groups): array {
        $bykey = array_column($plangroups, null, 'key');
        $result = [];
        foreach ($groups as $group) {
            $key = (string) ($group['key'] ?? '');
            $keep = (int) ($group['keep'] ?? 0);
            // Only groups of this very preview: no arbitrary course ids.
            if (!isset($bykey[$key])) {
                throw new \invalid_parameter_exception('Group not in the preview: ' . $key);
            }
            if (isset($result[$key])) {
                throw new \invalid_parameter_exception('Repeated group: ' . $key);
            }
            if (!in_array($keep, array_column($bykey[$key]['courses'], 'courseid'), true)) {
                throw new \invalid_parameter_exception('Course not in the group: ' . $keep);
            }
            $result[$key] = [$bykey[$key], $keep];
        }
        return array_values($result);
    }

    /**
     * Requires every course to move to be, right now, in the category of its group.
     *
     * The plan is built again under the lock and its hash compared, so this holds already; it is checked against the
     * database once more because a confirmed possible group is the user's word, not a rule.
     *
     * @param array $todo
     * @throws \invalid_parameter_exception When a course moved.
     */
    private static function still_there(array $todo): void {
        global $DB;
        $expected = array_column($todo, 'fromid', 'courseid');
        foreach (array_chunk(array_keys($expected), 1000) as $chunk) {
            foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id, category') as $course) {
                if ((int) $course->category !== (int) $expected[(int) $course->id]) {
                    throw new \invalid_parameter_exception('Course no longer in its category: ' . $course->id);
                }
                unset($expected[(int) $course->id]);
            }
        }
        if ($expected) {
            throw new \invalid_parameter_exception('Course not found: ' . array_key_first($expected));
        }
    }

    /**
     * Requires every capability. It throws, so nothing is written.
     *
     * moodle/category:manage on every chosen category (where the folders are created), on each folder that exists
     * and on the category every course leaves; moodle/course:changecategory on every course moved. Undo checks the
     * same categories.
     *
     * @param int[] $roots
     * @param int[] $folderids Folders that exist.
     * @param array $todo
     */
    private static function check(array $roots, array $folderids, array $todo): void {
        $categories = [];
        foreach (array_merge($roots, $folderids) as $id) {
            $categories[(int) $id] = true;
        }
        foreach ($todo as $row) {
            $categories[(int) $row['fromid']] = true;
        }
        foreach (array_keys($categories) as $id) {
            mover::check_category($id);
        }
        foreach ($todo as $row) {
            mover::check_course((int) $row['courseid'], 'moodle/course:changecategory');
        }
    }

    /**
     * Summary for the history, cut as journal::record() does.
     *
     * @param array $payload With 'categoryids' and the 'items' moved so far.
     * @return string
     */
    protected static function summary(array $payload): string {
        global $DB;
        $names = [];
        foreach ($payload['categoryids'] as $id) {
            $names[] = (string) $DB->get_field('course_categories', 'name', ['id' => (int) $id], MUST_EXIST);
        }
        $count = count($payload['items'] ?? []);
        $frame = get_string('summary_dedupe', 'tool_ceaorganizador', (object) ['category' => '', 'count' => $count]);
        $room = max(0, journal::SUMMARY_MAX - \core_text::strlen($frame));
        $summary = get_string('summary_dedupe', 'tool_ceaorganizador', (object) [
            'category' => journal::list_summary($names, $room),
            'count' => $count,
        ]);
        return \core_text::substr($summary, 0, journal::SUMMARY_MAX);
    }
}
