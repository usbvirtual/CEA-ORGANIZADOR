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
 * Web service: one page of courses.
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
 * Courses of a category, or of the whole site, with an optional search, 100 per page.
 */
class get_courses extends external_api {
    /** @var int Courses per page. */
    public const PERPAGE = 100;

    /**
     * Parameters: category, search text and page.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category id, 0 for the whole site'),
            'search' => new external_value(PARAM_TEXT, 'Text in the full or short name', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Zero-based page', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Lists one page of courses.
     *
     * @param int $categoryid
     * @param string $search
     * @param int $page
     * @return array ['courses' => array, 'total' => int, 'perpage' => int]
     */
    public static function execute(int $categoryid, string $search = '', int $page = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'search' => $search,
            'page' => $page,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $result = tree::courses(
            max(0, $params['categoryid']),
            $params['search'],
            max(0, $params['page']),
            self::PERPAGE
        );
        return $result + ['perpage' => self::PERPAGE];
    }

    /**
     * Returns: the courses, names and paths already formatted (HTML-safe).
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'category' => new external_value(PARAM_INT, 'Category id'),
                'fullname' => new external_value(PARAM_RAW, 'Formatted full name'),
                'shortname' => new external_value(PARAM_RAW, 'Formatted short name'),
                'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                'visible' => new external_value(PARAM_BOOL, 'Visible'),
                'path' => new external_value(PARAM_RAW, 'Formatted path of its category'),
            ])),
            'total' => new external_value(PARAM_INT, 'Number of matching courses'),
            'perpage' => new external_value(PARAM_INT, 'Courses per page'),
        ]);
    }
}
