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
 * Web service: preview of a rename.
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
use tool_ceaorganizador\local\renamer;

/**
 * Applies the operations to the raw names of the items and flags the rows that cannot be applied.
 *
 * Names are the raw database values, so rename_apply can compare them; the client shows them as text.
 * With includeshortname the short name of each course goes through the same operations (the manual
 * table edits only the full name). Errors: those of renamer::preview(), plus 'shortnametaken' (used
 * by a course outside the batch) and 'shortnameduplicate' (two rows of the batch end with the same
 * short name; both are flagged). A short name that becomes empty or too long reuses 'empty' and
 * 'toolong'.
 */
class rename_preview extends external_api {
    /** @var int Maximum number of operations. */
    public const MAXOPS = 50;

    /** @var array Accepted keys of each operation type, with their validator. */
    private const OPS = [
        'replace' => ['search' => 'string', 'replace' => 'string', 'casesensitive' => 'bool', 'wholeword' => 'bool'],
        'clean' => ['case' => ['none', 'title', 'sentence', 'upper']],
        'affix' => ['position' => ['prefix', 'suffix'], 'mode' => ['add', 'remove'], 'text' => 'string'],
    ];

    /**
     * Parameters: items, operations and manual names as JSON, and whether short names change too.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'category or course'),
                'id' => new external_value(PARAM_INT, 'Item id'),
            ])),
            'ops' => new external_value(PARAM_RAW, 'Operations as a JSON list', VALUE_DEFAULT, '[]'),
            'manual' => new external_value(PARAM_RAW, 'JSON object "type:id" => full name override', VALUE_DEFAULT, '{}'),
            'includeshortname' => new external_value(PARAM_BOOL, 'Also rename the course short names', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Builds the preview rows.
     *
     * @param array $items
     * @param string $ops
     * @param string $manual
     * @param bool $includeshortname
     * @return array ['rows' => [['type', 'id', 'old', 'new', 'error', 'oldshort'?, 'newshort'?]]]
     */
    public static function execute(array $items, string $ops = '[]', string $manual = '{}', bool $includeshortname = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'items' => $items,
            'ops' => $ops,
            'manual' => $manual,
            'includeshortname' => $includeshortname,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);

        $ops = self::parse_ops($params['ops']);
        $manual = self::parse_manual($params['manual']);
        $records = self::load($params['items']);

        $rows = [];
        foreach (renamer::preview($records, $ops, $manual) as $row) {
            $rows[$row['type'] . ':' . $row['id']] = $row;
        }
        if ($params['includeshortname']) {
            $rows = self::shortnames($records, $ops, $rows);
        }
        return ['rows' => array_values($rows)];
    }

    /**
     * Adds the short names to the course rows, and the rows of courses whose only change is the short name.
     *
     * @param array $records Loaded items.
     * @param array $ops
     * @param array $rows Preview rows keyed by "type:id".
     * @return array Rows keyed by "type:id".
     */
    private static function shortnames(array $records, array $ops, array $rows): array {
        global $DB;
        // Final short name of every course of the batch, changed or not.
        $final = [];
        foreach ($records as $record) {
            if ($record['type'] !== 'course') {
                continue;
            }
            $key = 'course:' . $record['id'];
            $old = $record['shortname'];
            $new = strpos($old, '<') !== false ? $old : trim(renamer::apply($old, $ops));
            $final[$key] = $new;
            if ($new === $old && !isset($rows[$key])) {
                continue;
            }
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'type' => 'course',
                    'id' => $record['id'],
                    'old' => $record['name'],
                    'new' => $record['name'],
                    'error' => null,
                ];
            }
            $rows[$key]['oldshort'] = $old;
            $rows[$key]['newshort'] = $new;
            if ($rows[$key]['error'] !== null || $new === $old) {
                continue;
            }
            if ($new === '') {
                $rows[$key]['error'] = 'empty';
            } else if (\core_text::strlen($new) > renamer::MAXLEN) {
                $rows[$key]['error'] = 'toolong';
            } else {
                // Held by a course outside the batch; holders inside the batch are checked as duplicates.
                [$notin, $sqlparams] = $DB->get_in_or_equal(self::course_ids($records), SQL_PARAMS_NAMED, 'batch', false);
                $sqlparams['shortname'] = $new;
                if ($DB->record_exists_select('course', "shortname = :shortname AND id $notin", $sqlparams)) {
                    $rows[$key]['error'] = 'shortnametaken';
                }
            }
        }

        // Two courses of the batch that end with the same short name, when at least one of them changes it.
        $holders = [];
        foreach ($final as $key => $shortname) {
            $holders[$shortname][] = $key;
        }
        foreach ($holders as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            $changed = false;
            foreach ($keys as $key) {
                $changed = $changed || (isset($rows[$key]) && $rows[$key]['newshort'] !== $rows[$key]['oldshort']);
            }
            if (!$changed) {
                continue;
            }
            foreach ($keys as $key) {
                if (!isset($rows[$key])) {
                    $record = $records[$key];
                    $rows[$key] = [
                        'type' => 'course',
                        'id' => $record['id'],
                        'old' => $record['name'],
                        'new' => $record['name'],
                        'error' => null,
                        'oldshort' => $record['shortname'],
                        'newshort' => $record['shortname'],
                    ];
                }
                if ($rows[$key]['error'] === null || $rows[$key]['error'] === 'shortnametaken') {
                    $rows[$key]['error'] = 'shortnameduplicate';
                }
            }
        }
        return $rows;
    }

    /**
     * Ids of the courses of the batch.
     *
     * @param array $records
     * @return int[]
     */
    private static function course_ids(array $records): array {
        $ids = [];
        foreach ($records as $record) {
            if ($record['type'] === 'course') {
                $ids[] = $record['id'];
            }
        }
        return $ids ?: [0];
    }

    /**
     * Loads the raw names of the items; items that no longer exist are left out.
     *
     * @param array $items List of ['type', 'id'].
     * @return array Items for renamer::preview(), keyed by "type:id".
     */
    private static function load(array $items): array {
        global $DB;
        $ids = ['category' => [], 'course' => []];
        foreach ($items as $item) {
            if (!isset($ids[$item['type']])) {
                throw new \invalid_parameter_exception('Invalid item type: ' . $item['type']);
            }
            $ids[$item['type']][(int) $item['id']] = (int) $item['id'];
        }
        $categories = $ids['category']
            ? $DB->get_records_list('course_categories', 'id', array_values($ids['category']), '', 'id, name')
            : [];
        $courses = $ids['course']
            ? $DB->get_records_list('course', 'id', array_values($ids['course']), '', 'id, fullname, shortname')
            : [];

        $records = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $key = $item['type'] . ':' . $id;
            if (isset($records[$key])) {
                continue;
            }
            if ($item['type'] === 'category' && isset($categories[$id])) {
                $records[$key] = ['type' => 'category', 'id' => $id, 'name' => $categories[$id]->name, 'shortname' => ''];
            } else if ($item['type'] === 'course' && isset($courses[$id]) && $id !== (int) SITEID) {
                $course = $courses[$id];
                $records[$key] = ['type' => 'course', 'id' => $id, 'name' => $course->fullname, 'shortname' => $course->shortname];
            }
        }
        return $records;
    }

    /**
     * Decodes and validates the operations against the formats the renamer accepts.
     *
     * @param string $json
     * @return array
     * @throws \invalid_parameter_exception
     */
    public static function parse_ops(string $json): array {
        $ops = trim($json) === '' ? [] : json_decode($json, true);
        if (!is_array($ops) || ($ops && array_keys($ops) !== range(0, count($ops) - 1))) {
            throw new \invalid_parameter_exception('ops must be a JSON list');
        }
        if (count($ops) > self::MAXOPS) {
            throw new \invalid_parameter_exception('Too many operations');
        }
        foreach ($ops as $op) {
            if (!is_array($op) || !isset($op['type']) || !is_string($op['type']) || !isset(self::OPS[$op['type']])) {
                throw new \invalid_parameter_exception('Invalid operation type');
            }
            $allowed = self::OPS[$op['type']];
            foreach ($op as $key => $value) {
                if ($key === 'type') {
                    continue;
                }
                if (!isset($allowed[$key])) {
                    throw new \invalid_parameter_exception('Invalid operation key: ' . $key);
                }
                $rule = $allowed[$key];
                $valid = is_array($rule)
                    ? is_string($value) && in_array($value, $rule, true)
                    : ($rule === 'bool' ? is_bool($value) : is_string($value) && \core_text::strlen($value) <= renamer::MAXLEN);
                if (!$valid) {
                    throw new \invalid_parameter_exception('Invalid value for operation key: ' . $key);
                }
            }
        }
        return $ops;
    }

    /**
     * Decodes and validates the manual names: an object "category:id" or "course:id" => string.
     *
     * @param string $json
     * @return array
     * @throws \invalid_parameter_exception
     */
    public static function parse_manual(string $json): array {
        $manual = trim($json) === '' ? [] : json_decode($json, true);
        if (!is_array($manual)) {
            throw new \invalid_parameter_exception('manual must be a JSON object');
        }
        foreach ($manual as $key => $value) {
            if (!is_string($key) || !preg_match('/^(category|course):[1-9]\d*$/', $key) || !is_string($value)) {
                throw new \invalid_parameter_exception('Invalid manual entry');
            }
        }
        return $manual;
    }

    /**
     * Returns: the rows that change or have an error, with raw names.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'category or course'),
                'id' => new external_value(PARAM_INT, 'Item id'),
                'old' => new external_value(PARAM_RAW, 'Current raw name; send it back to rename_apply as is'),
                'new' => new external_value(PARAM_RAW, 'New raw name'),
                'error' => new external_value(PARAM_ALPHA, 'Error code, or null when the row can be applied'),
                'oldshort' => new external_value(PARAM_RAW, 'Current raw short name (courses, includeshortname)', VALUE_OPTIONAL),
                'newshort' => new external_value(PARAM_RAW, 'New raw short name (courses, includeshortname)', VALUE_OPTIONAL),
            ])),
        ]);
    }
}
