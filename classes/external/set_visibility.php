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
 * Web service: show or hide courses.
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
 * Shows or hides a batch of courses in one transaction, with one journal entry.
 *
 * Every course needs moodle/course:visibility, checked under the lock before any write: one
 * missing permission refuses the whole batch. Courses that already have the requested
 * visibility, or that no longer exist, are skipped and left out of the journal.
 */
class set_visibility extends external_api {
    /**
     * @var int Maximum number of courses in one call. Every course goes through update_course(), which purges caches
     *      of the whole site: small batches keep each request short on a busy site.
     */
    public const MAXITEMS = 100;

    /**
     * Parameters: the courses and the visibility to give them.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'Course id')),
            'visible' => new external_value(PARAM_BOOL, 'True to show the courses, false to hide them'),
        ]);
    }

    /**
     * Shows or hides the courses under the write lock.
     *
     * @param int[] $courseids
     * @param bool $visible
     * @return array ['changed' => int, 'skipped' => int, 'logid' => int]; logid is 0 when nothing changed.
     */
    public static function execute(array $courseids, bool $visible): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseids' => $courseids,
            'visible' => $visible,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        if (count($params['courseids']) > self::MAXITEMS) {
            throw new \moodle_exception('error_toomany', 'tool_ceaorganizador', '', self::MAXITEMS);
        }
        $ids = array_values(array_unique(array_map('intval', $params['courseids'])));
        foreach ($ids as $id) {
            if ($id <= 0 || $id === (int) SITEID) {
                throw new \invalid_parameter_exception('Invalid course id: ' . $id);
            }
        }
        $visible = (bool) $params['visible'];
        // Up to 100 course updates, each with its event and cache purge.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $lock = guard::lock();
        try {
            $courses = $ids ? $DB->get_records_list('course', 'id', $ids, '', 'id, fullname, visible') : [];
            // Every permission first: a refusal leaves everything as it was.
            foreach ($courses as $course) {
                mover::check_course((int) $course->id, 'moodle/course:visibility');
            }
            $change = [];
            foreach ($ids as $id) {
                if (isset($courses[$id]) && (bool) $courses[$id]->visible !== $visible) {
                    $change[] = $courses[$id];
                }
            }
            $logid = $change ? self::apply($change, $visible) : 0;
        } finally {
            $lock->release();
        }
        return [
            'changed' => count($change),
            'skipped' => count($ids) - count($change),
            'logid' => $logid,
        ];
    }

    /**
     * Writes the changes in one transaction and records one journal entry with them.
     *
     * @param \stdClass[] $courses Records with id, fullname and visible, in the given order.
     * @param bool $visible
     * @return int Journal id.
     */
    private static function apply(array $courses, bool $visible): int {
        global $DB;
        $items = [];
        $names = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($courses as $course) {
                mover::set_course_visibility((int) $course->id, $visible);
                $items[] = [
                    'id' => (int) $course->id,
                    'name' => $course->fullname,
                    'before' => (int) (bool) $course->visible,
                    'after' => (int) $visible,
                ];
                $names[] = $course->fullname;
            }
            if ($visible) {
                $list = journal::list_summary($names, journal::summary_room('summary_show'));
                $summary = get_string('summary_show', 'tool_ceaorganizador', $list);
            } else {
                $list = journal::list_summary($names, journal::summary_room('summary_hide'));
                $summary = get_string('summary_hide', 'tool_ceaorganizador', $list);
            }
            $logid = journal::record('visibility', $summary, ['items' => $items]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }
        return $logid;
    }

    /**
     * Returns: how many courses changed, how many were skipped, and the journal id.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'changed' => new external_value(PARAM_INT, 'Courses whose visibility changed'),
            'skipped' => new external_value(PARAM_INT, 'Courses that already had it or no longer exist'),
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo; 0 when nothing changed'),
        ]);
    }
}
