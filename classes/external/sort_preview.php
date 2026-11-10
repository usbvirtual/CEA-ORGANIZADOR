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
 * Web service: preview of an automatic sort.
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
use tool_ceaorganizador\local\sortplan;

/**
 * The lists an automatic sort would change, before and after, with the signature for sort_apply.
 */
class sort_preview extends external_api {
    /**
     * Parameters: category, scope and recursion; shared with sort_apply.
     *
     * @return array
     */
    public static function plan_parameters(): array {
        return [
            'categoryid' => new external_value(PARAM_INT, 'Category id, 0 for the top level'),
            'scope' => new external_value(PARAM_ALPHA, 'categories, courses or both'),
            'recursive' => new external_value(PARAM_BOOL, 'Also every category below', VALUE_DEFAULT, false),
        ];
    }

    /**
     * Validates the scope, which PARAM_ALPHA alone does not restrict.
     *
     * @param string $scope
     * @throws \invalid_parameter_exception
     */
    public static function validate_scope(string $scope): void {
        if (!in_array($scope, sortplan::SCOPES, true)) {
            throw new \invalid_parameter_exception('Invalid scope: ' . $scope);
        }
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(self::plan_parameters());
    }

    /**
     * Builds the plan.
     *
     * @param int $categoryid
     * @param string $scope
     * @param bool $recursive
     * @return array ['changes' => array, 'hash' => string]
     */
    public static function execute(int $categoryid, string $scope, bool $recursive = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'scope' => $scope,
            'recursive' => $recursive,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        self::validate_scope($params['scope']);

        return sortplan::build(max(0, $params['categoryid']), $params['scope'], $params['recursive']);
    }

    /**
     * Returns: the changes, names and paths already formatted (HTML-safe), and the hash.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $item = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Category or course id'),
            'name' => new external_value(PARAM_RAW, 'Formatted name'),
        ]);
        return new external_single_structure([
            'changes' => new external_multiple_structure(new external_single_structure([
                'kind' => new external_value(PARAM_ALPHA, 'categories or courses'),
                'parentid' => new external_value(PARAM_INT, 'Parent category (categories) or the category (courses)'),
                'parentpath' => new external_value(PARAM_RAW, 'Formatted path of that category'),
                'before' => new external_multiple_structure($item, 'Current order'),
                'after' => new external_multiple_structure($item, 'Proposed order'),
            ])),
            'hash' => new external_value(PARAM_ALPHANUM, 'Signature of the plan, for sort_apply'),
        ]);
    }
}
