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
 * Sort plan: what an automatic sort would change, and its signature.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Builds the read-only plan shared by the sort preview and the sort apply.
 */
class sortplan {
    /** @var string[] Accepted scopes. */
    public const SCOPES = ['categories', 'courses', 'both'];

    /**
     * Formats a name for display and for the sorter, as the tree does.
     *
     * @param string $name
     * @return string
     */
    private static function format(string $name): string {
        return format_string($name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Builds the plan of a category, or of the top level when the id is 0.
     *
     * @param int $categoryid 0 for the top level.
     * @param string $scope categories, courses or both.
     * @param bool $recursive Also every category below.
     * @return array ['changes' => [['kind', 'parentid', 'parentpath', 'before' => [['id', 'name']], 'after']], 'hash']
     */
    public static function build(int $categoryid, string $scope, bool $recursive): array {
        return self::build_many([$categoryid], $scope, $recursive);
    }

    /**
     * Builds the plan of several categories at once.
     *
     * The 'categories' kind orders the children of a parent (the top level is a
     * parent with id 0); the 'courses' kind orders the courses of a category.
     * Only the lists whose proposed order differs are included. Repeated ids are
     * dropped and, when recursive, so is every id below another selected one; the
     * plans are joined without repeating a (kind, parent) pair. The hash covers
     * ids only, so a change of formatting cannot make a preview look stale.
     *
     * @param int[] $categoryids Categories to sort; 0 (the top level) is accepted only alone.
     * @param string $scope categories, courses or both.
     * @param bool $recursive Also every category below.
     * @return array ['changes' => [['kind', 'parentid', 'parentpath', 'before' => [['id', 'name']], 'after']], 'hash']
     */
    public static function build_many(array $categoryids, string $scope, bool $recursive): array {
        global $DB;
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \coding_exception('Unknown sort scope: ' . $scope);
        }
        $roots = array_values(array_unique(array_map('intval', $categoryids)));
        if (!$roots) {
            throw new \coding_exception('No category to sort');
        }
        if (in_array(0, $roots, true) && count($roots) > 1) {
            throw new \coding_exception('The top level can only be sorted alone');
        }
        foreach ($roots as $id) {
            if ($id !== 0) {
                $DB->get_record('course_categories', ['id' => $id], 'id', MUST_EXIST);
            }
        }
        $withcategories = $scope !== 'courses';
        $withcourses = $scope !== 'categories';

        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name');
        $byparent = [];
        $names = [];
        $parents = [];
        foreach ($records as $record) {
            $byparent[(int) $record->parent][] = (int) $record->id;
            $names[(int) $record->id] = self::format($record->name);
            $parents[(int) $record->id] = (int) $record->parent;
        }

        if ($recursive && count($roots) > 1) {
            $roots = self::without_descendants($roots, $parents);
        }

        $courses = $withcourses ? self::courses($roots, $recursive) : [];
        $patterns = sorter::patterns();
        $changes = [];
        $seen = [];
        $pending = $roots;
        while ($pending) {
            $id = array_shift($pending);
            $children = $byparent[$id] ?? [];
            $path = self::path($id, $parents, $names);
            if ($withcategories && $children && !isset($seen['categories:' . $id])) {
                $seen['categories:' . $id] = true;
                $items = [];
                foreach ($children as $child) {
                    $items[] = ['id' => $child, 'name' => $names[$child]];
                }
                $after = sorter::propose($items, $id === 0, $patterns);
                if (sorter::differs($children, $after)) {
                    $changes[] = self::change('categories', $id, $path, $items, $after);
                }
            }
            if ($withcourses && $id !== 0 && !empty($courses[$id]) && !isset($seen['courses:' . $id])) {
                $seen['courses:' . $id] = true;
                $after = sorter::propose_courses($courses[$id]);
                if (sorter::differs(array_column($courses[$id], 'id'), $after)) {
                    $changes[] = self::change('courses', $id, $path, $courses[$id], $after);
                }
            }
            if ($recursive) {
                // Depth first, in the current order of the children.
                $pending = array_merge($children, $pending);
            }
        }
        return ['changes' => $changes, 'hash' => self::hash($changes)];
    }

    /**
     * Drops every id that is below another one of the list.
     *
     * @param int[] $ids Distinct ids.
     * @param int[] $parents Parent of each category id.
     * @return int[]
     */
    private static function without_descendants(array $ids, array $parents): array {
        $selected = array_flip($ids);
        return array_values(array_filter($ids, function (int $id) use ($selected, $parents): bool {
            $current = $parents[$id] ?? 0;
            // The depth guard protects against a broken parent chain.
            for ($depth = 0; $current !== 0 && $depth < 100; $depth++) {
                if (isset($selected[$current])) {
                    return false;
                }
                $current = $parents[$current] ?? 0;
            }
            return true;
        }));
    }

    /**
     * Signature of a list of changes, over ids only.
     *
     * The changes are taken in a fixed order (kind, then parent), so the signature
     * does not depend on the order of the selection.
     *
     * @param array $changes As in build().
     * @return string
     */
    public static function hash(array $changes): string {
        usort($changes, fn(array $x, array $y): int => [$x['kind'], (int) $x['parentid']] <=> [$y['kind'], (int) $y['parentid']]);
        $ids = [];
        foreach ($changes as $change) {
            $ids[] = [
                $change['kind'],
                (int) $change['parentid'],
                array_map('intval', array_column($change['before'], 'id')),
                array_map('intval', array_column($change['after'], 'id')),
            ];
        }
        return sha1(json_encode($ids));
    }

    /**
     * One change of the plan.
     *
     * @param string $kind categories or courses.
     * @param int $parentid
     * @param string $path Formatted path of the parent.
     * @param array $items Current items, ['id', 'name'] in their current order.
     * @param int[] $afterids Proposed order.
     * @return array
     */
    private static function change(string $kind, int $parentid, string $path, array $items, array $afterids): array {
        $byid = [];
        foreach ($items as $item) {
            $byid[(int) $item['id']] = ['id' => (int) $item['id'], 'name' => $item['name']];
        }
        $after = [];
        foreach ($afterids as $id) {
            $after[] = $byid[(int) $id];
        }
        return [
            'kind' => $kind,
            'parentid' => $parentid,
            'parentpath' => $path,
            'before' => array_values($byid),
            'after' => $after,
        ];
    }

    /**
     * Formatted path of a category, or the name of the top level for 0.
     *
     * @param int $id
     * @param int[] $parents Parent of each category id.
     * @param string[] $names Formatted name of each category id.
     * @return string
     */
    private static function path(int $id, array $parents, array $names): string {
        if ($id === 0) {
            return get_string('top');
        }
        $parts = [];
        // The depth guard protects against a broken parent chain.
        for ($depth = 0; $id !== 0 && isset($names[$id]) && $depth < 100; $depth++) {
            array_unshift($parts, $names[$id]);
            $id = $parents[$id] ?? 0;
        }
        return implode(' / ', $parts);
    }

    /**
     * Courses grouped by category, in their current order, with formatted names.
     *
     * @param int[] $categoryids Selected categories; 0 for the whole site.
     * @param bool $recursive False loads only the courses of the categories themselves.
     * @return array[] Lists of ['id' => int, 'name' => string] by category id.
     */
    private static function courses(array $categoryids, bool $recursive): array {
        global $DB;
        $where = 'id <> :site';
        $params = ['site' => SITEID];
        if (!$recursive) {
            [$insql, $inparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
            $where .= ' AND category ' . $insql;
            $params += $inparams;
        }
        $records = $DB->get_records_select('course', $where, $params, 'category, sortorder, id', 'id, category, fullname');
        $bycategory = [];
        foreach ($records as $record) {
            $bycategory[(int) $record->category][] = [
                'id' => (int) $record->id,
                'name' => self::format($record->fullname),
            ];
        }
        return $bycategory;
    }
}
