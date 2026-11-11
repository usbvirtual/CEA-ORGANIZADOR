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
 * Web service: apply «Quitar repetidos».
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
use tool_ceaorganizador\local\deduperunner;
use tool_ceaorganizador\local\partial_exception;

/**
 * Moves the repeated courses of a preview that is still current. The lock and the native permissions live in
 * deduperunner.
 */
class dedupe_apply extends external_api {
    /**
     * Parameters: the category, the groups chosen with the course that stays, and the hash.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category analysed'),
            'groups' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_RAW, 'Key of the group, as dedupe_preview returned it'),
                    'keep' => new external_value(PARAM_INT, 'Course that stays'),
                ]),
                'Groups to clean; a group left out is not touched'
            ),
            'hash' => new external_value(PARAM_ALPHANUM, 'Hash returned by dedupe_preview'),
        ]);
    }

    /**
     * Moves the repeated courses.
     *
     * @param int $categoryid
     * @param array $groups
     * @param string $hash
     * @return array ['status' => string, 'logid' => int, 'moved' => int]
     */
    public static function execute(int $categoryid, array $groups, string $hash): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'groups' => $groups,
            'hash' => $hash,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        try {
            return deduperunner::run($params['categoryid'], $params['groups'], $params['hash']);
        } catch (partial_exception $e) {
            // Half done: what moved is recorded under logid, and the panel offers to undo it.
            return ['status' => 'partial', 'logid' => $e->logid, 'moved' => $e->moved];
        }
    }

    /**
     * Returns: the status, the journal id and the count.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(
                PARAM_ALPHA,
                'ok, stale (the preview changed) or partial (stopped halfway; logid undoes it)'
            ),
            'logid' => new external_value(PARAM_INT, 'Journal id; 0 when nothing was recorded'),
            'moved' => new external_value(PARAM_INT, 'Courses moved to the Repetidos folder'),
        ]);
    }
}
