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
 * Web service: preview of cloning seed courses into an academic period.
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
use tool_ceaorganizador\local\cloneplan;

/**
 * Builds the list of copies, with their destinations and errors, and its signature. It writes nothing.
 */
class clone_preview extends external_api {
    /**
     * Parameters shared with clone_apply: what to clone, where, and the dates.
     *
     * @return array Map name => parameter definition.
     */
    public static function plan_parameters(): array {
        return [
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_ALPHA, 'category or course'),
                    'id' => new external_value(PARAM_INT, 'Category or course id'),
                ]),
                'Seed categories and courses'
            ),
            'periodid' => new external_value(PARAM_INT, 'Category of the period that receives the copies'),
            'periodlabel' => new external_value(PARAM_TEXT, 'Period text, such as 2027-01'),
            'startdate' => new external_value(PARAM_INT, 'Start date of the copies'),
            'enddate' => new external_value(PARAM_INT, 'End date of the copies'),
        ];
    }

    /**
     * Checks the item types, which PARAM_ALPHA would let through as any letters.
     *
     * @param array $items Items as validated.
     * @return array Items as cloneplan::build() expects them.
     * @throws \invalid_parameter_exception On a type that is not category or course.
     */
    public static function validate_items(array $items): array {
        $clean = [];
        foreach ($items as $item) {
            if (!in_array($item['type'], ['category', 'course'], true)) {
                throw new \invalid_parameter_exception('Unknown item type');
            }
            $clean[] = ['type' => $item['type'], 'id' => (int) $item['id']];
        }
        return $clean;
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
     * Previews a cloning batch.
     *
     * @param array $items
     * @param int $periodid
     * @param string $periodlabel
     * @param int $startdate
     * @param int $enddate
     * @return array ['rows' => array[], 'total' => int, 'hash' => string]
     */
    public static function execute(array $items, int $periodid, string $periodlabel, int $startdate, int $enddate): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'items' => $items,
            'periodid' => $periodid,
            'periodlabel' => $periodlabel,
            'startdate' => $startdate,
            'enddate' => $enddate,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        return cloneplan::build(
            self::validate_items($params['items']),
            $params['periodid'],
            $params['periodlabel'],
            $params['startdate'],
            $params['enddate']
        );
    }

    /**
     * Returns: the rows, their total and the signature of the preview.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Seed course id'),
                'fullname' => new external_value(PARAM_TEXT, 'Seed full name, formatted'),
                'shortname' => new external_value(PARAM_TEXT, 'Seed short name, formatted'),
                'destpath' => new external_value(PARAM_TEXT, 'Destination path, formatted'),
                'destcategoryid' => new external_value(PARAM_INT, 'Destination category id; 0 when it is still to be created'),
                'newcategories' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Path of a category to create, formatted'),
                    'Categories the copy needs and that do not exist yet'
                ),
                'newfullname' => new external_value(PARAM_RAW, 'Full name of the copy, raw'),
                'newshortname' => new external_value(PARAM_RAW, 'Short name of the copy, raw'),
                'error' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Error code that stops this row, or null',
                    VALUE_REQUIRED,
                    null,
                    NULL_ALLOWED
                ),
            ])),
            'total' => new external_value(PARAM_INT, 'Number of rows'),
            'hash' => new external_value(PARAM_ALPHANUM, 'Signature of the preview, to pass to clone_apply'),
        ]);
    }
}
