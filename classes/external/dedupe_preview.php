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
 * Web service: preview of «Quitar repetidos».
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
use tool_ceaorganizador\local\dedupeplan;

/**
 * Lists the groups of repeated courses of some categories and their subcategories, the possible repeated courses
 * that need the user's confirmation, and its signature. It writes nothing.
 */
class dedupe_preview extends external_api {
    /**
     * Parameters shared with dedupe_apply: the chosen categories.
     *
     * @return array Map name => parameter definition.
     */
    public static function plan_parameters(): array {
        return [
            'categoryids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Category analysed, with all its subcategories'),
                'Chosen categories; one inside another chosen one is skipped'
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
     * Previews it.
     *
     * @param int[] $categoryids
     * @return array ['roots' => int[], 'groups' => array[], 'possible' => array[], 'folders' => array[], 'hash' => string]
     */
    public static function execute(array $categoryids): array {
        $params = self::validate_parameters(self::execute_parameters(), ['categoryids' => $categoryids]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        return dedupeplan::build($params['categoryids']);
    }

    /**
     * Structure of a group, sure or possible.
     *
     * @param bool $possible Whether it carries the reason of a possible group.
     * @return external_single_structure
     */
    private static function group_structure(bool $possible): external_single_structure {
        $fields = [
            'rootid' => new external_value(PARAM_INT, 'Chosen category the group belongs to'),
            'categoryid' => new external_value(PARAM_INT, 'Category of the group'),
            'path' => new external_value(PARAM_TEXT, 'Path of that category, formatted'),
            'key' => new external_value(PARAM_RAW, 'Stable key of the group: its sorted course ids joined by -'),
            'courses' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'fullname' => new external_value(PARAM_RAW, 'Full name, raw'),
                'shortname' => new external_value(PARAM_RAW, 'Short name, raw'),
                'timemodified' => new external_value(PARAM_INT, 'Last modification'),
                'visible' => new external_value(PARAM_BOOL, 'The course is visible'),
                'activities' => new external_value(PARAM_INT, 'Activities of the course'),
                'reasons' => new external_multiple_structure(
                    new external_value(PARAM_ALPHA, 'Rule or reason that matched'),
                    'Rules that matched'
                ),
                'keep' => new external_value(PARAM_BOOL, 'Stays by default: visible, then most activities, then most recent'),
            ])),
        ];
        if ($possible) {
            $fields['reason'] = new external_value(PARAM_ALPHA, 'group, shortname, extra or similar');
        }
        return new external_single_structure($fields);
    }

    /**
     * Returns: the groups, the possible groups, the folders that receive the repeated courses and the signature.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'roots' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Chosen category'),
                'Chosen categories analysed, without the ones inside another'
            ),
            'groups' => new external_multiple_structure(self::group_structure(false), 'Sure groups'),
            'possible' => new external_multiple_structure(self::group_structure(true), 'Groups that need confirmation'),
            'folders' => new external_multiple_structure(new external_single_structure([
                'rootid' => new external_value(PARAM_INT, 'Chosen category the folder hangs from'),
                'id' => new external_value(PARAM_INT, 'Folder id; 0 when it will be created'),
                'visible' => new external_value(PARAM_BOOL, 'The folder exists and is visible: moved courses stay visible'),
                'path' => new external_value(PARAM_TEXT, 'Path of the folder, formatted'),
            ])),
            'hash' => new external_value(PARAM_ALPHANUM, 'Signature of the preview, to pass to dedupe_apply'),
        ]);
    }
}
