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
 * Web service: what «Ocultar todo» or «Mostrar todo» would change.
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
use tool_ceaorganizador\local\visibilitytree;

/**
 * Counts the categories and courses whose visibility would change, with the hash to apply it. It writes nothing.
 */
class visibility_tree_preview extends external_api {
    /**
     * Parameters: the categories and the visibility to give their whole subtrees.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryids' => new external_multiple_structure(new external_value(PARAM_INT, 'Category id')),
            'show' => new external_value(PARAM_BOOL, 'True for «Mostrar todo», false for «Ocultar todo»'),
        ]);
    }

    /**
     * Previews the operation.
     *
     * @param int[] $categoryids
     * @param bool $show
     * @return array ['categories' => int, 'courses' => int, 'hash' => string]
     */
    public static function execute(array $categoryids, bool $show): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryids' => $categoryids,
            'show' => $show,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $plan = visibilitytree::plan($params['categoryids'], (bool) $params['show']);
        return [
            'categories' => count($plan['categories']),
            'courses' => count($plan['courses']),
            'hash' => $plan['hash'],
        ];
    }

    /**
     * Returns: the counts of what would change and the hash.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'categories' => new external_value(PARAM_INT, 'Categories whose visibility would change'),
            'courses' => new external_value(PARAM_INT, 'Courses whose visibility would change'),
            'hash' => new external_value(PARAM_ALPHANUM, 'Send it to visibility_tree_apply'),
        ]);
    }
}
