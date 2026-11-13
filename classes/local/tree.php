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
 * Reads the category tree and the courses.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Reads the category tree, with paths, sort recommendations and counts of repeated courses, and the courses.
 *
 * The repeated courses follow the rules of «Quitar repetidos» (duplicates, dedupeplan): each category is compared only
 * with itself, and a «Repetidos» folder and everything under it are never analysed. It writes nothing.
 */
class tree {
    /**
     * Formats a name for display and for the sorter.
     *
     * @param string $name
     * @return string
     */
    private static function format(string $name): string {
        return format_string($name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Loads all categories grouped by parent, each group ordered by sortorder.
     *
     * @return array[] Records grouped by parent id.
     */
    private static function load_children(): array {
        global $DB;
        $records = $DB->get_records(
            'course_categories',
            null,
            'sortorder ASC, id ASC',
            'id, parent, name, sortorder, visible, depth, coursecount'
        );
        $byparent = [];
        foreach ($records as $record) {
            $byparent[(int) $record->parent][] = $record;
        }
        return $byparent;
    }

    /**
     * Loads the courses grouped by category, in sortorder: one query for the whole site.
     *
     * @return array [array[] lists of ['id' => int, 'name' => string (formatted)] by category id, for the sorter,
     *      array[] lists of ['id' => int, 'fullname' => string, 'shortname' => string] (raw) by category id, for
     *      duplicates::repeated_count()].
     */
    private static function load_courses_by_category(): array {
        global $DB;
        $records = $DB->get_records_sql(
            'SELECT id, category, fullname, shortname
               FROM {course}
              WHERE id <> :site
           ORDER BY category, sortorder, id',
            ['site' => SITEID]
        );
        $bycategory = [];
        $raw = [];
        foreach ($records as $record) {
            $categoryid = (int) $record->category;
            $bycategory[$categoryid][] = [
                'id' => (int) $record->id,
                'name' => self::format($record->fullname),
            ];
            $raw[$categoryid][] = [
                'id' => (int) $record->id,
                'fullname' => (string) $record->fullname,
                'shortname' => (string) $record->shortname,
            ];
        }
        return [$bycategory, $raw];
    }

    /**
     * Whether a category name is the «Repetidos» folder (normalized, so «repetidos» or «REPETIDOS» are too).
     *
     * @param string $name Raw name.
     * @return bool
     */
    private static function is_folder(string $name): bool {
        return sorter::normalize($name) === sorter::normalize(duplicates::FOLDER);
    }

    /**
     * Whether the children of a parent differ from the proposed order.
     *
     * @param array $children Category records of the parent.
     * @param bool $toplevel
     * @param array $patterns
     * @return bool
     */
    private static function children_differ(array $children, bool $toplevel, array $patterns): bool {
        $items = [];
        $current = [];
        foreach ($children as $child) {
            $items[] = ['id' => (int) $child->id, 'name' => self::format($child->name)];
            $current[] = (int) $child->id;
        }
        return sorter::differs($current, sorter::propose($items, $toplevel, $patterns));
    }

    /**
     * Whether the top-level categories differ from the proposed order.
     *
     * @return bool
     */
    public static function root_needsort(): bool {
        $byparent = self::load_children();
        return self::children_differ($byparent[0] ?? [], true, sorter::patterns());
    }

    /**
     * Lists all categories in tree order: parent first, then its children by sortorder.
     *
     * 'dupcount' is the number of repeated courses directly in the category (in each sure group of
     * duplicates::groups(), every course but the one that stays; possible groups do not count) and 'dupcounttotal'
     * adds those of all its subcategories. Both are 0 for a «Repetidos» folder and everything under it, which never
     * count.
     *
     * @return array[] Rows with id, parent, name, path, depth, sortorder, visible, coursecount, needsort, dupcount
     *      and dupcounttotal.
     */
    public static function categories(): array {
        return self::overview()['categories'];
    }

    /**
     * The categories of categories() and the flag of root_needsort(), reading the categories once.
     *
     * @return array ['categories' => array[] as categories(), 'rootneedsort' => bool as root_needsort()]
     */
    public static function overview(): array {
        $byparent = self::load_children();
        [$bycourses, $raw] = self::load_courses_by_category();
        $patterns = sorter::patterns();
        $rows = [];
        self::walk(0, '', $byparent, $bycourses, $raw, $patterns, false, $rows);
        return [
            'categories' => $rows,
            'rootneedsort' => self::children_differ($byparent[0] ?? [], true, $patterns),
        ];
    }

    /**
     * Walks the tree depth first.
     *
     * @param int $parentid
     * @param string $parentpath
     * @param array $byparent
     * @param array $bycourses Formatted course names by category, for the sorter.
     * @param array $raw Raw course names by category, for the repeated courses.
     * @param array $patterns
     * @param bool $inrepeated The parent is a «Repetidos» folder or lies under one.
     * @param array $rows Output rows.
     * @return int Repeated courses of the children of the parent and of all their subcategories.
     */
    private static function walk(
        int $parentid,
        string $parentpath,
        array $byparent,
        array $bycourses,
        array $raw,
        array $patterns,
        bool $inrepeated,
        array &$rows
    ): int {
        $total = 0;
        foreach ($byparent[$parentid] ?? [] as $category) {
            $id = (int) $category->id;
            $name = self::format($category->name);
            $path = $parentpath === '' ? $name : $parentpath . ' / ' . $name;
            $children = $byparent[$id] ?? [];
            $courses = $bycourses[$id] ?? [];
            $currentcourses = array_column($courses, 'id');
            $needsort = self::children_differ($children, false, $patterns)
                || sorter::differs($currentcourses, sorter::propose_courses($courses));
            $excluded = $inrepeated || self::is_folder((string) $category->name);
            $dupcount = $excluded ? 0 : duplicates::repeated_count($raw[$id] ?? []);
            $index = count($rows);
            $rows[] = [
                'id' => $id,
                'parent' => (int) $category->parent,
                'name' => $name,
                'path' => $path,
                'depth' => (int) $category->depth,
                'sortorder' => (int) $category->sortorder,
                'visible' => (bool) $category->visible,
                'coursecount' => (int) $category->coursecount,
                'needsort' => $needsort,
                'dupcount' => $dupcount,
                'dupcounttotal' => 0,
            ];
            $below = self::walk($id, $path, $byparent, $bycourses, $raw, $patterns, $excluded, $rows);
            $rows[$index]['dupcounttotal'] = $excluded ? 0 : $dupcount + $below;
            $total += $rows[$index]['dupcounttotal'];
        }
        return $total;
    }

    /**
     * Maps category ids to their formatted paths.
     *
     * Reads only the category names and parents (no courses, no sort recommendations),
     * and formats only the categories on the way to the requested ids.
     *
     * @param int[] $ids Category ids whose paths are needed.
     * @return string[] Paths by category id.
     */
    private static function paths(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name');
        $paths = [];
        foreach ($ids as $id) {
            self::path_of((int) $id, $records, $paths);
        }
        return $paths;
    }

    /**
     * Builds the formatted path of one category, memoising its ancestors.
     *
     * @param int $id
     * @param \stdClass[] $records Category records by id.
     * @param string[] $paths Memo of paths by id, filled in place.
     * @return string The path, or '' when the category does not exist.
     */
    private static function path_of(int $id, array $records, array &$paths): string {
        if (isset($paths[$id])) {
            return $paths[$id];
        }
        if (!isset($records[$id])) {
            return '';
        }
        // Iterate up to the root to avoid deep recursion; stop on a cycle or a missing parent.
        $chain = [];
        $seen = [];
        $current = $id;
        while ($current > 0 && isset($records[$current]) && !isset($paths[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $chain[] = $current;
            $current = (int) $records[$current]->parent;
        }
        $prefix = $paths[$current] ?? '';
        foreach (array_reverse($chain) as $categoryid) {
            $name = self::format($records[$categoryid]->name);
            $prefix = $prefix === '' ? $name : $prefix . ' / ' . $name;
            $paths[$categoryid] = $prefix;
        }
        return $paths[$id];
    }

    /**
     * Lists courses of a category, or of the whole site when the id is 0.
     *
     * @param int $categoryid
     * @param string $search Case-insensitive text in the full or short name.
     * @param int $page Zero-based page.
     * @param int $perpage
     * @return array With 'courses' and the 'total' count. Each course carries 'dup', 'dupreasons' and 'dupbase' (see
     *      duplicate_marks()); in a site-wide list (id 0) they are always empty.
     */
    public static function courses(int $categoryid, string $search = '', int $page = 0, int $perpage = 100): array {
        global $DB;
        $where = ['c.id <> :site'];
        $params = ['site' => SITEID];
        if ($categoryid > 0) {
            $where[] = 'c.category = :category';
            $params['category'] = $categoryid;
        }
        $search = trim($search);
        if ($search !== '') {
            $escaped = '%' . $DB->sql_like_escape($search) . '%';
            $where[] = '(' . $DB->sql_like('c.fullname', ':searchfull', false) . ' OR '
                . $DB->sql_like('c.shortname', ':searchshort', false) . ')';
            $params['searchfull'] = $escaped;
            $params['searchshort'] = $escaped;
        }
        $wheresql = implode(' AND ', $where);
        $order = $categoryid > 0 ? 'c.sortorder, c.id' : 'c.category, c.sortorder, c.id';

        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {course} c WHERE $wheresql", $params);
        $records = $DB->get_records_sql(
            "SELECT c.id, c.category, c.fullname, c.shortname, c.sortorder, c.visible
               FROM {course} c
              WHERE $wheresql
           ORDER BY $order",
            $params,
            max(0, $page) * $perpage,
            $perpage
        );

        $categoryids = [];
        foreach ($records as $record) {
            $categoryids[(int) $record->category] = (int) $record->category;
        }
        $paths = self::paths(array_values($categoryids));
        // The marks come from all the courses of the category, not only from this page.
        $marks = ($categoryid > 0 && $records) ? self::duplicate_marks($categoryid) : [];
        $nomark = ['dup' => '', 'dupreasons' => [], 'dupbase' => ''];
        $courses = [];
        foreach ($records as $record) {
            $courses[] = [
                'id' => (int) $record->id,
                'category' => (int) $record->category,
                'fullname' => self::format($record->fullname),
                'shortname' => self::format($record->shortname),
                'sortorder' => (int) $record->sortorder,
                'visible' => (bool) $record->visible,
                'path' => $paths[(int) $record->category] ?? '',
            ] + ($marks[(int) $record->id] ?? $nomark);
        }
        return ['courses' => $courses, 'total' => (int) $total];
    }

    /**
     * Marks of the repeated courses of one category, with the rules of «Quitar repetidos».
     *
     * All the courses of the category are compared (duplicates::groups() and candidates()), and the activities of the
     * courses in a group are counted with one grouped query (dedupeplan::activities()), so that the base is the course
     * that «Quitar repetidos» would keep: [visible, activities, timemodified, id]. Nothing is marked in a «Repetidos»
     * folder or under one.
     *
     * @param int $categoryid
     * @return array Map course id => ['dup' => 'base'|'repeated'|'possible', 'dupreasons' => string[] (rules of
     *      duplicates::RULES, or one of POSSIBLE_REASONS for 'possible'), 'dupbase' => string (formatted name of the
     *      course that stays, '' for the base; for a possible repeat, the course it may repeat)]. Courses in no group
     *      are missing.
     */
    public static function duplicate_marks(int $categoryid): array {
        global $DB;
        if (self::in_folder($categoryid)) {
            return [];
        }
        $records = $DB->get_records_sql(
            'SELECT id, fullname, shortname, timemodified, visible
               FROM {course}
              WHERE category = :category AND id <> :site
           ORDER BY sortorder, id',
            ['category' => $categoryid, 'site' => SITEID]
        );
        $courses = [];
        foreach ($records as $record) {
            $courses[] = [
                'id' => (int) $record->id,
                'fullname' => (string) $record->fullname,
                'shortname' => (string) $record->shortname,
                'timemodified' => (int) $record->timemodified,
                'visible' => (int) $record->visible,
            ];
        }
        if (count($courses) < 2) {
            return [];
        }
        $sure = duplicates::groups($courses);
        $possible = duplicates::candidates($courses, $sure);
        if (!$sure && !$possible) {
            return [];
        }
        // The groups do not depend on the activities, only which course stays: they are counted for the members only.
        $members = [];
        foreach (array_merge($sure, $possible) as $group) {
            foreach ($group['ids'] as $id) {
                $members[] = $id;
            }
        }
        $activities = dedupeplan::activities($members);
        foreach ($courses as &$course) {
            $course['activities'] = $activities[$course['id']] ?? 0;
        }
        unset($course);
        $sure = duplicates::groups($courses);
        $possible = duplicates::candidates($courses, $sure);

        $names = [];
        $name = static function (int $id) use (&$names, $records): string {
            if (!isset($names[$id])) {
                $names[$id] = self::format((string) $records[$id]->fullname);
            }
            return $names[$id];
        };
        $marks = [];
        foreach ($sure as $group) {
            $all = [];
            foreach ($group['courses'] as $member) {
                $all += array_fill_keys($member['reasons'], true);
            }
            // The base shows every reason of its group, in the order of the rules.
            $basereasons = array_values(array_filter(duplicates::RULES, static function (string $rule) use ($all): bool {
                return isset($all[$rule]);
            }));
            foreach ($group['courses'] as $member) {
                $marks[$member['id']] = [
                    'dup' => $member['keep'] ? 'base' : 'repeated',
                    'dupreasons' => $member['keep'] ? $basereasons : $member['reasons'],
                    'dupbase' => $member['keep'] ? '' : $name($group['keep']),
                ];
            }
        }
        foreach ($possible as $group) {
            foreach ($group['courses'] as $member) {
                // The course it may repeat: the one that would stay or, for that one, the first other course.
                $other = $group['keep'];
                if ($member['id'] === $other) {
                    foreach ($group['courses'] as $candidate) {
                        if ($candidate['id'] !== $other) {
                            $other = $candidate['id'];
                            break;
                        }
                    }
                }
                $marks[$member['id']] = [
                    'dup' => 'possible',
                    'dupreasons' => [$group['reason']],
                    'dupbase' => $name($other),
                ];
            }
        }
        return $marks;
    }

    /**
     * Whether a category is a «Repetidos» folder or lies under one.
     *
     * @param int $categoryid
     * @return bool
     */
    private static function in_folder(int $categoryid): bool {
        global $DB;
        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === false) {
            return false;
        }
        $ids = array_values(array_filter(array_map('intval', explode('/', (string) $path))));
        if (!$ids) {
            return false;
        }
        foreach ($DB->get_records_list('course_categories', 'id', $ids, '', 'id, name') as $record) {
            if (self::is_folder((string) $record->name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which of some short names are used by a course outside a set, with one query per 500 names.
     *
     * The database compares the names, as update_course() does: with a collation that ignores case or accents
     * (MariaDB), «ABC» holds «abc» too. Holders are matched to the names in memory; when a holder matches none of
     * them exactly (only through the collation), the names of its chunk not matched yet are asked one by one, so
     * the answer is always the one of the database.
     *
     * @param string[] $shortnames
     * @param int[] $excludeids Courses that do not count (the batch itself).
     * @return bool[] Map short name => true for the names taken; the others are missing.
     */
    public static function taken_shortnames(array $shortnames, array $excludeids): array {
        global $DB;
        $shortnames = array_values(array_unique(array_map('strval', $shortnames)));
        $excludeids = array_values(array_unique(array_map('intval', $excludeids))) ?: [0];
        $taken = [];
        foreach (array_chunk($shortnames, 500) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'short');
            [$notin, $notparams] = $DB->get_in_or_equal($excludeids, SQL_PARAMS_NAMED, 'batch', false);
            $holders = $DB->get_records_select('course', "shortname $in AND id $notin", $params + $notparams, '', 'id, shortname');
            $wanted = array_fill_keys($chunk, true);
            $unmatched = false;
            foreach ($holders as $holder) {
                if (isset($wanted[(string) $holder->shortname])) {
                    $taken[(string) $holder->shortname] = true;
                } else {
                    $unmatched = true;
                }
            }
            if (!$unmatched) {
                continue;
            }
            foreach ($chunk as $shortname) {
                if (isset($taken[$shortname])) {
                    continue;
                }
                $notparams['shortname'] = $shortname;
                if ($DB->record_exists_select('course', "shortname = :shortname AND id $notin", $notparams)) {
                    $taken[$shortname] = true;
                }
            }
        }
        return $taken;
    }

    /**
     * Ids of the child categories of a parent in their current order.
     *
     * @param int $parentid
     * @return int[]
     */
    public static function sibling_categories(int $parentid): array {
        global $DB;
        $records = $DB->get_records('course_categories', ['parent' => $parentid], 'sortorder ASC, id ASC', 'id');
        return array_map('intval', array_keys($records));
    }

    /**
     * Ids of the courses of a category in their current order.
     *
     * @param int $categoryid
     * @return int[]
     */
    public static function category_courses(int $categoryid): array {
        global $DB;
        $records = $DB->get_records_sql(
            'SELECT id
               FROM {course}
              WHERE category = :category AND id <> :site
           ORDER BY sortorder, id',
            ['category' => $categoryid, 'site' => SITEID]
        );
        return array_map('intval', array_keys($records));
    }
}
