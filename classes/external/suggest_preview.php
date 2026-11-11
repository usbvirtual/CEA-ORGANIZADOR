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
 * Web service: suggested corrections for category and course names.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use tool_ceaorganizador\local\suggester;

/**
 * Proposes corrected names for the categories (and courses) of a scope; it never writes.
 *
 * The vocabulary comes from every category name and course full name of the site, whatever the
 * scope, so an accent written twice anywhere is learned. Names are raw database values, so the
 * client can send them back to rename_apply as they are; only the group is formatted. The group of
 * a category is the path of its parent (empty at the top level); the one of a course is the path
 * of its category.
 */
class suggest_preview extends external_api {
    /** @var int Maximum number of rows returned. */
    public const MAXROWS = 2000;

    /**
     * Parameters: the category (0 for the whole site) and whether courses are included.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category and its descendants; 0 is the whole site', VALUE_DEFAULT, 0),
            'includecourses' => new external_value(PARAM_BOOL, 'Also suggest course full names', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Builds the suggestions.
     *
     * @param int $categoryid
     * @param bool $includecourses
     * @return array ['rows' => [...], 'total' => int, 'truncated' => bool]
     */
    public static function execute(int $categoryid = 0, bool $includecourses = true): array {
        global $DB;
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'includecourses' => $includecourses,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $categories = $DB->get_records('course_categories', null, 'id ASC', 'id, parent, name');
        $courses = $DB->get_records_select('course', 'id <> :site', ['site' => SITEID], 'id ASC', 'id, category, fullname');

        $names = [];
        foreach ($categories as $category) {
            $names[] = $category->name;
        }
        foreach ($courses as $course) {
            $names[] = $course->fullname;
        }
        $vocabulary = suggester::vocabulary($names);
        $glossary = suggester::parse_glossary((string) get_config('tool_ceaorganizador', 'glossary'));

        $scope = self::scope($categories, (int) $params['categoryid']);
        $paths = [];
        $rows = [];

        // Categories: the siblings of each parent are compared with each other.
        $children = [];
        foreach ($categories as $category) {
            $children[(int) $category->parent][] = $category;
        }
        foreach ($children as $siblings) {
            $inscope = array_filter($siblings, function ($category) use ($scope) {
                return isset($scope[(int) $category->id]);
            });
            if (!$inscope) {
                continue;
            }
            $forms = suggester::sibling_forms(array_map(function ($category) {
                return $category->name;
            }, $siblings), $vocabulary);
            foreach ($inscope as $category) {
                $row = self::row('category', (int) $category->id, $category->name, $vocabulary, $glossary, $forms);
                if ($row) {
                    $row['group'] = (int) $category->parent ? self::path((int) $category->parent, $categories, $paths) : '';
                    $rows[] = $row;
                }
            }
        }

        // Courses: the siblings are the courses of the same category.
        if ($params['includecourses']) {
            $bycategory = [];
            foreach ($courses as $course) {
                $bycategory[(int) $course->category][] = $course;
            }
            foreach ($bycategory as $parentid => $siblings) {
                if (!isset($scope[$parentid])) {
                    continue;
                }
                $forms = suggester::sibling_forms(array_map(function ($course) {
                    return $course->fullname;
                }, $siblings), $vocabulary);
                foreach ($siblings as $course) {
                    $row = self::row('course', (int) $course->id, $course->fullname, $vocabulary, $glossary, $forms);
                    if ($row) {
                        $row['group'] = self::path($parentid, $categories, $paths);
                        $rows[] = $row;
                    }
                }
            }
        }

        usort($rows, function (array $a, array $b): int {
            return [$a['group'], $a['old'], $a['type'], $a['id']] <=> [$b['group'], $b['old'], $b['type'], $b['id']];
        });
        $total = count($rows);
        return [
            'rows' => array_slice($rows, 0, self::MAXROWS),
            'total' => $total,
            'truncated' => $total > self::MAXROWS,
        ];
    }

    /**
     * The suggestion of one name, or null when it does not change.
     *
     * @param string $type
     * @param int $id
     * @param string $name Raw name.
     * @param array $vocabulary
     * @param array $glossary
     * @param array $forms Result of sibling_forms() for the siblings of this item.
     * @return array|null
     */
    private static function row(string $type, int $id, string $name, array $vocabulary, array $glossary, array $forms): ?array {
        $result = suggester::suggest($name, $vocabulary, $glossary, $forms[$name] ?? []);
        if ($result['new'] === $name) {
            return null;
        }
        return [
            'type' => $type,
            'id' => $id,
            'old' => $name,
            'new' => $result['new'],
            'reasons' => array_values($result['reasons']),
        ];
    }

    /**
     * Ids of the category and of all its descendants; every category when the id is 0.
     *
     * @param \stdClass[] $categories Records by id.
     * @param int $categoryid
     * @return array Ids as keys.
     */
    private static function scope(array $categories, int $categoryid): array {
        if ($categoryid === 0) {
            return array_fill_keys(array_keys($categories), true);
        }
        if (!isset($categories[$categoryid])) {
            throw new \invalid_parameter_exception('Unknown category: ' . $categoryid);
        }
        $children = [];
        foreach ($categories as $category) {
            $children[(int) $category->parent][] = (int) $category->id;
        }
        $scope = [];
        $queue = [$categoryid];
        while ($queue) {
            $id = array_shift($queue);
            if (isset($scope[$id])) {
                continue;
            }
            $scope[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }
        return $scope;
    }

    /**
     * Formatted path of a category, memoised.
     *
     * @param int $id
     * @param \stdClass[] $categories Records by id.
     * @param string[] $paths Memo, filled in place.
     * @return string
     */
    private static function path(int $id, array $categories, array &$paths): string {
        if (isset($paths[$id])) {
            return $paths[$id];
        }
        $chain = [];
        $current = $id;
        while ($current > 0 && isset($categories[$current]) && !isset($paths[$current]) && !in_array($current, $chain, true)) {
            $chain[] = $current;
            $current = (int) $categories[$current]->parent;
        }
        $prefix = $paths[$current] ?? '';
        foreach (array_reverse($chain) as $categoryid) {
            $name = format_string($categories[$categoryid]->name, true, ['context' => \context_system::instance()]);
            $prefix = $prefix === '' ? $name : $prefix . ' / ' . $name;
            $paths[$categoryid] = $prefix;
        }
        return $paths[$id] ?? '';
    }

    /**
     * Returns: the rows, how many there were before the cap and whether it cut them.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'category or course'),
                'id' => new external_value(PARAM_INT, 'Item id'),
                'old' => new external_value(PARAM_RAW, 'Current raw name; send it back to rename_apply as is'),
                'new' => new external_value(PARAM_RAW, 'Suggested raw name'),
                'reasons' => new external_multiple_structure(new external_value(PARAM_ALPHA, 'Reason code')),
                'group' => new external_value(PARAM_RAW, 'Formatted path of the category that holds the item'),
            ])),
            'total' => new external_value(PARAM_INT, 'Rows before the cap'),
            'truncated' => new external_value(PARAM_BOOL, 'True when the cap cut the list'),
        ]);
    }
}
