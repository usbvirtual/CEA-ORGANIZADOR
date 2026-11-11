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
 * Writes a batch of course moves in short transactions, with its journal row.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Shared write step of mergerunner and deduperunner.
 *
 * Up to 1000 moves in one transaction would hold locks on the course table for a long time. Instead:
 * 1. One short transaction creates the missing categories and records the journal row, with no items yet.
 * 2. One transaction per destination category, at most CHUNK courses each, moves the courses and rewrites the
 *    journal row with the items moved so far.
 * A failure rolls back only its own chunk; what was committed before is in the journal row, so it can be undone
 * (journal::undo() undoes the whole row in one transaction). No step leaves moves without a row. When a chunk fails
 * after another was committed, the caller gets a partial_exception with the row and the counts; before that, the
 * failure itself.
 */
abstract class batchrunner {
    /** @var int Most courses moved in one transaction. */
    public const CHUNK = 100;

    /** @var string Journal action of the subclass. */
    protected const ACTION = '';

    /**
     * Creates the categories, records the row and moves the courses, chunk by chunk.
     *
     * The caller holds the write lock and has checked every capability.
     *
     * @param array $payload Payload without 'created' and 'items'.
     * @param array $rows Rows with 'courseid', 'fromid', 'anchor', 'missing' and, optionally, 'hidden'.
     * @return int Journal id.
     * @throws partial_exception When a chunk fails after at least one chunk was committed.
     */
    protected static function write(array $payload, array $rows): int {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            [$rows, $created] = mover::create_categories($rows);
            $payload['created'] = $created;
            $payload['items'] = [];
            $logid = static::record_batch($payload);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            guard::rollback($transaction, $e);
        }

        $bydestination = [];
        foreach ($rows as $row) {
            $bydestination[(int) $row['destcategoryid']][] = $row;
        }
        foreach ($bydestination as $categoryid => $list) {
            foreach (array_chunk($list, self::CHUNK) as $chunk) {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $ids = array_map(static function (array $row): int {
                        return (int) $row['courseid'];
                    }, $chunk);
                    $before = self::visibility($ids);
                    mover::move_courses_into($ids, $categoryid);
                    // Moving into a hidden category hides the course: recorded so undo can show it again.
                    $after = self::visibility($ids);
                    $items = $payload['items'];
                    foreach ($chunk as $row) {
                        $id = (int) $row['courseid'];
                        $items[] = [
                            'id' => $id,
                            'fromcategory' => (int) $row['fromid'],
                            'tocategory' => $categoryid,
                            'fromvisible' => $before[$id],
                            'tovisible' => $after[$id],
                        ];
                    }
                    static::update_batch($logid, ['items' => $items] + $payload);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    try {
                        guard::rollback($transaction, $e);
                    } catch (\Throwable $failure) {
                        // After a committed chunk the batch is half done: the caller gets the row to undo it.
                        if ($payload['items']) {
                            throw new partial_exception($logid, count($payload['items']), count($rows), $failure);
                        }
                        throw $failure;
                    }
                }
                $payload['items'] = $items;
            }
        }
        return $logid;
    }

    /**
     * Records the journal row. Runs inside the first transaction; protected so the tests can make it fail.
     *
     * @param array $payload
     * @return int Journal id.
     */
    protected static function record_batch(array $payload): int {
        return journal::record(static::ACTION, static::summary($payload), $payload);
    }

    /**
     * Rewrites the journal row after a chunk. Runs inside the chunk transaction; protected for the tests.
     *
     * @param int $logid
     * @param array $payload
     */
    protected static function update_batch(int $logid, array $payload): void {
        journal::update($logid, static::summary($payload), $payload);
    }

    /**
     * Summary of the batch for the history.
     *
     * @param array $payload
     * @return string
     */
    abstract protected static function summary(array $payload): string;

    /**
     * Visibility of some courses.
     *
     * @param int[] $ids
     * @return int[] Map id => 0|1.
     */
    protected static function visibility(array $ids): array {
        global $DB;
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id, visible') as $course) {
                $result[(int) $course->id] = (int) $course->visible;
            }
        }
        return $result;
    }
}
