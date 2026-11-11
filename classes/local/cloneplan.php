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
 * Preview of cloning seed courses into an academic period.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Builds the list of copies to make, with their destinations and the errors that would stop them.
 */
class cloneplan {
    /** @var int Most rows a batch can have. */
    public const MAX_ROWS = 100;

    /** @var int Days a clone batch is looked at for copies still in flight. */
    public const INFLIGHT_DAYS = 30;

    /** @var int Most values in one IN clause. */
    private const CHUNK = 1000;

    /**
     * Builds the preview of a cloning batch. It writes nothing.
     *
     * @param array $items List of ['type' => 'category'|'course', 'id' => int]; categories expand to their courses
     *      and those of their descendants. Items that do not exist are ignored; ids below 1 are rejected.
     * @param int $periodid Category of the period that receives the copies.
     * @param string $label Period text such as "2027-01".
     * @param int $startdate Start date of the copies.
     * @param int $enddate End date of the copies.
     * @return array ['rows' => array[], 'total' => int, 'hash' => string]
     * @throws \moodle_exception error_backupbroken when the course backups of this Moodle cannot run.
     */
    public static function build(array $items, int $periodid, string $label, int $startdate, int $enddate): array {
        global $DB;

        // Preview and apply both pass here, so a Moodle whose backups cannot run refuses before any write.
        if (backupcheck::broken()) {
            throw new \moodle_exception('error_backupbroken', 'tool_ceaorganizador');
        }
        if (!$DB->record_exists('course_categories', ['id' => $periodid])) {
            throw new \invalid_parameter_exception('Period category not found');
        }
        if (cloner::period_label($label) !== $label) {
            throw new \invalid_parameter_exception('Invalid period label');
        }
        if ($startdate <= 0 || $enddate <= $startdate) {
            throw new \invalid_parameter_exception('Invalid dates');
        }
        foreach ($items as $item) {
            // Category 0 would expand to every course of the site.
            if ((int) ($item['id'] ?? 0) <= 0) {
                throw new \invalid_parameter_exception('Invalid item id');
            }
        }

        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name');
        $cats = [];
        $children = [];
        foreach ($records as $record) {
            $cats[(int) $record->id] = ['id' => (int) $record->id, 'parent' => (int) $record->parent, 'name' => $record->name];
            $children[(int) $record->parent][] = (int) $record->id;
        }
        $paths = [];
        $patterns = sorter::patterns();

        $courses = self::expand($items, $children);
        if (count($courses) > self::MAX_ROWS) {
            throw new \moodle_exception('error_toomanyclones', 'tool_ceaorganizador', '', self::MAX_ROWS);
        }
        foreach ($courses as $course) {
            self::path_of((int) $course->category, $cats, $paths);
        }
        usort($courses, static function ($a, $b) use ($paths): int {
            return [$paths[(int) $a->category], (int) $a->sortorder, (int) $a->id]
                <=> [$paths[(int) $b->category], (int) $b->sortorder, (int) $b->id];
        });

        $insideseeds = cloner::mirror($cats, $periodid, $patterns)['root'] !== null;
        $periodpath = self::path_of($periodid, $cats, $paths);
        $copycaps = \core_course\management\helper::get_course_copy_capabilities();

        $rows = [];
        foreach ($courses as $course) {
            \context_helper::preload_from_record($course);
            $rows[] = self::row($course, $cats, $paths, $patterns, $periodid, $periodpath, $label, $insideseeds, $copycaps);
        }
        self::flag_errors($rows);

        $signature = [];
        foreach ($rows as $row) {
            $signature[] = [$row['courseid'], $row['destpath'], $row['newshortname'], $row['error']];
        }
        $hash = sha1(json_encode([$periodid, $startdate, $enddate, $label, $signature]));
        return ['rows' => $rows, 'total' => count($rows), 'hash' => $hash];
    }

    /**
     * Loads the courses of the items, without repeats and without the site course.
     *
     * @param array $items
     * @param array $children Category ids by parent id.
     * @return \stdClass[] Course records with the context preload columns.
     */
    private static function expand(array $items, array $children): array {
        global $DB;
        $categoryids = [];
        $courseids = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            if ($item['type'] === 'category') {
                $stack = [$id];
                while ($stack) {
                    $current = array_pop($stack);
                    if (isset($categoryids[$current])) {
                        continue;
                    }
                    $categoryids[$current] = $current;
                    foreach ($children[$current] ?? [] as $child) {
                        $stack[] = $child;
                    }
                }
            } else {
                $courseids[$id] = $id;
            }
        }

        $ctxselect = \context_helper::get_preload_record_columns_sql('ctx');
        $found = [];
        foreach ([['category', $categoryids], ['id', $courseids]] as [$field, $ids]) {
            foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                $params['site'] = SITEID;
                $params['level'] = CONTEXT_COURSE;
                $result = $DB->get_records_sql(
                    "SELECT c.id, c.category, c.sortorder, c.fullname, c.shortname, $ctxselect
                       FROM {course} c
                       JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
                      WHERE c.$field $insql AND c.id <> :site",
                    $params
                );
                foreach ($result as $record) {
                    $found[(int) $record->id] = $record;
                }
            }
        }
        return array_values($found);
    }

    /**
     * Builds one row, with the errors that depend only on that course.
     *
     * @param \stdClass $course
     * @param array $cats
     * @param string[] $paths Memo of formatted paths.
     * @param array $patterns
     * @param int $periodid
     * @param string $periodpath
     * @param string $label
     * @param bool $insideseeds
     * @param string[] $copycaps
     * @return array
     */
    private static function row(
        \stdClass $course,
        array $cats,
        array &$paths,
        array $patterns,
        int $periodid,
        string $periodpath,
        string $label,
        bool $insideseeds,
        array $copycaps
    ): array {
        $mirror = cloner::mirror($cats, (int) $course->category, $patterns);
        $newcategories = [];
        if ($mirror['root'] === null) {
            $destid = $periodid;
            $destpath = $periodpath;
        } else {
            $resolved = cloner::resolve($cats, $periodid, $mirror['relative']);
            $destpath = self::path_of($resolved['existing'], $cats, $paths);
            foreach ($resolved['missing'] as $name) {
                $destpath .= ' / ' . self::format($name);
                $newcategories[] = $destpath;
            }
            $destid = $resolved['missing'] ? 0 : $resolved['existing'];
        }

        $newfullname = cloner::new_fullname($course->fullname, $label);
        $newshortname = cloner::new_shortname($course->shortname, $label);

        $error = null;
        if ($insideseeds) {
            $error = 'destinsideseeds';
        } else if (!has_all_capabilities($copycaps, \context_course::instance((int) $course->id))) {
            $error = 'nopermission';
        } else if (\core_text::strlen($newfullname) > 254 || \core_text::strlen($newshortname) > 254) {
            $error = 'toolong';
        }

        return [
            'courseid' => (int) $course->id,
            'fullname' => self::format($course->fullname),
            'shortname' => self::format($course->shortname),
            'destpath' => $destpath,
            'destcategoryid' => $destid,
            'newcategories' => $newcategories,
            'newfullname' => $newfullname,
            'newshortname' => $newshortname,
            'error' => $error,
        ];
    }

    /**
     * Sets the errors that need the database or the other rows: alreadycloned, shortnametaken, shortnameduplicate.
     *
     * @param array $rows Rows, changed in place.
     */
    private static function flag_errors(array &$rows): void {
        $names = [];
        $count = [];
        foreach ($rows as $row) {
            $names[$row['newshortname']] = $row['newshortname'];
            $key = \core_text::strtolower($row['newshortname']);
            $count[$key] = ($count[$key] ?? 0) + 1;
        }
        // One IN query per chunk of names; the existing courses by lowercase short name.
        $existing = self::taken_shortnames(array_values($names));
        foreach ($rows as &$row) {
            if ($row['error'] !== null) {
                continue;
            }
            $key = \core_text::strtolower($row['newshortname']);
            if (isset($existing[$key])) {
                $row['error'] = ($row['destcategoryid'] > 0 && in_array($row['destcategoryid'], $existing[$key], true))
                    ? 'alreadycloned' : 'shortnametaken';
            } else if ($count[$key] > 1) {
                $row['error'] = 'shortnameduplicate';
            }
        }
        unset($row);
    }

    /**
     * Short names already used, by lowercase name, with the categories of the courses that use them.
     *
     * Besides the courses that have the name, it counts the copies still in flight: until the cron runs
     * the restore, the new course is called «Copying course» and the name is only in the clone journal.
     *
     * @param string[] $names Raw short names to look up.
     * @return array Map lowercase short name => int[] category ids.
     */
    public static function taken_shortnames(array $names): array {
        global $DB;
        $existing = [];
        foreach (array_chunk($names, self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $records = $DB->get_records_sql("SELECT id, category, shortname FROM {course} WHERE shortname $insql", $params);
            foreach ($records as $record) {
                $existing[\core_text::strtolower($record->shortname)][] = (int) $record->category;
            }
        }
        foreach (self::inflight() as $key => $categories) {
            foreach ($categories as $category) {
                $existing[$key][] = $category;
            }
        }
        return $existing;
    }

    /**
     * Short names of the recent copies whose restore has not finished yet.
     *
     * @return array Map lowercase short name => int[] categories of the provisional courses.
     */
    private static function inflight(): array {
        global $DB;
        $logs = $DB->get_records_select(
            journal::TABLE,
            'action = :action AND timecreated >= :since',
            ['action' => 'clone', 'since' => time() - self::INFLIGHT_DAYS * DAYSECS],
            '',
            'id, payload'
        );
        $planned = [];
        foreach ($logs as $log) {
            $payload = json_decode($log->payload, true);
            foreach ((is_array($payload) ? $payload['items'] ?? [] : []) as $item) {
                $courseid = (int) ($item['newcourseid'] ?? 0);
                if ($courseid > 0 && isset($item['newshortname'])) {
                    $planned[$courseid] = (string) $item['newshortname'];
                }
            }
        }
        $inflight = [];
        foreach (array_chunk(array_keys($planned), self::CHUNK) as $chunk) {
            foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id, category, shortname') as $course) {
                $name = $planned[(int) $course->id];
                // Once restored the course carries the name and the plain lookup finds it.
                if ($course->shortname !== $name) {
                    $inflight[\core_text::strtolower($name)][] = (int) $course->category;
                }
            }
        }
        return $inflight;
    }

    /**
     * Formats a name for display.
     *
     * @param string $name
     * @return string
     */
    private static function format(string $name): string {
        return format_string($name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Formatted path of a category, memoised.
     *
     * @param int $id
     * @param array $cats
     * @param string[] $paths Memo, filled in place.
     * @return string
     */
    private static function path_of(int $id, array $cats, array &$paths): string {
        if (isset($paths[$id])) {
            return $paths[$id];
        }
        $chain = [];
        $current = $id;
        while ($current > 0 && isset($cats[$current]) && !isset($paths[$current]) && !in_array($current, $chain, true)) {
            $chain[] = $current;
            $current = (int) $cats[$current]['parent'];
        }
        $prefix = $paths[$current] ?? '';
        foreach (array_reverse($chain) as $categoryid) {
            $name = self::format($cats[$categoryid]['name']);
            $prefix = $prefix === '' ? $name : $prefix . ' / ' . $name;
            $paths[$categoryid] = $prefix;
        }
        return $paths[$id] ?? '';
    }
}
