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
 * Web service: preview of merging seed categories into one.
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
use tool_ceaorganizador\local\mergeplan;

/**
 * Builds the list of courses to move, with their destinations and repeated courses, and its signature. It writes nothing.
 */
class merge_preview extends external_api {
    /**
     * Parameters shared with merge_apply: the target and the sources.
     *
     * @return array Map name => parameter definition.
     */
    public static function plan_parameters(): array {
        return [
            'targetid' => new external_value(PARAM_INT, 'Category that receives the courses'),
            'sourceids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Source category id'),
                'Categories whose courses are moved'
            ),
        ];
    }

    /**
     * Parameters: see plan_parameters().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::plan_parameters());
    }

    /**
     * Previews a merge.
     *
     * @param int $targetid
     * @param int[] $sourceids
     * @return array ['rows' => array[], 'emptied' => array[], 'folder' => array, 'hash' => string]
     */
    public static function execute(int $targetid, array $sourceids): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'targetid' => $targetid,
            'sourceids' => $sourceids,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $plan = mergeplan::build($params['targetid'], $params['sourceids']);
        $rows = [];
        foreach ($plan['rows'] as $row) {
            $rows[] = [
                'courseid' => $row['courseid'],
                'fullname' => $row['fullname'],
                'from' => $row['from'],
                'to' => $row['to'],
                'newcategories' => $row['newcategories'],
                'duplicate' => $row['duplicate'],
                'action' => $row['action'],
                'group' => $row['group'],
                'existing' => $row['existing'],
                'winner' => $row['winner'],
                'visible' => $row['visible'],
                'reasons' => $row['reasons'],
            ];
        }
        return ['rows' => $rows, 'emptied' => $plan['emptied'], 'folder' => $plan['folder'], 'hash' => $plan['hash']];
    }

    /**
     * Returns: the rows, the categories that would be left empty and the signature of the preview.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'fullname' => new external_value(PARAM_TEXT, 'Course full name, formatted'),
                'from' => new external_value(PARAM_TEXT, 'Current path, formatted'),
                'to' => new external_value(PARAM_TEXT, 'Destination path, formatted'),
                'newcategories' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Path of a category to create, formatted'),
                    'Categories the course needs and that do not exist yet'
                ),
                'duplicate' => new external_value(PARAM_BOOL, 'The course is in a group of repeated courses'),
                'action' => new external_value(PARAM_ALPHA, 'Default action: move, repeated or skip'),
                'group' => new external_value(PARAM_RAW, 'Key of its group of repeated courses, or empty'),
                'existing' => new external_value(PARAM_BOOL, 'The course is already in the target'),
                'winner' => new external_value(PARAM_BOOL, 'Stays in the destination by default: visible, then most recent'),
                'visible' => new external_value(PARAM_BOOL, 'The course is visible'),
                'reasons' => new external_multiple_structure(
                    new external_value(PARAM_ALPHA, 'name, key or shortname'),
                    'Rules that matched'
                ),
            ])),
            'emptied' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'path' => new external_value(PARAM_TEXT, 'Path, formatted'),
            ]), 'Source categories left empty with the default actions'),
            'folder' => new external_single_structure([
                'visible' => new external_value(PARAM_BOOL, 'The folder exists and is visible: moved courses stay visible'),
                'id' => new external_value(PARAM_INT, 'Folder of repeated courses under the target; 0 when it will be created'),
                'path' => new external_value(PARAM_TEXT, 'Path of the folder, formatted'),
            ]),
            'hash' => new external_value(PARAM_ALPHANUM, 'Signature of the preview, to pass to merge_apply'),
        ]);
    }
}
