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
 * Web service: apply a rename.
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
use tool_ceaorganizador\local\mover;
use tool_ceaorganizador\local\renamer;

/**
 * Applies explicit renames, only where the current name is still the one the preview showed.
 *
 * A row with newshort must carry oldshort. Rows whose current raw name differs from 'old' (or short
 * name from 'oldshort'), or whose
 * item no longer exists, come back 'skipped'. Rows with an empty or too long new name come back
 * 'error' and are not applied. The other rows are applied all together in ONE transaction: when
 * any of them throws (for example a short name taken meanwhile), everything rolls back and the
 * whole call fails, so the journal entry always matches what was written and the undo is exact.
 */
class rename_apply extends external_api {
    /** @var int Maximum number of rows in one call. */
    public const MAXITEMS = 500;

    /**
     * Parameters: the explicit changes.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'changes' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'category or course'),
                'id' => new external_value(PARAM_INT, 'Item id'),
                'old' => new external_value(PARAM_RAW, 'Raw name shown by the preview'),
                'new' => new external_value(PARAM_TEXT, 'New name'),
                'oldshort' => new external_value(PARAM_RAW, 'Preview raw short name; needed with newshort', VALUE_OPTIONAL),
                'newshort' => new external_value(PARAM_TEXT, 'New short name (courses)', VALUE_OPTIONAL),
            ])),
        ]);
    }

    /**
     * Applies the changes.
     *
     * @param array $changes
     * @return array ['results' => [['type', 'id', 'status', 'message']], 'logid' => int]; logid is 0 when nothing was applied.
     */
    public static function execute(array $changes): array {
        // A batch of up to 500 renames can take a while.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $params = self::validate_parameters(self::execute_parameters(), ['changes' => $changes]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        $changes = $params['changes'];
        if (count($changes) > self::MAXITEMS) {
            throw new \moodle_exception('error_toomany', 'tool_ceaorganizador');
        }
        $seen = [];
        foreach ($changes as $change) {
            $key = $change['type'] . ':' . $change['id'];
            if (!in_array($change['type'], ['category', 'course'], true) || isset($seen[$key])) {
                throw new \invalid_parameter_exception('Invalid or repeated item: ' . $key);
            }
            // The short name is compared like the name, so the preview's value is required.
            if (isset($change['newshort']) && !isset($change['oldshort'])) {
                throw new \invalid_parameter_exception('newshort requires oldshort: ' . $key);
            }
            $seen[$key] = true;
        }

        $lock = guard::lock();
        try {
            [$results, $apply] = self::classify($changes);
            foreach ($apply as $row) {
                if ($row['type'] === 'category') {
                    mover::check_category($row['id']);
                } else {
                    mover::check_course($row['id'], 'moodle/course:update');
                }
            }
            $logid = 0;
            if ($apply) {
                $logid = self::apply(self::order($apply));
            }
        } finally {
            $lock->release();
        }
        return ['results' => array_values($results), 'logid' => $logid];
    }

    /**
     * Compares every row with the live raw names.
     *
     * @param array $changes
     * @return array [results keyed by "type:id" in the given order, rows to apply]
     */
    private static function classify(array $changes): array {
        global $DB;
        $ids = ['category' => [0], 'course' => [0]];
        foreach ($changes as $change) {
            $ids[$change['type']][] = (int) $change['id'];
        }
        $categories = $DB->get_records_list('course_categories', 'id', $ids['category'], '', 'id, name');
        $courses = $DB->get_records_list('course', 'id', $ids['course'], '', 'id, fullname, shortname');

        $results = [];
        $apply = [];
        foreach ($changes as $change) {
            $id = (int) $change['id'];
            $type = $change['type'];
            $key = $type . ':' . $id;
            $result = ['type' => $type, 'id' => $id, 'status' => 'ok', 'message' => ''];
            $new = trim($change['new']);
            $newshort = ($type === 'course' && isset($change['newshort'])) ? trim($change['newshort']) : null;

            if ($type === 'category') {
                $current = isset($categories[$id]) ? $categories[$id]->name : null;
                $currentshort = null;
            } else {
                $exists = isset($courses[$id]) && $id !== (int) SITEID;
                $current = $exists ? $courses[$id]->fullname : null;
                $currentshort = $exists ? $courses[$id]->shortname : null;
            }
            $shortchanged = isset($change['oldshort']) && $type === 'course' && $currentshort !== $change['oldshort'];

            if ($current === null || $current !== $change['old'] || $shortchanged) {
                $result['status'] = 'skipped';
                $result['message'] = get_string('renameskipped', 'tool_ceaorganizador');
            } else if ($new === '' || $newshort === '') {
                $result['status'] = 'error';
                $result['message'] = get_string('renameerror_empty', 'tool_ceaorganizador');
            } else if (\core_text::strlen($new) > renamer::MAXLEN || \core_text::strlen((string) $newshort) > renamer::MAXLEN) {
                $result['status'] = 'error';
                $result['message'] = get_string('renameerror_toolong', 'tool_ceaorganizador');
            } else {
                $newshort = $newshort === $currentshort ? null : $newshort;
                if ($new !== $current || $newshort !== null) {
                    $apply[] = [
                        'type' => $type,
                        'id' => $id,
                        'old' => $current,
                        'new' => $new,
                        'oldshort' => $currentshort,
                        'newshort' => $newshort,
                    ];
                }
            }
            $results[$key] = $result;
        }
        return [$results, $apply];
    }

    /**
     * Orders the rows so a short name is freed before another course of the batch takes it.
     *
     * Categories go first. A cycle (two courses swapping short names) keeps the given order and
     * fails on apply, which rolls everything back.
     *
     * @param array $rows
     * @return array
     */
    private static function order(array $rows): array {
        $ordered = [];
        $pending = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'category') {
                $ordered[] = $row;
            } else {
                $pending[] = $row;
            }
        }
        while ($pending) {
            $held = [];
            foreach ($pending as $row) {
                $held[$row['oldshort']] = $row['id'];
            }
            $pick = 0;
            foreach ($pending as $index => $row) {
                $holder = $row['newshort'] === null ? null : ($held[$row['newshort']] ?? null);
                if ($holder === null || $holder === $row['id']) {
                    $pick = $index;
                    break;
                }
            }
            $ordered[] = $pending[$pick];
            array_splice($pending, $pick, 1);
        }
        return $ordered;
    }

    /**
     * Applies the rows in one transaction and records one journal entry with them.
     *
     * @param array $rows
     * @return int Journal id.
     */
    private static function apply(array $rows): int {
        global $DB;
        $items = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($rows as $row) {
                if ($row['type'] === 'category') {
                    mover::rename_category($row['id'], $row['new']);
                    $items[] = [
                        'type' => 'category',
                        'id' => $row['id'],
                        'before' => ['name' => $row['old']],
                        'after' => ['name' => $row['new']],
                    ];
                    continue;
                }
                mover::rename_course($row['id'], $row['new'], $row['newshort']);
                $before = ['fullname' => $row['old']];
                $after = ['fullname' => $row['new']];
                if ($row['newshort'] !== null) {
                    $before['shortname'] = $row['oldshort'];
                    $after['shortname'] = $row['newshort'];
                }
                $items[] = ['type' => 'course', 'id' => $row['id'], 'before' => $before, 'after' => $after];
            }
            $summary = get_string('summary_rename', 'tool_ceaorganizador', count($items));
            $logid = journal::record('rename', $summary, ['items' => $items]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
        return $logid;
    }

    /**
     * Returns: one result per row, in the given order, and the journal id.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'category or course'),
                'id' => new external_value(PARAM_INT, 'Item id'),
                'status' => new external_value(PARAM_ALPHA, 'ok, skipped or error'),
                'message' => new external_value(PARAM_TEXT, 'Why it was skipped or failed'),
            ])),
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo; 0 when nothing was applied'),
        ]);
    }
}
