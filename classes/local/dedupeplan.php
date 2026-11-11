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
 * Preview of «Quitar repetidos»: the groups of repeated courses of a category and its subcategories.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Finds the repeated courses of a category and of all its subcategories. It writes nothing.
 *
 * Each category is compared only with itself: «Cálculo I» in two programmes are two different courses. A category
 * named «Repetidos» (normalized), and everything under it, is never analysed: that is where the repeated courses go.
 */
class dedupeplan {
    /** @var int Most courses one preview examines; a larger tree is refused, before its courses are loaded. */
    public const MAX_COURSES = 5000;

    /** @var int Most values in one IN clause. */
    private const CHUNK = 1000;

    /**
     * Builds the preview.
     *
     * @param int $categoryid
     * @return array ['groups' => [['categoryid' => int, 'path' => string (formatted), 'key' => string,
     *      'courses' => [['courseid' => int, 'fullname' => string (raw), 'shortname' => string (raw),
     *      'timemodified' => int, 'reasons' => string[], 'keep' => bool]]]], 'folder' => ['id' => int (0 when it will
     *      be created), 'path' => string (formatted)], 'hash' => string]
     * @throws \invalid_parameter_exception When the category does not exist.
     * @throws \moodle_exception error_toomanydedupecourses beyond MAX_COURSES courses to examine.
     */
    public static function build(int $categoryid): array {
        global $DB;
        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name');
        if (!isset($records[$categoryid])) {
            throw new \invalid_parameter_exception('Category not found');
        }
        $cats = [];
        $children = [];
        foreach ($records as $record) {
            $cats[(int) $record->id] = ['id' => (int) $record->id, 'parent' => (int) $record->parent, 'name' => $record->name];
            $children[(int) $record->parent][] = (int) $record->id;
        }
        $folderkey = sorter::normalize(duplicates::FOLDER);

        // Tree order, skipping every «Repetidos» folder and what is under it.
        $analysed = [];
        $stack = [$categoryid];
        while ($stack) {
            $id = array_pop($stack);
            if (sorter::normalize((string) $cats[$id]['name']) === $folderkey) {
                continue;
            }
            $analysed[] = $id;
            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = $child;
            }
        }

        if (self::count_courses($analysed) > static::MAX_COURSES) {
            throw new \moodle_exception('error_toomanydedupecourses', 'tool_ceaorganizador', '', static::MAX_COURSES);
        }
        $bycategory = self::load_courses($analysed);
        $paths = [];
        $groups = [];
        $signature = [];
        foreach ($analysed as $id) {
            $courses = $bycategory[$id] ?? [];
            if (count($courses) < 2) {
                continue;
            }
            $byid = array_column($courses, null, 'id');
            foreach (duplicates::groups($courses) as $group) {
                $list = [];
                foreach ($group['courses'] as $member) {
                    $course = $byid[$member['id']];
                    $list[] = [
                        'courseid' => $member['id'],
                        'fullname' => $course['fullname'],
                        'shortname' => $course['shortname'],
                        'timemodified' => $course['timemodified'],
                        'reasons' => $member['reasons'],
                        'keep' => $member['keep'],
                    ];
                }
                $groups[] = [
                    'categoryid' => $id,
                    'path' => cloneplan::path_of($id, $cats, $paths),
                    'key' => $group['key'],
                    'courses' => $list,
                ];
                $signature[] = [$id, $group['key']];
            }
        }

        $folderid = 0;
        foreach ($children[$categoryid] ?? [] as $child) {
            if (sorter::normalize((string) $cats[$child]['name']) === $folderkey) {
                $folderid = $child;
                break;
            }
        }
        $folder = [
            'id' => $folderid,
            'path' => $folderid
                ? cloneplan::path_of($folderid, $cats, $paths)
                : cloneplan::path_of($categoryid, $cats, $paths) . ' / ' . cloneplan::format(duplicates::FOLDER),
        ];
        // Ids only (see sortplan): the names are not signed, the groups and where they are are.
        $hash = sha1(json_encode([$categoryid, $folderid, $signature]));
        return ['groups' => $groups, 'folder' => $folder, 'hash' => $hash];
    }

    /**
     * How many courses some categories hold, without the site course.
     *
     * @param int[] $categoryids
     * @return int
     */
    private static function count_courses(array $categoryids): int {
        global $DB;
        $total = 0;
        foreach (array_chunk($categoryids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['site'] = SITEID;
            $total += $DB->count_records_select('course', "category $insql AND id <> :site", $params);
        }
        return $total;
    }

    /**
     * Courses of some categories, in their order, without the site course.
     *
     * @param int[] $categoryids
     * @return array Map category id => list of ['id', 'fullname', 'shortname', 'timemodified'].
     */
    private static function load_courses(array $categoryids): array {
        global $DB;
        $result = [];
        foreach (array_chunk($categoryids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['site'] = SITEID;
            $records = $DB->get_records_sql(
                "SELECT id, category, fullname, shortname, timemodified
                   FROM {course}
                  WHERE category $insql AND id <> :site
               ORDER BY sortorder ASC, id ASC",
                $params
            );
            foreach ($records as $record) {
                $result[(int) $record->category][] = [
                    'id' => (int) $record->id,
                    'fullname' => (string) $record->fullname,
                    'shortname' => (string) $record->shortname,
                    'timemodified' => (int) $record->timemodified,
                ];
            }
        }
        return $result;
    }
}
