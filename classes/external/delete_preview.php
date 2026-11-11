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
 * Web service: preview of deleting categories.
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
use tool_ceaorganizador\local\deleterunner;

/**
 * Says which categories of a batch would be deleted and which skipped, and why. It writes nothing.
 */
class delete_preview extends external_api {
    /**
     * Parameters: the categories.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ids' => new external_multiple_structure(new external_value(PARAM_INT, 'Category id')),
        ]);
    }

    /**
     * Previews the deletion with the same rules as delete_apply.
     *
     * @param int[] $ids
     * @return array ['delete' => array[], 'skipped' => array[]]
     */
    public static function execute(array $ids): array {
        $params = self::validate_parameters(self::execute_parameters(), ['ids' => $ids]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        return deleterunner::plan($params['ids']);
    }

    /**
     * Returns: the categories to delete, deepest first, and the ones skipped with their reason.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'delete' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'path' => new external_value(PARAM_TEXT, 'Path, formatted'),
            ]), 'Empty categories that would be deleted, deepest first'),
            'skipped' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'name' => new external_value(PARAM_RAW, 'Raw name; empty when it no longer exists'),
                'path' => new external_value(PARAM_TEXT, 'Path, formatted; empty when it no longer exists'),
                'reason' => new external_value(PARAM_ALPHA, 'hascourses, haschildren, hascontent, nopermission or notfound'),
            ]), 'Categories that would be kept'),
        ]);
    }
}
