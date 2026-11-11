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
 * Web service: create the top-level category that receives the merged seeds.
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
use tool_ceaorganizador\local\sorter;

/**
 * Creates a top-level category, or returns the one that already has that name. It is not journaled: deleting the
 * category from Moodle undoes it.
 */
class create_seedroot extends external_api {
    /**
     * Parameters: the name.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT, 'Name of the category', VALUE_DEFAULT, 'Semillas'),
        ]);
    }

    /**
     * Creates the category under the write lock.
     *
     * @param string $name
     * @return array ['id' => int, 'name' => string, 'created' => bool]
     */
    public static function execute(string $name = 'Semillas'): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['name' => $name]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        require_capability('moodle/category:manage', $context);

        $name = trim($params['name']);
        if (sorter::normalize($name) === '') {
            throw new \invalid_parameter_exception('Empty name');
        }

        $lock = guard::lock();
        try {
            $found = null;
            $records = $DB->get_records('course_categories', ['parent' => 0], 'sortorder ASC, id ASC', 'id, name');
            foreach ($records as $record) {
                if (sorter::normalize($record->name) === sorter::normalize($name)) {
                    $found = $record;
                    break;
                }
            }
            $created = $found === null;
            if ($created) {
                $category = \core_course_category::create(['name' => $name, 'parent' => 0]);
                $found = (object) ['id' => $category->id, 'name' => $category->name];
            }
        } finally {
            $lock->release();
        }
        return [
            'id' => (int) $found->id,
            'name' => format_string($found->name, true, ['context' => $context]),
            'created' => $created,
        ];
    }

    /**
     * Returns: the category and whether it was created.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Category id'),
            'name' => new external_value(PARAM_TEXT, 'Category name, formatted'),
            'created' => new external_value(PARAM_BOOL, 'False when a category with that name already existed'),
        ]);
    }
}
