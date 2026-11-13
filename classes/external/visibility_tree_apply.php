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
 * Web service: «Ocultar todo» or «Mostrar todo».
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
use tool_ceaorganizador\local\guard;
use tool_ceaorganizador\local\journal;
use tool_ceaorganizador\local\partial_exception;
use tool_ceaorganizador\local\visibilitytree;

/**
 * Hides or shows the categories, all their subcategories and all their courses, when the preview is still current.
 *
 * The write lock is taken here; the permissions are checked by visibilitytree before any write.
 */
class visibility_tree_apply extends external_api {
    /**
     * Parameters: as visibility_tree_preview, and its hash.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryids' => new external_multiple_structure(new external_value(PARAM_INT, 'Category id')),
            'show' => new external_value(PARAM_BOOL, 'True for «Mostrar todo», false for «Ocultar todo»'),
            'hash' => new external_value(PARAM_ALPHANUM, 'Hash returned by visibility_tree_preview'),
        ]);
    }

    /**
     * Applies the operation under the write lock.
     *
     * @param int[] $categoryids
     * @param bool $show
     * @param string $hash
     * @return array ['status' => string, 'logid' => int, 'categories' => int, 'courses' => int]
     */
    public static function execute(array $categoryids, bool $show, string $hash): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryids' => $categoryids,
            'show' => $show,
            'hash' => $hash,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $lock = guard::lock();
        try {
            return visibilitytree::run($params['categoryids'], (bool) $params['show'], $params['hash']);
        } catch (partial_exception $e) {
            // Half done: what changed is recorded under logid, and the interface offers to undo it.
            $payload = json_decode((string) $DB->get_field(journal::TABLE, 'payload', ['id' => $e->logid]), true);
            return [
                'status' => 'partial',
                'logid' => $e->logid,
                'categories' => count($payload['categories'] ?? []),
                'courses' => count($payload['courses'] ?? []),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns: the status, the journal id and the counts of what changed.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(
                PARAM_ALPHA,
                'ok, stale (the preview changed) or partial (stopped halfway; logid undoes it)'
            ),
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo; 0 when nothing was recorded'),
            'categories' => new external_value(PARAM_INT, 'Categories whose visibility changed'),
            'courses' => new external_value(PARAM_INT, 'Courses whose visibility changed'),
        ]);
    }
}
