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
 * Reads the category tree, with paths and sort recommendations, and the courses. It writes nothing.
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
     * @return array[] Lists of ['id' => int, 'name' => string (formatted)] by category id, for the sorter.
     */
    private static function load_courses_by_category(): array {
        global $DB;
        $records = $DB->get_records_sql(
            'SELECT id, category, fullname
               FROM {course}
              WHERE id <> :site
           ORDER BY category, sortorder, id',
            ['site' => SITEID]
        );
        $bycategory = [];
        foreach ($records as $record) {
            $bycategory[(int) $record->category][] = [
                'id' => (int) $record->id,
                'name' => self::format($record->fullname),
            ];
        }
        return $bycategory;
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
     * @return array[] Rows with id, parent, name, path, depth, sortorder, visible, coursecount and needsort.
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
        $bycourses = self::load_courses_by_category();
        $patterns = sorter::patterns();
        $rows = [];
        self::walk(0, '', $byparent, $bycourses, $patterns, $rows);
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
     * @param array $patterns
     * @param array $rows Output rows.
     */
    private static function walk(
        int $parentid,
        string $parentpath,
        array $byparent,
        array $bycourses,
        array $patterns,
        array &$rows
    ): void {
        foreach ($byparent[$parentid] ?? [] as $category) {
            $id = (int) $category->id;
            $name = self::format($category->name);
            $path = $parentpath === '' ? $name : $parentpath . ' / ' . $name;
            $children = $byparent[$id] ?? [];
            $courses = $bycourses[$id] ?? [];
            $currentcourses = array_column($courses, 'id');
            $needsort = self::children_differ($children, false, $patterns)
                || sorter::differs($currentcourses, sorter::propose_courses($courses));
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
            ];
            self::walk($id, $path, $byparent, $bycourses, $patterns, $rows);
        }
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
     * @return array With 'courses' and the 'total' count.
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
            ];
        }
        return ['courses' => $courses, 'total' => (int) $total];
    }

    /**
     * Which of some short names are used by a course outside a set, with one query per 500 names.
     *
     * The database compares the names, as update_course() does: with a collation that ignores case or accents
     * (MariaDB), «ABC» holds «abc» too. Without any holder in a chunk (the usual case) its names are free. With
     * holders, the exact matches are taken, and every other name of the chunk is asked one by one: «abc» may be held
     * through the collation by the holder of «ABC». So the answer is always the one of the database.
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
            if (!$holders) {
                continue;
            }
            $wanted = array_fill_keys($chunk, true);
            foreach ($holders as $holder) {
                if (isset($wanted[(string) $holder->shortname])) {
                    $taken[(string) $holder->shortname] = true;
                }
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
