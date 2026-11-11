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
 * Web service: delete empty categories.
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
use tool_ceaorganizador\local\guard;

/**
 * Deletes the empty categories of a batch under the write lock, in one transaction with one journal entry.
 *
 * moodle/category:manage on each category and on its parent is checked by the runner: a category without it
 * is skipped, not deleted.
 */
class delete_apply extends external_api {
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
     * Deletes the empty categories.
     *
     * @param int[] $ids
     * @return array ['status' => string, 'logid' => int, 'deleted' => int, 'skipped' => array[]]
     */
    public static function execute(array $ids): array {
        $params = self::validate_parameters(self::execute_parameters(), ['ids' => $ids]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $lock = guard::lock();
        try {
            return deleterunner::run($params['ids']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns: the status, the journal id, how many were deleted and the ones skipped with their reason.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok'),
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo; 0 when nothing was deleted'),
            'deleted' => new external_value(PARAM_INT, 'Categories deleted'),
            'skipped' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'name' => new external_value(PARAM_RAW, 'Raw name; empty when it no longer exists'),
                'reason' => new external_value(PARAM_ALPHA, 'hascourses, haschildren, hascontent, nopermission or notfound'),
            ]), 'Categories kept'),
        ]);
    }
}
