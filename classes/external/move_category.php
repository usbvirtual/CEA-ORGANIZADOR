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
 * Web service: move a category.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use tool_ceaorganizador\local\guard;
use tool_ceaorganizador\local\journal;
use tool_ceaorganizador\local\mover;

/**
 * Moves a category under a parent, right after a sibling, and records it for the undo.
 */
class move_category extends external_api {
    /**
     * Parameters: the category, its new parent and the sibling it goes after.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Category id'),
            'newparent' => new external_value(PARAM_INT, 'New parent id, 0 for top level'),
            'afterid' => new external_value(PARAM_INT, 'Sibling it goes after, 0 for first'),
        ]);
    }

    /**
     * Moves the category.
     *
     * @param int $id
     * @param int $newparent
     * @param int $afterid
     * @return array ['logid' => int, 'from' => ['parent', 'afterid'], 'to' => ['parent', 'afterid']]
     */
    public static function execute(int $id, int $newparent, int $afterid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'newparent' => $newparent,
            'afterid' => $afterid,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $lock = guard::lock();
        try {
            // Read under the lock: the current parent is the source to check.
            $oldparent = (int) $DB->get_field('course_categories', 'parent', ['id' => $params['id']], MUST_EXIST);
            mover::check_category($params['id']);
            if ($oldparent !== 0) {
                mover::check_category($oldparent);
            }
            if ($params['newparent'] !== 0) {
                $DB->get_record('course_categories', ['id' => $params['newparent']], 'id', MUST_EXIST);
            }
            mover::check_category($params['newparent']);
            $result = journal::move_category($params['id'], $params['newparent'], $params['afterid']);
        } finally {
            $lock->release();
        }
        return [
            'logid' => (int) $result['logid'],
            'from' => [
                'parent' => (int) $result['from']['parent'],
                'afterid' => (int) $result['from']['afterid'],
            ],
            'to' => [
                'parent' => (int) $result['to']['parent'],
                'afterid' => (int) $result['to']['afterid'],
            ],
        ];
    }

    /**
     * Returns: the journal id and the position before and after.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo'),
            'from' => self::position('Position before'),
            'to' => self::position('Position after'),
        ]);
    }

    /**
     * A position: parent and previous sibling.
     *
     * @param string $desc
     * @return external_single_structure
     */
    private static function position(string $desc): external_single_structure {
        $keys = [
            'parent' => new external_value(PARAM_INT, 'Parent id, 0 for top level'),
            'afterid' => new external_value(PARAM_INT, 'Previous sibling, 0 for first'),
        ];
        return new external_single_structure($keys, $desc);
    }
}
