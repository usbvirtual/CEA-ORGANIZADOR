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
 * Web service: one page of the history.
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
use tool_ceaorganizador\local\journal;

/**
 * The latest operations, newest first, 20 per page.
 */
class history extends external_api {
    /** @var int Rows per page. */
    public const PERPAGE = 20;

    /**
     * Parameters: the page.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page' => new external_value(PARAM_INT, 'Zero-based page', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Lists one page of the journal.
     *
     * The summary holds raw names, so it is formatted here; summary and user name come back HTML-safe.
     *
     * @param int $page
     * @return array ['rows' => array, 'total' => int, 'perpage' => int]
     */
    public static function execute(int $page = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['page' => $page]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $history = journal::history(max(0, $params['page']), self::PERPAGE);
        $rows = [];
        foreach ($history['rows'] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'action' => $row['action'],
                'summary' => format_string($row['summary'], true, ['context' => $context]),
                'userfullname' => s($row['userfullname']),
                'timecreated' => (int) $row['timecreated'],
                'undone' => (bool) $row['undone'],
            ];
        }
        return ['rows' => $rows, 'total' => (int) $history['total'], 'perpage' => self::PERPAGE];
    }

    /**
     * Returns: the rows, the total and the page size.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Journal id'),
                'action' => new external_value(PARAM_ALPHANUMEXT, 'move_category, move_course, sort or rename'),
                'summary' => new external_value(PARAM_RAW, 'Formatted summary (HTML-safe)'),
                'userfullname' => new external_value(PARAM_RAW, 'Escaped name of who did it; empty when anonymised'),
                'timecreated' => new external_value(PARAM_INT, 'When it was done'),
                'undone' => new external_value(PARAM_BOOL, 'Whether it was undone'),
            ])),
            'total' => new external_value(PARAM_INT, 'Number of rows in the journal'),
            'perpage' => new external_value(PARAM_INT, 'Rows per page'),
        ]);
    }
}
