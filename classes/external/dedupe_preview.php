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
 * Lists the groups of repeated courses of a category and its subcategories, with its signature. It writes nothing.
 */
class dedupe_preview extends external_api {
    /**
     * Parameters: the category analysed.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category analysed, with all its subcategories'),
        ]);
    }

    /**
     * Previews it.
     *
     * @param int $categoryid
     * @return array ['groups' => array[], 'folder' => array, 'hash' => string]
     */
    public static function execute(int $categoryid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['categoryid' => $categoryid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        return dedupeplan::build($params['categoryid']);
    }

    /**
     * Returns: the groups, the folder that receives the repeated courses and the signature of the preview.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'groups' => new external_multiple_structure(new external_single_structure([
                'categoryid' => new external_value(PARAM_INT, 'Category of the group'),
                'path' => new external_value(PARAM_TEXT, 'Path of that category, formatted'),
                'key' => new external_value(PARAM_RAW, 'Stable key of the group: its sorted course ids joined by -'),
                'courses' => new external_multiple_structure(new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'fullname' => new external_value(PARAM_RAW, 'Full name, raw'),
                    'shortname' => new external_value(PARAM_RAW, 'Short name, raw'),
                    'timemodified' => new external_value(PARAM_INT, 'Last modification'),
                    'visible' => new external_value(PARAM_BOOL, 'The course is visible'),
                    'reasons' => new external_multiple_structure(
                        new external_value(PARAM_ALPHA, 'name, key or shortname'),
                        'Rules that matched'
                    ),
                    'keep' => new external_value(PARAM_BOOL, 'Stays by default: visible first, then the most recently modified'),
                ])),
            ])),
            'folder' => new external_single_structure([
                'visible' => new external_value(PARAM_BOOL, 'The folder exists and is visible: moved courses stay visible'),
                'id' => new external_value(PARAM_INT, 'Folder id; 0 when it will be created'),
                'path' => new external_value(PARAM_TEXT, 'Path of the folder, formatted'),
            ]),
            'hash' => new external_value(PARAM_ALPHANUM, 'Signature of the preview, to pass to dedupe_apply'),
        ]);
    }
}
