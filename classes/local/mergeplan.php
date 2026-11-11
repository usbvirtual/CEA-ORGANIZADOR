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
 * Preview of merging several seed categories into one.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Maps the courses of the source categories to mirrored paths under the target. It writes nothing.
 *
 * Repeated courses are found with the rules of duplicates, inside each resolved destination: the courses already
 * there count, and so do the other source courses that go to the same destination. In each group the most recently
 * modified course goes to (or stays in) the destination and the others go to the hidden «Repetidos» folder directly
 * under the target; a target course that loses gets its own row. Destinations inside that folder are not compared.
 */
class mergeplan {
    /** @var int Most courses a merge can have. */
    public const MAX_ROWS = 1000;

    /** @var int Most values in one IN clause. */
    private const CHUNK = 1000;

    /**
     * Builds the preview of a merge.
     *
     * Each row has 'action': 'move' (to the destination; for a course already in the target, it stays), 'repeated'
     * (to the «Repetidos» folder) or 'skip' (left in the source). Rows of a group share 'group' (the sorted ids of the
     * group joined by '-'), 'winner' marks the course that stays by default and 'reasons' the rules that matched.
     * 'existing' marks a course that is already in the target, 'visible' whether the course is visible: a visible
     * course is kept before a hidden one (duplicates::groups()).
     *
     * @param int $targetid Category that receives the courses.
     * @param int[] $sourceids Categories whose courses are moved, at any depth.
     * @param bool $details Adds to each row, for mergerunner: 'fromid' (current category), 'anchor' (deepest
     *      existing destination category), 'missing' (raw names to create below it) and 'hidden' (the destination is
     *      inside the «Repetidos» folder). The hash does not change.
     * @return array ['rows' => array[], 'emptied' => array[], 'folder' => ['id' => int (0 when it will be created),
     *      'visible' => bool (false when it will be created hidden), 'path' => string], 'hash' => string]
     */
    public static function build(int $targetid, array $sourceids, bool $details = false): array {
        global $DB;

        $sourceids = array_map('intval', array_values($sourceids));
        if (!$sourceids) {
            throw new \invalid_parameter_exception('No source categories');
        }
        if (count(array_unique($sourceids)) !== count($sourceids)) {
            throw new \invalid_parameter_exception('Repeated source category');
        }

        $fields = 'id, parent, name, path, sortorder, visible';
        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', $fields);
        $cats = [];
        $children = [];
        foreach ($records as $record) {
            $cats[(int) $record->id] = [
                'id' => (int) $record->id,
                'parent' => (int) $record->parent,
                'name' => $record->name,
                'path' => $record->path,
            ];
            $children[(int) $record->parent][] = (int) $record->id;
        }
        if (!isset($cats[$targetid])) {
            throw new \invalid_parameter_exception('Target category not found');
        }
        self::validate_sources($cats, $targetid, $sourceids);

        // Every category under a source, with the source it belongs to.
        $sourceof = [];
        foreach ($sourceids as $sourceid) {
            $stack = [$sourceid];
            while ($stack) {
                $current = array_pop($stack);
                if (isset($sourceof[$current])) {
                    continue;
                }
                $sourceof[$current] = $sourceid;
                foreach ($children[$current] ?? [] as $child) {
                    $stack[] = $child;
                }
            }
        }

        $courses = self::load_courses(array_keys($sourceof));
        if (count($courses) > self::MAX_ROWS) {
            throw new \moodle_exception('error_toomanymerge', 'tool_ceaorganizador', '', self::MAX_ROWS);
        }

        $paths = [];
        foreach ($courses as $course) {
            cloneplan::path_of((int) $course->category, $cats, $paths);
        }
        usort($courses, static function ($a, $b) use ($paths): int {
            return [$paths[(int) $a->category], (int) $a->sortorder, (int) $a->id]
                <=> [$paths[(int) $b->category], (int) $b->sortorder, (int) $b->id];
        });

        $index = cloner::index($cats);
        $folderkey = sorter::normalize(duplicates::FOLDER);
        $folderid = $index[$targetid][$folderkey] ?? 0;
        // A folder that exists and is visible would leave the moved courses visible: the panel warns about it.
        $folder = [
            'id' => $folderid,
            'visible' => $folderid ? (int) $records[$folderid]->visible === 1 : false,
            'path' => $folderid
                ? cloneplan::path_of($folderid, $cats, $paths)
                : cloneplan::path_of($targetid, $cats, $paths) . ' / ' . cloneplan::format(duplicates::FOLDER),
        ];

        // Resolved once per source category: every course of a category goes to the same place.
        $bycategory = [];
        $destinations = [];
        $rows = [];
        $candidates = [];
        foreach ($courses as $course) {
            $category = (int) $course->category;
            if (!isset($bycategory[$category])) {
                $bycategory[$category] = self::destination(
                    $category,
                    $cats,
                    $sourceof,
                    $targetid,
                    $index,
                    $paths,
                    $destinations
                );
            }
            $where = $bycategory[$category];
            $missing = $where['resolved']['missing'];
            $rows[] = [
                'courseid' => (int) $course->id,
                'fullname' => cloneplan::format($course->fullname),
                'visible' => (int) $course->visible === 1,
                'from' => $paths[$category],
                'to' => $destinations[$where['key']]['to'],
                'toid' => $missing ? 0 : $where['resolved']['existing'],
                'newcategories' => $destinations[$where['key']]['new'],
                'duplicate' => false,
                'action' => 'move',
                'group' => '',
                'existing' => false,
                'winner' => false,
                'reasons' => [],
                'fromid' => $category,
                'anchor' => $where['resolved']['existing'],
                'missing' => $missing,
                'hidden' => $where['hidden'],
            ];
            if (!$where['hidden']) {
                $candidates[$where['key']][] = [
                    'id' => (int) $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'timemodified' => (int) $course->timemodified,
                    'visible' => (int) $course->visible,
                    'row' => count($rows) - 1,
                ];
            }
        }

        self::add_target_courses($candidates, $rows);
        $rows = self::group_rows($rows, $candidates, $cats, $paths);
        if (count($rows) > self::MAX_ROWS) {
            throw new \moodle_exception('error_toomanymerge', 'tool_ceaorganizador', '', self::MAX_ROWS);
        }
        $emptied = self::emptied($cats, $children, $sourceids, $rows, $paths);

        $signature = [];
        foreach ($rows as $row) {
            $signature[] = [$row['courseid'], $row['toid'], $row['to'], $row['action'], $row['group'], $row['existing']];
        }
        $sorted = $sourceids;
        sort($sorted);
        $hash = sha1(json_encode([$targetid, $sorted, $folder['id'], $signature]));
        if (!$details) {
            foreach ($rows as &$row) {
                unset($row['fromid'], $row['anchor'], $row['missing'], $row['hidden']);
            }
            unset($row);
        }
        return ['rows' => $rows, 'emptied' => $emptied, 'folder' => $folder, 'hash' => $hash];
    }

    /**
     * Where the courses of a source category go.
     *
     * @param int $category
     * @param array $cats
     * @param int[] $sourceof Source of every category under a source.
     * @param int $targetid
     * @param array $index As cloner::index() returns it for $cats.
     * @param string[] $paths Memo of formatted paths.
     * @param array $destinations Memo of destination paths by key, changed in place.
     * @return array ['key' => string, 'resolved' => array, 'hidden' => bool]
     */
    private static function destination(
        int $category,
        array $cats,
        array $sourceof,
        int $targetid,
        array $index,
        array &$paths,
        array &$destinations
    ): array {
        $relative = [];
        for ($id = $category; $id !== $sourceof[$category]; $id = $cats[$id]['parent']) {
            array_unshift($relative, (string) $cats[$id]['name']);
        }
        $resolved = cloner::resolve($cats, $targetid, $relative, $index);
        $missing = $resolved['missing'];
        // Courses that need the same missing path share one destination, so they report it the same way.
        $key = $resolved['existing'] . '|' . implode('/', array_map([sorter::class, 'normalize'], $missing));
        if (!isset($destinations[$key])) {
            $dest = cloneplan::path_of($resolved['existing'], $cats, $paths);
            $new = [];
            foreach ($missing as $name) {
                $dest .= ' / ' . cloneplan::format($name);
                $new[] = $dest;
            }
            $destinations[$key] = ['to' => $dest, 'new' => $new];
        }
        // The folder of repeated courses is never compared, and what is created in it is hidden.
        $hidden = $relative && sorter::normalize($relative[0]) === sorter::normalize(duplicates::FOLDER);
        return ['key' => $key, 'resolved' => $resolved, 'hidden' => $hidden];
    }

    /**
     * Adds to the candidates of each existing destination the courses that are already there.
     *
     * @param array $candidates Destination key => candidates, changed in place; a target course has 'row' => -1
     *      and 'category'.
     * @param array $rows Rows so far, to find the existing destination of each key.
     */
    private static function add_target_courses(array &$candidates, array $rows): void {
        global $DB;
        $keyof = [];
        foreach ($candidates as $key => $list) {
            $row = $rows[$list[0]['row']];
            if ($row['toid'] > 0) {
                $keyof[$row['toid']] = $key;
            }
        }
        foreach (array_chunk(array_keys($keyof), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $records = $DB->get_records_sql(
                "SELECT id, category, fullname, shortname, timemodified, visible
                   FROM {course}
                  WHERE category $insql
               ORDER BY sortorder ASC, id ASC",
                $params
            );
            foreach ($records as $record) {
                $candidates[$keyof[(int) $record->category]][] = [
                    'id' => (int) $record->id,
                    'fullname' => $record->fullname,
                    'shortname' => $record->shortname,
                    'timemodified' => (int) $record->timemodified,
                    'visible' => (int) $record->visible,
                    'row' => -1,
                    'category' => (int) $record->category,
                ];
            }
        }
    }

    /**
     * Marks the groups of repeated courses and adds a row for each target course in a group.
     *
     * The rows of a group are kept together, at the place of its first source row, with the target courses last.
     *
     * @param array $rows
     * @param array $candidates
     * @param array $cats
     * @param string[] $paths Memo of formatted paths.
     * @return array The rows, in their final order.
     */
    private static function group_rows(array $rows, array $candidates, array $cats, array &$paths): array {
        $extra = [];
        foreach ($candidates as $list) {
            if (count($list) < 2) {
                continue;
            }
            $byid = array_column($list, null, 'id');
            foreach (duplicates::groups($list) as $group) {
                $fromsources = array_filter($group['courses'], static function (array $member) use ($byid): bool {
                    return $byid[$member['id']]['row'] >= 0;
                });
                // Repeated courses that are all in the target already are not the merge's business.
                if (!$fromsources) {
                    continue;
                }
                foreach ($group['courses'] as $member) {
                    $candidate = $byid[$member['id']];
                    $values = [
                        'duplicate' => true,
                        'action' => $member['keep'] ? 'move' : 'repeated',
                        'group' => $group['key'],
                        'winner' => $member['keep'],
                        'reasons' => $member['reasons'],
                    ];
                    if ($candidate['row'] >= 0) {
                        $rows[$candidate['row']] = $values + $rows[$candidate['row']];
                        continue;
                    }
                    $category = $candidate['category'];
                    $path = cloneplan::path_of($category, $cats, $paths);
                    $extra[] = $values + [
                        'courseid' => $candidate['id'],
                        'fullname' => cloneplan::format($candidate['fullname']),
                        'visible' => $candidate['visible'] === 1,
                        'from' => $path,
                        'to' => $path,
                        'toid' => $category,
                        'newcategories' => [],
                        'existing' => true,
                        'fromid' => $category,
                        'anchor' => $category,
                        'missing' => [],
                        'hidden' => false,
                    ];
                }
            }
        }
        // Each row sorts by the place of its group (its first source row), source rows first, then by its index.
        $all = array_merge($rows, $extra);
        $firstof = [];
        $rank = [];
        foreach ($all as $i => $row) {
            $existing = $row['existing'] ? 1 : 0;
            if ($row['group'] === '') {
                $rank[$i] = [$i, $existing, $i];
                continue;
            }
            // Every group has at least one source row, and source rows come before the extra ones.
            $firstof[$row['group']] = $firstof[$row['group']] ?? $i;
            $rank[$i] = [$firstof[$row['group']], $existing, $i];
        }
        $order = array_keys($all);
        usort($order, static function (int $a, int $b) use ($rank): int {
            return $rank[$a] <=> $rank[$b];
        });
        $result = [];
        foreach ($order as $i) {
            $result[] = $all[$i];
        }
        return $result;
    }

    /**
     * Rejects sources that equal the target, contain it, are inside it, or are nested in each other.
     *
     * @param array $cats
     * @param int $targetid
     * @param int[] $sourceids
     */
    private static function validate_sources(array $cats, int $targetid, array $sourceids): void {
        $targetpath = $cats[$targetid]['path'] . '/';
        foreach ($sourceids as $sourceid) {
            if (!isset($cats[$sourceid])) {
                throw new \invalid_parameter_exception('Source category not found');
            }
            $path = $cats[$sourceid]['path'] . '/';
            if (str_starts_with($targetpath, $path) || str_starts_with($path, $targetpath)) {
                throw new \invalid_parameter_exception('A source cannot be, contain or be inside the target');
            }
            foreach ($sourceids as $other) {
                if ($other !== $sourceid && isset($cats[$other]) && str_starts_with($path, $cats[$other]['path'] . '/')) {
                    throw new \invalid_parameter_exception('A source cannot be inside another source');
                }
            }
        }
    }

    /**
     * Courses in the given categories, without the site course.
     *
     * @param int[] $categoryids
     * @return \stdClass[]
     */
    private static function load_courses(array $categoryids): array {
        global $DB;
        $found = [];
        foreach (array_chunk($categoryids, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['site'] = SITEID;
            $records = $DB->get_records_sql(
                "SELECT id, category, sortorder, fullname, shortname, timemodified, visible
                   FROM {course}
                  WHERE category $insql AND id <> :site",
                $params
            );
            foreach ($records as $record) {
                $found[(int) $record->id] = $record;
            }
        }
        return array_values($found);
    }

    /**
     * Source categories left with no courses and no subcategories after the default actions, deepest first.
     *
     * @param array $cats
     * @param array $children
     * @param int[] $sourceids
     * @param array $rows Rows with 'fromid' and 'existing'.
     * @param string[] $paths Memo of formatted paths.
     * @return array List of ['id' => int, 'path' => string].
     */
    private static function emptied(
        array $cats,
        array $children,
        array $sourceids,
        array $rows,
        array &$paths
    ): array {
        $staying = [];
        foreach ($rows as $row) {
            if ($row['action'] === 'skip' && !$row['existing']) {
                $staying[(int) $row['fromid']] = true;
            }
        }
        $depth = [];
        $stack = $sourceids;
        while ($stack) {
            $id = array_pop($stack);
            $depth[$id] = substr_count($cats[$id]['path'], '/');
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        $order = array_keys($depth);
        usort($order, static function ($a, $b) use ($depth): int {
            return [$depth[$b], $a] <=> [$depth[$a], $b];
        });
        $empty = [];
        $result = [];
        foreach ($order as $id) {
            $isempty = !isset($staying[$id]);
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
}
