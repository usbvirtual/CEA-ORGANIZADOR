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
 * Web service: apply an automatic sort.
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
use tool_ceaorganizador\local\sortplan;

/**
 * Rebuilds the plan, and applies it only when it is still the one the preview showed.
 */
class sort_apply extends external_api {
    /**
     * Parameters: the same as sort_preview, plus its hash.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(sort_preview::plan_parameters() + [
            'hash' => new external_value(PARAM_ALPHANUM, 'Hash returned by sort_preview'),
        ]);
    }

    /**
     * Applies the plan in one transaction and records one journal entry.
     *
     * @param int $categoryid
     * @param string $scope
     * @param bool $recursive
     * @param string $hash
     * @return array ['status' => 'ok'|'stale', 'logid' => int]; logid is 0 when nothing was recorded.
     */
    public static function execute(int $categoryid, string $scope, bool $recursive, string $hash): array {
        global $DB;
        // A recursive sort of a large tree can take a while.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'scope' => $scope,
            'recursive' => $recursive,
            'hash' => $hash,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/ceaorganizador:manage', $context);
        sort_preview::validate_scope($params['scope']);
        $categoryid = max(0, $params['categoryid']);

        $lock = guard::lock();
        try {
            $plan = sortplan::build($categoryid, $params['scope'], $params['recursive']);
            if (!hash_equals($plan['hash'], $params['hash'])) {
                return ['status' => 'stale', 'logid' => 0];
            }
            if (!$plan['changes']) {
                return ['status' => 'ok', 'logid' => 0];
            }
            foreach ($plan['changes'] as $change) {
                mover::check_category($change['parentid']);
            }

            $payload = ['categories' => [], 'courses' => []];
            foreach ($plan['changes'] as $change) {
                $before = array_column($change['before'], 'id');
                $after = array_column($change['after'], 'id');
                if ($change['kind'] === 'categories') {
                    $payload['categories'][] = ['parentid' => $change['parentid'], 'before' => $before, 'after' => $after];
                } else {
                    $payload['courses'][] = ['categoryid' => $change['parentid'], 'before' => $before, 'after' => $after];
                }
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                // Courses first, then categories, and one site-wide cleanup at the end (see mover).
                foreach ($payload['courses'] as $entry) {
                    mover::apply_course_order($entry['categoryid'], $entry['after'], false);
                }
                foreach ($payload['categories'] as $entry) {
                    mover::apply_category_order($entry['parentid'], $entry['after'], false);
                }
                \core_course_category::resort_categories_cleanup(true);
                $logid = journal::record('sort', self::summary($categoryid), $payload);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                guard::rollback($transaction, $e);
            }
        } finally {
            $lock->release();
        }
        return ['status' => 'ok', 'logid' => $logid];
    }

    /**
     * Summary for the history, with the raw name; the history formats it on output.
     *
     * @param int $categoryid
     * @return string
     */
    private static function summary(int $categoryid): string {
        global $DB;
        $name = $categoryid === 0
            ? get_string('top')
            : (string) $DB->get_field('course_categories', 'name', ['id' => $categoryid], MUST_EXIST);
        return get_string('summary_sort', 'tool_ceaorganizador', $name);
    }

    /**
     * Returns: the status and the journal id.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, or stale when the order changed since the preview'),
            'logid' => new external_value(PARAM_INT, 'Journal id, for the undo; 0 when nothing was recorded'),
        ]);
    }
}
