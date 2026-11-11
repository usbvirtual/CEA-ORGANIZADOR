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
    /** @var int Most categories of one request. */
    public const MAX_CATEGORIES = 200;

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
     * The optional list of categories, shared with sort_apply.
     *
     * @return external_multiple_structure
     */
    public static function categoryids_parameter(): external_multiple_structure {
        return new external_multiple_structure(
            new external_value(PARAM_INT, 'Category id'),
            'Several categories to sort; when present it replaces categoryid',
            VALUE_DEFAULT,
            []
        );
    }

    /**
     * The categories to sort: the list when it has elements, otherwise the single category.
     *
     * @param int $categoryid
     * @param int[] $categoryids
     * @return int[]
     * @throws \invalid_parameter_exception
     */
    public static function resolve_categories(int $categoryid, array $categoryids): array {
        if (!$categoryids) {
            return [max(0, $categoryid)];
        }
        if ($categoryid !== 0) {
            throw new \invalid_parameter_exception('categoryid and categoryids cannot be combined');
        }
        if (count($categoryids) > self::MAX_CATEGORIES) {
            throw new \invalid_parameter_exception('Too many categories (maximum ' . self::MAX_CATEGORIES . ')');
        }
        foreach ($categoryids as $id) {
            if ((int) $id <= 0) {
                throw new \invalid_parameter_exception('Invalid category id: ' . $id);
            }
        }
        return array_values(array_unique(array_map('intval', $categoryids)));
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
        return new external_function_parameters(self::plan_parameters() + [
            'categoryids' => self::categoryids_parameter(),
        ]);
    }

    /**
     * Builds the plan.
     *
     * @param int $categoryid
     * @param string $scope
     * @param bool $recursive
     * @param int[] $categoryids Several categories; replaces categoryid when not empty.
     * @return array ['changes' => array, 'hash' => string]
     */
    public static function execute(int $categoryid, string $scope, bool $recursive = false, array $categoryids = []): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'scope' => $scope,
            'recursive' => $recursive,
            'categoryids' => $categoryids,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        self::validate_scope($params['scope']);
        $ids = self::resolve_categories($params['categoryid'], $params['categoryids']);

        return sortplan::build_many($ids, $params['scope'], $params['recursive']);
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
