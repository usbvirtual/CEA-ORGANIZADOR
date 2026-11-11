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
 * Only the groups sent are touched; in each one, every course but the one chosen to stay goes, flat, to a single
 * hidden folder «Repetidos» directly under the analysed category (created hidden when missing, reused when there).
 * Moving into a hidden category hides the course. Every capability is checked before any write; the writes follow
 * batchrunner. It never deletes a course.
 *
 * Undo (journal, action 'dedupe', same format as 'merge') moves the courses back, restores the visibility the move
 * changed and deletes the folder only when this batch created it and it is empty then.
 */
class deduperunner extends batchrunner {
    /** @var int Most courses moved in one batch. */
    public const MAX_MOVES = 1000;

    /** @var string Journal action. */
    protected const ACTION = 'dedupe';

    /**
     * Runs it under the write lock.
     *
     * @param int $categoryid Category analysed.
     * @param array $groups List of ['key' => string, 'keep' => int]: the groups to clean and the course that stays in
     *      each. A group of the preview that is not listed is left untouched.
     * @param string $hash Hash of the preview the user saw.
     * @return array ['status' => 'ok'|'stale', 'logid' => int, 'moved' => int]; logid is 0 when nothing was recorded.
     * @throws \invalid_parameter_exception For an unknown or repeated group, or a course outside its group.
     * @throws \moodle_exception error_toomanydedupe beyond MAX_MOVES courses.
     * @throws \required_capability_exception When a category or course cannot be changed; nothing is written.
     */
    public static function run(int $categoryid, array $groups, string $hash): array {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $lock = guard::lock();
        try {
            $plan = dedupeplan::build($categoryid);
            if (!hash_equals($plan['hash'], $hash)) {
                return ['status' => 'stale', 'logid' => 0, 'moved' => 0];
            }
            $folderid = (int) $plan['folder']['id'];
            $todo = [];
            foreach (self::chosen($plan['groups'], $groups) as [$group, $keep]) {
                foreach ($group['courses'] as $course) {
                    if ($course['courseid'] === $keep) {
                        continue;
                    }
                    $todo[] = [
                        'courseid' => $course['courseid'],
                        'fromid' => $group['categoryid'],
                        'anchor' => $folderid ?: $categoryid,
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
            self::check($categoryid, $folderid, $todo);
            $logid = static::write(['categoryid' => $categoryid], $todo);
        } finally {
            $lock->release();
        }
        return ['status' => 'ok', 'logid' => $logid, 'moved' => count($todo)];
    }

    /**
     * The groups of the plan the user sent, with the course that stays.
     *
     * @param array $plangroups
     * @param array $groups
     * @return array List of [plan group, int course that stays].
     */
    private static function chosen(array $plangroups, array $groups): array {
        $bykey = array_column($plangroups, null, 'key');
        $result = [];
        foreach ($groups as $group) {
            $key = (string) ($group['key'] ?? '');
            $keep = (int) ($group['keep'] ?? 0);
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
     * Requires every capability. It throws, so nothing is written.
     *
     * moodle/category:manage on the analysed category (where the folder is created), on the folder when it exists
     * and on the category every course leaves; moodle/course:changecategory on every course moved. Undo checks the
     * same categories.
     *
     * @param int $categoryid
     * @param int $folderid 0 when it will be created.
     * @param array $todo
     */
    private static function check(int $categoryid, int $folderid, array $todo): void {
        $categories = [$categoryid => true];
        if ($folderid) {
            $categories[$folderid] = true;
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
     * @param array $payload With 'categoryid' and the 'items' moved so far.
     * @return string
     */
    protected static function summary(array $payload): string {
        global $DB;
        $category = (string) $DB->get_field('course_categories', 'name', ['id' => (int) $payload['categoryid']], MUST_EXIST);
        $summary = get_string('summary_dedupe', 'tool_ceaorganizador', (object) [
            'category' => $category,
            'count' => count($payload['items'] ?? []),
        ]);
        return \core_text::substr($summary, 0, journal::SUMMARY_MAX);
    }
}
