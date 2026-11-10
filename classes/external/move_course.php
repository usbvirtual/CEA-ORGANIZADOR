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
 * Web service: move a course.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use tool_ceaorganizador\local\guard;
use tool_ceaorganizador\local\journal;
use tool_ceaorganizador\local\mover;

/**
 * Moves a course into a category, right after another course, and records it for the undo.
 */
class move_course extends external_api {
    /**
     * Parameters: the course, its category and the course it goes after.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Course id'),
            'categoryid' => new external_value(PARAM_INT, 'Destination category id'),
            'afterid' => new external_value(PARAM_INT, 'Course it goes after, 0 for first'),
        ]);
    }

    /**
     * Moves the course.
     *
     * @param int $id
     * @param int $categoryid
     * @param int $afterid
     * @return array ['logid' => int, 'from' => ['category', 'afterid'], 'to' => ['category', 'afterid']]
     */
    public static function execute(int $id, int $categoryid, int $afterid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'categoryid' => $categoryid,
            'afterid' => $afterid,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        if ($params['id'] === (int) SITEID) {
            throw new \invalid_parameter_exception('The site course cannot be moved');
        }

        $lock = guard::lock();
        try {
            // Read under the lock: the current category is the source to check.
            $oldcategory = (int) $DB->get_field('course', 'category', ['id' => $params['id']], MUST_EXIST);
            $DB->get_record('course_categories', ['id' => $params['categoryid']], 'id', MUST_EXIST);
            mover::check_course($params['id'], 'moodle/course:changecategory');
            mover::check_category($oldcategory);
            mover::check_category($params['categoryid']);
            $result = journal::move_course($params['id'], $params['categoryid'], $params['afterid']);
        } finally {
            $lock->release();
        }
        return [
            'logid' => (int) $result['logid'],
            'from' => [
                'category' => (int) $result['from']['category'],
                'afterid' => (int) $result['from']['afterid'],
            ],
            'to' => [
                'category' => (int) $result['to']['category'],
                'afterid' => (int) $result['to']['afterid'],
            ],
        ];
    }

    /**
     * Returns: the journal id and the position before and after.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo'),
            'from' => self::position('Position before'),
            'to' => self::position('Position after'),
        ]);
    }

    /**
     * A position: category and previous course.
     *
     * @param string $desc
     * @return external_single_structure
     */
    private static function position(string $desc): external_single_structure {
        $keys = [
            'category' => new external_value(PARAM_INT, 'Category id'),
            'afterid' => new external_value(PARAM_INT, 'Previous course, 0 for first'),
        ];
        return new external_single_structure($keys, $desc);
    }
}
