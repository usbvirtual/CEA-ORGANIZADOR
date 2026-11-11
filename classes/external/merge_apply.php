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
 * Web service: apply a merge of seed categories.
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
use tool_ceaorganizador\local\mergerunner;

/**
 * Moves the courses of a preview that is still current. The lock and the native permissions live in mergerunner.
 */
class merge_apply extends external_api {
    /**
     * Parameters: the same as merge_preview, plus the action of each row and the hash.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(merge_preview::plan_parameters() + [
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'action' => new external_value(PARAM_ALPHA, 'move or skip'),
                ]),
                'Action of each course, as the user left it'
            ),
            'hash' => new external_value(PARAM_ALPHANUM, 'Hash returned by merge_preview'),
        ]);
    }

    /**
     * Merges the categories.
     *
     * @param int $targetid
     * @param int[] $sourceids
     * @param array $rows
     * @param string $hash
     * @return array ['status' => string, 'logid' => int, 'moved' => int, 'skipped' => int, 'emptied' => array[]]
     */
    public static function execute(int $targetid, array $sourceids, array $rows, string $hash): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'targetid' => $targetid,
            'sourceids' => $sourceids,
            'rows' => $rows,
            'hash' => $hash,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        return mergerunner::run($params['targetid'], $params['sourceids'], $params['rows'], $params['hash']);
    }

    /**
     * Returns: the status, the journal id, the counts and the categories left empty.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, or stale when the preview is no longer current'),
            'logid' => new external_value(PARAM_INT, 'Journal id; 0 when nothing was recorded'),
            'moved' => new external_value(PARAM_INT, 'Courses moved'),
            'skipped' => new external_value(PARAM_INT, 'Courses left where they were'),
            'emptied' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'path' => new external_value(PARAM_TEXT, 'Path, formatted'),
            ]), 'Source categories that are empty now, deepest first'),
        ]);
    }
}
