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
 * Preview of «Quitar repetidos»: the groups of repeated courses of some categories and their subcategories.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Finds the repeated courses of one or more categories and of all their subcategories. It writes nothing.
 *
 * Each category is compared only with itself: «Cálculo I» in two programmes are two different courses. A category
 * named «Repetidos» (normalized), and everything under it, is never analysed: that is where the repeated courses go.
 * A chosen category inside another chosen one is covered by it and is skipped. Each chosen category (root) has its
 * own «Repetidos» folder directly under it.
 */
class dedupeplan {
    /** @var int Most courses one preview examines; a larger tree is refused, before its courses are loaded. */
    public const MAX_COURSES = 5000;

    /** @var int Most values in one IN clause. */
    private const CHUNK = 1000;

    /**
     * Builds the preview.
     *
     * @param int[] $categoryids Chosen categories; one inside another chosen one is skipped.
     * @return array ['roots' => int[], 'groups' => [group], 'possible' => [group + 'reason' => string],
     *      'folders' => [['rootid' => int, 'id' => int (0 when it will be created), 'visible' => bool (false when it
     *      will be created, since it is created hidden), 'path' => string (formatted)]], 'hash' => string].
     *      A group is ['rootid' => int, 'categoryid' => int, 'path' => string (formatted), 'key' => string,
     *      'courses' => [['courseid' => int, 'fullname' => string (raw), 'shortname' => string (raw),
     *      'timemodified' => int, 'visible' => bool, 'activities' => int, 'reasons' => string[], 'keep' => bool]]].
     * @throws \invalid_parameter_exception When there is no category or one does not exist.
     * @throws \moodle_exception error_toomanydedupecourses beyond MAX_COURSES courses to examine.
     */
    public static function build(array $categoryids): array {
        global $DB;
        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name, path, visible');
        $roots = self::roots($records, $categoryids);
        $cats = [];
        $children = [];
        foreach ($records as $record) {
            $cats[(int) $record->id] = ['id' => (int) $record->id, 'parent' => (int) $record->parent, 'name' => $record->name];
            $children[(int) $record->parent][] = (int) $record->id;
        }
        $folderkey = sorter::normalize(duplicates::FOLDER);

        // Tree order, skipping every «Repetidos» folder and what is under it.
        $rootof = [];
        foreach ($roots as $root) {
            $stack = [$root];
            while ($stack) {
                $id = array_pop($stack);
                if (sorter::normalize((string) $cats[$id]['name']) === $folderkey) {
                    continue;
                }
                $rootof[$id] = $root;
                foreach (array_reverse($children[$id] ?? []) as $child) {
                    $stack[] = $child;
                }
            }
        }

        if (self::count_courses(array_keys($rootof)) > static::MAX_COURSES) {
            throw new \moodle_exception('error_toomanydedupecourses', 'tool_ceaorganizador', '', static::MAX_COURSES);
        }
        $bycategory = self::load_courses(array_keys($rootof));
        $ids = [];
        foreach ($bycategory as $list) {
            foreach ($list as $course) {
                $ids[] = $course['id'];
            }
        }
        $activities = self::activities($ids);
        $paths = [];
        $groups = [];
        $possible = [];
        $signature = [];
        foreach ($rootof as $id => $root) {
            $courses = $bycategory[$id] ?? [];
            if (count($courses) < 2) {
                continue;
            }
            foreach ($courses as &$course) {
                $course['activities'] = $activities[$course['id']] ?? 0;
            }
            unset($course);
            $byid = array_column($courses, null, 'id');
            $sure = duplicates::groups($courses);
            foreach ($sure as $group) {
                $groups[] = self::describe($group, $byid, $root, $id, $cats, $paths);
                $signature[] = [$id, $group['key']];
            }
            foreach (duplicates::candidates($courses, $sure) as $group) {
                $possible[] = self::describe($group, $byid, $root, $id, $cats, $paths) + ['reason' => $group['reason']];
                $signature[] = [$id, $group['key'], $group['reason']];
            }
        }

        $folders = [];
        foreach ($roots as $root) {
            $folderid = 0;
            foreach ($children[$root] ?? [] as $child) {
                if (sorter::normalize((string) $cats[$child]['name']) === $folderkey) {
                    $folderid = $child;
                    break;
                }
            }
            // A folder that exists and is visible would leave the moved courses visible: the panel warns about it.
            $folders[] = [
                'rootid' => $root,
                'id' => $folderid,
                'visible' => $folderid ? (int) $records[$folderid]->visible === 1 : false,
                'path' => $folderid
                    ? cloneplan::path_of($folderid, $cats, $paths)
                    : cloneplan::path_of($root, $cats, $paths) . ' / ' . cloneplan::format(duplicates::FOLDER),
            ];
        }
        // Ids only (see sortplan): the names are not signed, the groups, the possible groups and where they are are.
        $sorted = $roots;
        sort($sorted);
        $hash = sha1(json_encode([$sorted, array_column($folders, 'id', 'rootid'), $signature]));
        return ['roots' => $roots, 'groups' => $groups, 'possible' => $possible, 'folders' => $folders, 'hash' => $hash];
    }

    /**
     * Number of activities of some courses: course modules not being deleted, one grouped query per chunk.
     *
     * @param int[] $courseids
     * @return int[] Map course id => count; a course with none is missing.
     */
    public static function activities(array $courseids): array {
        global $DB;
        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $courseids))), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $counts = $DB->get_records_sql_menu(
                "SELECT course, COUNT(1)
                   FROM {course_modules}
                  WHERE course $insql AND deletioninprogress = 0
               GROUP BY course",
                $params
            );
            foreach ($counts as $courseid => $count) {
                $result[(int) $courseid] = (int) $count;
            }
        }
        return $result;
    }

    /**
     * The chosen categories that are not inside another chosen one, in tree order.
     *
     * @param array $records Category records by id, in tree order, with 'path'.
     * @param int[] $categoryids
     * @return int[]
     * @throws \invalid_parameter_exception When the list is empty or a category does not exist.
     */
    private static function roots(array $records, array $categoryids): array {
        $chosen = [];
        foreach ($categoryids as $id) {
            $id = (int) $id;
            if (!isset($records[$id])) {
                throw new \invalid_parameter_exception('Category not found');
            }
            $chosen[$id] = $records[$id]->path . '/';
        }
        if (!$chosen) {
            throw new \invalid_parameter_exception('No category');
        }
        $roots = [];
        foreach (array_keys($records) as $id) {
            $id = (int) $id;
            if (!isset($chosen[$id])) {
                continue;
            }
            foreach ($chosen as $other => $path) {
                if ($other !== $id && str_starts_with($chosen[$id], $path)) {
                    continue 2;
                }
            }
            $roots[] = $id;
        }
        return $roots;
    }

    /**
     * A group as the preview returns it.
     *
     * @param array $group As duplicates::groups() returns it.
     * @param array $byid Courses of the category by id.
     * @param int $root Chosen category it belongs to.
     * @param int $categoryid
     * @param array $cats
     * @param string[] $paths Memo of formatted paths.
     * @return array
     */
    private static function describe(array $group, array $byid, int $root, int $categoryid, array $cats, array &$paths): array {
        $list = [];
        foreach ($group['courses'] as $member) {
            $course = $byid[$member['id']];
            $list[] = [
                'courseid' => $member['id'],
                'fullname' => $course['fullname'],
                'shortname' => $course['shortname'],
                'timemodified' => $course['timemodified'],
                'visible' => $course['visible'] === 1,
                'activities' => $course['activities'],
                'reasons' => $member['reasons'],
                'keep' => $member['keep'],
            ];
        }
        return [
            'rootid' => $root,
            'categoryid' => $categoryid,
            'path' => cloneplan::path_of($categoryid, $cats, $paths),
            'key' => $group['key'],
            'courses' => $list,
        ];
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
     * @return array Map category id => list of ['id', 'fullname', 'shortname', 'timemodified', 'visible'].
     */
    private static function load_courses(array $categoryids): array {
        global $DB;
        $result = [];
        foreach (array_chunk($categoryids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['site'] = SITEID;
            $records = $DB->get_records_sql(
                "SELECT id, category, fullname, shortname, timemodified, visible
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
                    'visible' => (int) $record->visible,
                ];
            }
        }
        return $result;
    }
}
