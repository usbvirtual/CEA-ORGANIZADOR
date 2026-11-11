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
 * Web service: progress of a cloning batch.
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
 * Reports, for each copy of a batch, whether the cron has finished it.
 */
class clone_status extends external_api {
    /**
     * Parameters: the journal id of the batch.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'batchid' => new external_value(PARAM_INT, 'Journal id returned by clone_apply'),
        ]);
    }

    /**
     * Status of every copy of a batch. It writes nothing.
     *
     * The state comes from the restore controller of each copy; when the cron has already purged it, from
     * whether the new course exists with the expected short name. Running copies count as pending.
     *
     * @param int $batchid
     * @return array ['rows' => array[], 'pending' => int, 'done' => int, 'failed' => int]
     */
    public static function execute(int $batchid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $params = self::validate_parameters(self::execute_parameters(), ['batchid' => $batchid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $log = $DB->get_record(journal::TABLE, ['id' => $params['batchid']], 'id, action, payload');
        if (!$log || $log->action !== 'clone') {
            throw new \invalid_parameter_exception('Not a clone batch');
        }
        $payload = json_decode($log->payload, true);
        $items = is_array($payload) ? ($payload['items'] ?? []) : [];

        $restoreids = [];
        $courseids = [];
        foreach ($items as $item) {
            if ((string) ($item['restoreid'] ?? '') !== '') {
                $restoreids[] = (string) $item['restoreid'];
            }
            if ((int) ($item['newcourseid'] ?? 0) > 0) {
                $courseids[] = (int) $item['newcourseid'];
            }
        }
        $controllers = [];
        if ($restoreids) {
            [$insql, $inparams] = $DB->get_in_or_equal($restoreids, SQL_PARAMS_NAMED);
            $controllers = $DB->get_records_sql_menu(
                "SELECT backupid, status FROM {backup_controllers} WHERE backupid $insql",
                $inparams
            );
        }
        $courses = $courseids ? $DB->get_records_list('course', 'id', $courseids, '', 'id, shortname') : [];

        $rows = [];
        $totals = ['pending' => 0, 'done' => 0, 'failed' => 0];
        foreach ($items as $item) {
            $newcourseid = (int) ($item['newcourseid'] ?? 0);
            $status = self::item_status($item, $controllers, $courses);
            $totals[$status === 'running' ? 'pending' : $status]++;
            $rows[] = [
                'source' => (int) $item['source'],
                'newcourseid' => $newcourseid,
                'newfullname' => (string) $item['newfullname'],
                'status' => $status,
                'url' => $newcourseid > 0
                    ? (new \moodle_url('/course/view.php', ['id' => $newcourseid]))->out(false) : '',
            ];
        }
        return ['rows' => $rows] + $totals;
    }

    /**
     * State of one copy.
     *
     * @param array $item Payload item.
     * @param array $controllers Map backupid => status of the restore controllers.
     * @param \stdClass[] $courses Courses by id, with their short name.
     * @return string pending, running, done or failed
     */
    private static function item_status(array $item, array $controllers, array $courses): string {
        if (!empty($item['error'])) {
            return 'failed';
        }
        $restoreid = (string) ($item['restoreid'] ?? '');
        if ($restoreid !== '' && isset($controllers[$restoreid])) {
            switch ((int) $controllers[$restoreid]) {
                case \backup::STATUS_FINISHED_OK:
                    return 'done';
                case \backup::STATUS_FINISHED_ERR:
                    return 'failed';
                case \backup::STATUS_EXECUTING:
                    return 'running';
                default:
                    return 'pending';
            }
        }
        // No controller (the cron purges them): the copy is done when its course carries the expected name.
        $course = $courses[(int) ($item['newcourseid'] ?? 0)] ?? null;
        return ($course && $course->shortname === (string) $item['newshortname']) ? 'done' : 'failed';
    }

    /**
     * Returns: one row per copy and the totals.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'source' => new external_value(PARAM_INT, 'Seed course id'),
                'newcourseid' => new external_value(PARAM_INT, 'Id of the new course; 0 when it was not created'),
                'newfullname' => new external_value(PARAM_RAW, 'Full name of the copy, raw'),
                'status' => new external_value(PARAM_ALPHA, 'pending, running, done or failed'),
                'url' => new external_value(PARAM_RAW, 'Address of the new course; empty when there is none'),
            ])),
            'pending' => new external_value(PARAM_INT, 'Copies waiting or running'),
            'done' => new external_value(PARAM_INT, 'Copies finished'),
            'failed' => new external_value(PARAM_INT, 'Copies that failed'),
        ]);
    }
}
