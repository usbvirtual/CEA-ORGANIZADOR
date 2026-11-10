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
 * Write lock and rollback shared by the web services that write.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * One writer at a time: the web services that write hold this lock.
 */
class guard {
    /** @var int Seconds to wait for the lock. */
    public const TIMEOUT = 10;

    /**
     * Takes the write lock of the plugin; the caller releases it in a finally block.
     *
     * @return \core\lock\lock
     * @throws \moodle_exception error_busy when another writer holds it.
     */
    public static function lock(): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('tool_ceaorganizador');
        $lock = $factory->get_lock('write', self::TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('error_busy', 'tool_ceaorganizador');
        }
        return $lock;
    }

    /**
     * Purges the course and category caches, then rolls back and rethrows, as the mover does.
     *
     * @param \moodle_transaction $transaction
     * @param \Throwable $e
     * @throws \Throwable Always, the given one.
     */
    public static function rollback(\moodle_transaction $transaction, \Throwable $e): void {
        \cache_helper::purge_by_event('changesincoursecat');
        \cache_helper::purge_by_event('changesincourse');
        \context_helper::reset_caches();
        $transaction->rollback($e);
    }
}
