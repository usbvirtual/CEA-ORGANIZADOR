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
 */
class mergeplan {
    /** @var int Most courses a merge can have. */
    public const MAX_ROWS = 1000;

    /** @var int Most values in one IN clause. */
    private const CHUNK = 1000;

    /**
     * Builds the preview of a merge.
     *
     * @param int $targetid Category that receives the courses.
     * @param int[] $sourceids Categories whose courses are moved, at any depth.
     * @param bool $details Adds to each row, for mergerunner: 'fromid' (current category), 'anchor' (deepest
     *      existing destination category) and 'missing' (raw names to create below it). The hash does not change.
     * @return array ['rows' => array[], 'emptied' => array[], 'hash' => string]
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

        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name, path, sortorder');
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

        $destinations = [];
        $rows = [];
        $slots = [];
        foreach ($courses as $course) {
            $category = (int) $course->category;
            $relative = [];
            for ($id = $category; $id !== $sourceof[$category]; $id = $cats[$id]['parent']) {
                array_unshift($relative, (string) $cats[$id]['name']);
            }
            $resolved = cloner::resolve($cats, $targetid, $relative);
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
            $rows[] = [
                'courseid' => (int) $course->id,
                'fullname' => cloneplan::format($course->fullname),
                'from' => $paths[$category],
                'to' => $destinations[$key]['to'],
                'toid' => $missing ? 0 : $resolved['existing'],
                'newcategories' => $destinations[$key]['new'],
                'duplicate' => false,
                'action' => 'move',
            ];
            if ($details) {
                $rows[count($rows) - 1] += ['fromid' => $category, 'anchor' => $resolved['existing'], 'missing' => $missing];
            }
            $slots[] = [$key, sorter::normalize($course->fullname)];
        }

        self::flag_duplicates($rows, $slots);
        $emptied = self::emptied($cats, $children, $sourceids, $rows, $courses, $paths);

        $signature = [];
        foreach ($rows as $row) {
            $signature[] = [$row['courseid'], $row['toid'], $row['to'], $row['duplicate']];
        }
        $sorted = $sourceids;
        sort($sorted);
        $hash = sha1(json_encode([$targetid, $sorted, $signature]));
        return ['rows' => $rows, 'emptied' => $emptied, 'hash' => $hash];
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
                "SELECT id, category, sortorder, fullname FROM {course} WHERE category $insql AND id <> :site",
                $params
            );
            foreach ($records as $record) {
                $found[(int) $record->id] = $record;
            }
        }
        return array_values($found);
    }

    /**
     * Marks the rows that repeat a course of the destination or an earlier row of the batch.
     *
     * @param array $rows Rows, changed in place.
     * @param array $slots Per row: [destination key, normalized full name].
     */
    private static function flag_duplicates(array &$rows, array $slots): void {
        global $DB;
        $toids = [];
        foreach ($rows as $row) {
            if ($row['toid'] > 0) {
                $toids[$row['toid']] = $row['toid'];
            }
        }
        $held = [];
        foreach (array_chunk(array_values($toids), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $records = $DB->get_records_sql("SELECT id, category, fullname FROM {course} WHERE category $insql", $params);
            foreach ($records as $record) {
                $held[(int) $record->category][sorter::normalize($record->fullname)][(int) $record->id] = true;
            }
        }
        $seen = [];
        foreach ($rows as $index => &$row) {
            [$key, $name] = $slots[$index];
            $others = $held[$row['toid']][$name] ?? [];
            unset($others[$row['courseid']]);
            $row['duplicate'] = ($row['toid'] > 0 && $others) || isset($seen[$key][$name]);
            $seen[$key][$name] = true;
            $row['action'] = $row['duplicate'] ? 'skip' : 'move';
        }
        unset($row);
    }

    /**
     * Source categories left with no courses and no subcategories after the default actions, deepest first.
     *
     * @param array $cats
     * @param array $children
     * @param int[] $sourceids
     * @param array $rows
     * @param \stdClass[] $courses Course records in row order.
     * @param string[] $paths Memo of formatted paths.
     * @return array List of ['id' => int, 'path' => string].
     */
    private static function emptied(
        array $cats,
        array $children,
        array $sourceids,
        array $rows,
        array $courses,
        array &$paths
    ): array {
        $staying = [];
        foreach ($rows as $index => $row) {
            if ($row['action'] === 'skip') {
                $staying[(int) $courses[$index]->category] = true;
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
