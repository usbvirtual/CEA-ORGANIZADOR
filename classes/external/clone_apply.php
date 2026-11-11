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
 * Web service: apply a cloning batch.
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
use tool_ceaorganizador\local\clonerunner;

/**
 * Queues the copies of a preview that is still current. The lock lives in clonerunner::run().
 */
class clone_apply extends external_api {
    /**
     * Parameters: the same as clone_preview, plus the edited names and the hash; the container goes last.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(clone_preview::plan_parameters() + [
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Seed course id'),
                    'newfullname' => new external_value(PARAM_RAW, 'Full name of the copy, raw'),
                    'newshortname' => new external_value(PARAM_RAW, 'Short name of the copy, raw'),
                ]),
                'Names as the user left them'
            ),
            'hash' => new external_value(PARAM_ALPHANUM, 'Hash returned by clone_preview'),
        ] + clone_preview::container_parameter());
    }

    /**
     * Queues the copies of the batch.
     *
     * @param array $items
     * @param int $periodid
     * @param string $periodlabel
     * @param int $startdate
     * @param int $enddate
     * @param array $rows
     * @param string $hash
     * @param string $container
     * @return array ['status' => string, 'batchid' => int, 'queued' => int, 'skipped' => int]
     */
    public static function execute(
        array $items,
        int $periodid,
        string $periodlabel,
        int $startdate,
        int $enddate,
        array $rows,
        string $hash,
        string $container = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'items' => $items,
            'periodid' => $periodid,
            'periodlabel' => $periodlabel,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'rows' => $rows,
            'hash' => $hash,
            'container' => $container,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $edits = [];
        foreach ($params['rows'] as $row) {
            $edits[$row['courseid']] = [
                'newfullname' => $row['newfullname'],
                'newshortname' => $row['newshortname'],
            ];
        }
        return clonerunner::run(
            clone_preview::validate_items($params['items']),
            $params['periodid'],
            $params['periodlabel'],
            $params['startdate'],
            $params['enddate'],
            $edits,
            $params['hash'],
            $params['container']
        );
    }

    /**
     * Returns: the status, the journal id and the counts.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, or stale when the preview is no longer current'),
            'batchid' => new external_value(PARAM_INT, 'Journal id of the batch; 0 when nothing was recorded'),
            'queued' => new external_value(PARAM_INT, 'Copies queued'),
            'skipped' => new external_value(PARAM_INT, 'Rows that were not copied'),
        ]);
    }
}
