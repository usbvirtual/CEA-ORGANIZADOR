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
 * Web service: the category tree.
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
use tool_ceaorganizador\local\tree;

/**
 * All the categories, with their sort recommendation and the count for the summary.
 */
class get_tree extends external_api {
    /**
     * Parameters: none.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Lists the categories in tree order.
     *
     * @return array ['categories' => array, 'rootneedsort' => bool, 'needsortcount' => int]
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $categories = tree::categories();
        $rootneedsort = tree::root_needsort();
        $count = count(array_filter(array_column($categories, 'needsort')));
        return [
            'categories' => $categories,
            'rootneedsort' => $rootneedsort,
            'needsortcount' => $count + ($rootneedsort ? 1 : 0),
        ];
    }

    /**
     * Returns: the categories, names and paths already formatted (HTML-safe).
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'categories' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'parent' => new external_value(PARAM_INT, 'Parent id, 0 for top level'),
                'name' => new external_value(PARAM_RAW, 'Formatted name'),
                'path' => new external_value(PARAM_RAW, 'Formatted path'),
                'depth' => new external_value(PARAM_INT, 'Depth, 1 for top level'),
                'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                'visible' => new external_value(PARAM_BOOL, 'Visible'),
                'coursecount' => new external_value(PARAM_INT, 'Number of courses'),
                'needsort' => new external_value(PARAM_BOOL, 'Its subcategories or courses differ from the proposed order'),
            ])),
            'rootneedsort' => new external_value(PARAM_BOOL, 'The top-level categories differ from the proposed order'),
            'needsortcount' => new external_value(PARAM_INT, 'Categories with needsort, plus 1 when rootneedsort'),
        ]);
    }
}
