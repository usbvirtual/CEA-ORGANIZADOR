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
 * Web service: undo a recorded operation.
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

/**
 * Undoes an operation of the journal, or says what changed since and blocks it.
 */
class undo extends external_api {
    /**
     * Parameters: the journal id.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'logid' => new external_value(PARAM_INT, 'Journal id'),
        ]);
    }

    /**
     * Undoes the operation under the write lock.
     *
     * The native capabilities are checked on every category and course the undo writes, as for
     * the original operation; items that no longer exist are left to the journal, which reports them.
     *
     * @param int $logid
     * @return array ['ok' => bool, 'conflicts' => string[]]
     */
    public static function execute(int $logid): array {
        global $DB;
        // Undoing a large sort or rename can take a while.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $params = self::validate_parameters(self::execute_parameters(), ['logid' => $logid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        // A clone is never undone; refuse it before taking the lock (journal::undo refuses it too).
        if ($DB->get_field(journal::TABLE, 'action', ['id' => $params['logid']]) === 'clone') {
            throw new \moodle_exception('error_cannotundoclone', 'tool_ceaorganizador');
        }

        $lock = guard::lock();
        try {
            $log = $DB->get_record(journal::TABLE, ['id' => $params['logid']], 'id, action, payload', MUST_EXIST);
            $payload = json_decode($log->payload, true);
            if (is_array($payload)) {
                self::check($log->action, $payload);
            }
            $result = journal::undo($params['logid']);
        } finally {
            $lock->release();
        }
        return [
            'ok' => (bool) $result['ok'],
            'conflicts' => array_values(array_map('strval', $result['conflicts'])),
        ];
    }

    /**
     * Requires the native capabilities on the items the undo would write.
     *
     * @param string $action
     * @param array $payload
     */
    private static function check(string $action, array $payload): void {
        switch ($action) {
            case 'move_category':
                self::check_category((int) $payload['id']);
                self::check_category((int) ($payload['from']['parent'] ?? 0));
                self::check_category((int) ($payload['to']['parent'] ?? 0));
                return;
            case 'move_course':
                self::check_course((int) $payload['id'], 'moodle/course:changecategory');
                self::check_category((int) ($payload['from']['category'] ?? 0));
                self::check_category((int) ($payload['to']['category'] ?? 0));
                return;
            case 'sort':
                foreach ($payload['categories'] ?? [] as $entry) {
                    self::check_category((int) $entry['parentid']);
                }
                foreach ($payload['courses'] ?? [] as $entry) {
                    self::check_category((int) $entry['categoryid']);
                }
                return;
            case 'rename':
                foreach ($payload['items'] ?? [] as $item) {
                    if (($item['type'] ?? '') === 'category') {
                        self::check_category((int) $item['id']);
                    } else {
                        self::check_course((int) $item['id'], 'moodle/course:update');
                    }
                }
                return;
            case 'visibility':
                foreach ($payload['items'] ?? [] as $item) {
                    self::check_course((int) $item['id'], 'moodle/course:visibility');
                }
                return;
            case 'merge':
            case 'dedupe':
                self::check_merge($payload);
                return;
            case 'delete':
                self::check_delete($payload);
                return;
            case 'visibilitytree':
                self::check_visibilitytree($payload);
                return;
        }
    }

    /**
     * Native capabilities of undoing «Ocultar todo» / «Mostrar todo», as for the operation: moodle/category:manage on
     * every category and moodle/course:visibility on every course it changed (contexts loaded in bulk).
     *
     * @param array $payload
     */
    private static function check_visibilitytree(array $payload): void {
        foreach ($payload['categories'] ?? [] as $item) {
            self::check_category((int) $item['id']);
        }
        $courses = [];
        foreach ($payload['courses'] ?? [] as $item) {
            $courses[] = (int) $item['id'];
        }
        mover::check_courses($courses, 'moodle/course:visibility');
    }

    /**
     * Native capabilities of undoing a deletion: creating each category needs moodle/category:manage on its
     * parent, as in the core. A parent deleted in the same batch is created by the undo itself, under a parent
     * that is checked; the top level is checked in the system context.
     *
     * @param array $payload
     */
    private static function check_delete(array $payload): void {
        $batch = [];
        foreach ($payload['items'] ?? [] as $item) {
            $batch[(int) $item['id']] = true;
        }
        foreach ($payload['items'] ?? [] as $item) {
            $parent = (int) $item['parent'];
            if (!isset($batch[$parent])) {
                self::check_category($parent);
            }
        }
    }

    /**
     * Native capabilities of undoing a merge: moving each course back, and deleting the created categories.
     *
     * Deleting a category needs moodle/category:manage on its parent; the parent of a created category is
     * the target, an existing category or another created one.
     *
     * @param array $payload
     */
    private static function check_merge(array $payload): void {
        global $DB;
        $categories = [];
        foreach ($payload['items'] ?? [] as $item) {
            self::check_course((int) $item['id'], 'moodle/course:changecategory');
            $categories[(int) $item['fromcategory']] = true;
            $categories[(int) $item['tocategory']] = true;
        }
        foreach ($payload['created'] ?? [] as $id) {
            $parent = $DB->get_field('course_categories', 'parent', ['id' => (int) $id]);
            if ($parent !== false) {
                $categories[(int) $parent] = true;
            }
        }
        foreach (array_keys($categories) as $id) {
            self::check_category($id);
        }
    }

    /**
     * Checks a category when it exists (0 is the top level, in the system context).
     *
     * @param int $id
     */
    private static function check_category(int $id): void {
        global $DB;
        if ($id === 0 || $DB->record_exists('course_categories', ['id' => $id])) {
            mover::check_category($id);
        }
    }

    /**
     * Checks a course when it exists.
     *
     * @param int $id
     * @param string $cap
     */
    private static function check_course(int $id, string $cap): void {
        global $DB;
        if ($id !== (int) SITEID && $DB->record_exists('course', ['id' => $id])) {
            mover::check_course($id, $cap);
        }
    }

    /**
     * Returns: whether it was undone and, when not, the conflicts.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether it was undone'),
            'conflicts' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'What changed since the operation'),
                'Conflicts; empty when it was undone'
            ),
        ]);
    }
}
