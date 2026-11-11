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
 * Runs a cloning batch: creates the missing categories, records the batch and queues the copies.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Applies a cloning preview that is still current.
 *
 * Categories and the journal row are written in one transaction. The copies are queued after
 * that commit, one by one, each in its own transaction with the update of its journal entry:
 * \copy_helper::create_copy() writes only to the database (backup_controllers, the provisional
 * course, task_adhoc), so a copy that fails rolls back whole, is marked 'queuefailed' and the rest go on.
 *
 * Do not call it inside another transaction: a failed row would roll back the outer one too.
 */
class clonerunner {
    /** @var int Most values in one IN clause. */
    private const CHUNK = 1000;

    /**
     * Runs a batch under the write lock.
     *
     * @param array $items As cloneplan::build().
     * @param int $periodid
     * @param string $label
     * @param int $startdate
     * @param int $enddate
     * @param array $edits Map courseid => ['newfullname' => string, 'newshortname' => string], raw names. The rows
     *      are authoritative: a plan row whose course is not here is not cloned ('notselected').
     * @param string $hash Hash of the preview the user saw.
     * @param string $container Folder under the period, as cloneplan::build(); it is created like any other missing level.
     * @return array ['status' => 'ok'|'stale', 'batchid' => int, 'queued' => int, 'skipped' => int]; batchid is the
     *      journal id, 0 when nothing was recorded.
     * @throws \required_capability_exception When a destination cannot receive courses or categories; nothing is written.
     */
    public static function run(
        array $items,
        int $periodid,
        string $label,
        int $startdate,
        int $enddate,
        array $edits,
        string $hash,
        string $container = ''
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        // Up to cloneplan::MAX_ROWS copies are created and queued in one request.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $lock = guard::lock();
        try {
            $plan = cloneplan::build($items, $periodid, $label, $startdate, $enddate, $container);
            if (!hash_equals($plan['hash'], $hash)) {
                return ['status' => 'stale', 'batchid' => 0, 'queued' => 0, 'skipped' => 0];
            }
            $rows = self::apply_edits($plan['rows'], $edits);
            $rows = self::targets($rows, $periodid, cloneplan::container($container));
            $todo = array_values(array_filter($rows, static function (array $row): bool {
                return $row['error'] === null;
            }));
            $skipped = count($rows) - count($todo);
            if (!$todo) {
                return ['status' => 'ok', 'batchid' => 0, 'queued' => 0, 'skipped' => $skipped];
            }
            self::check_destinations($todo);
            // The copy API must accept our fields (5.1 plugins can require more) before anything is created.
            \copy_helper::process_formdata(self::formdata([
                'source' => $todo[0]['courseid'],
                'newfullname' => $todo[0]['newfullname'],
                'newshortname' => $todo[0]['newshortname'],
                'destcategoryid' => $todo[0]['anchor'],
            ], $startdate, $enddate));

            $transaction = $DB->start_delegated_transaction();
            try {
                [$todo, $created] = mover::create_categories($todo);
                $payload = [
                    'periodid' => $periodid,
                    'label' => $label,
                    'startdate' => $startdate,
                    'enddate' => $enddate,
                    'categories' => $created,
                    'items' => [],
                ];
                foreach ($todo as $row) {
                    $payload['items'][] = [
                        'source' => $row['courseid'],
                        'newfullname' => $row['newfullname'],
                        'newshortname' => $row['newshortname'],
                        'destcategoryid' => $row['destcategoryid'],
                        'backupid' => '',
                        'restoreid' => '',
                        'newcourseid' => 0,
                        'error' => null,
                    ];
                }
                $logid = journal::record('clone', self::summary($label, count($todo)), $payload);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                guard::rollback($transaction, $e);
            }

            // After the commit, still under the lock. Each copy and its journal entry commit together, so an
            // interruption loses nothing that was queued, and a failed copy leaves no provisional course behind.
            $queued = 0;
            foreach (array_keys($payload['items']) as $index) {
                $item = $payload['items'][$index];
                $transaction = $DB->start_delegated_transaction();
                try {
                    $ids = static::queue($item, $startdate, $enddate);
                    $item['backupid'] = $ids['backupid'];
                    $item['restoreid'] = $ids['restoreid'];
                    // The restore controller row stores the new course id in itemid (restore_controller_dbops).
                    $item['newcourseid'] = (int) $DB->get_field(
                        'backup_controllers',
                        'itemid',
                        ['backupid' => $ids['restoreid']],
                        MUST_EXIST
                    );
                    // The ids join the payload only once the row has committed, so a rolled-back attempt leaves none.
                    $candidate = $payload;
                    $candidate['items'][$index] = $item;
                    self::save_payload($logid, $candidate);
                    $transaction->allow_commit();
                    $payload = $candidate;
                    $queued++;
                } catch (\Throwable $e) {
                    try {
                        guard::rollback($transaction, $e);
                    } catch (\Throwable $rethrown) {
                        // Expected: rollback always rethrows. The row is marked and the batch goes on.
                        $reason = \core_text::substr(get_class($e) . ': ' . $e->getMessage(), 0, 255);
                        $payload['items'][$index]['backupid'] = '';
                        $payload['items'][$index]['restoreid'] = '';
                        $payload['items'][$index]['newcourseid'] = 0;
                        $payload['items'][$index]['error'] = 'queuefailed';
                        $payload['items'][$index]['reason'] = $reason;
                        debugging('Course copy of ' . $item['source'] . ' not queued: ' . $reason, DEBUG_DEVELOPER);
                    }
                    self::save_payload($logid, $payload);
                }
            }
            $DB->set_field(journal::TABLE, 'summary', self::summary($label, $queued), ['id' => $logid]);
        } finally {
            $lock->release();
        }
        return [
            'status' => 'ok',
            'batchid' => $logid,
            'queued' => $queued,
            'skipped' => $skipped + count($todo) - $queued,
        ];
    }

    /**
     * Summary of the batch for the history, cut as journal::record() does.
     *
     * @param string $label
     * @param int $count Copies queued.
     * @return string
     */
    private static function summary(string $label, int $count): string {
        $summary = get_string('summary_clone', 'tool_ceaorganizador', (object) ['label' => $label, 'count' => $count]);
        return \core_text::substr($summary, 0, 255);
    }

    /**
     * Writes the payload of the batch, encoded as journal::record() does.
     *
     * @param int $logid
     * @param array $payload
     */
    private static function save_payload(int $logid, array $payload): void {
        global $DB;
        $DB->set_field(
            journal::TABLE,
            'payload',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ['id' => $logid]
        );
    }

    /**
     * Applies the edited names to the rows without error, then checks every name again.
     *
     * The rows sent are authoritative: a row without error that the user did not send is not cloned.
     *
     * @param array $rows Plan rows.
     * @param array $edits
     * @return array Rows; those that fail now carry 'notselected', 'empty', 'toolong', 'shortnametaken',
     *      'alreadycloned' or 'shortnameduplicate'.
     */
    private static function apply_edits(array $rows, array $edits): array {
        foreach ($rows as &$row) {
            if ($row['error'] !== null) {
                continue;
            }
            if (!isset($edits[$row['courseid']])) {
                // Left out by the user (for example a row with a blank name): not cloned, and its short name
                // does not count as a duplicate of the rows that are.
                $row['error'] = 'notselected';
                continue;
            }
            $edit = $edits[$row['courseid']];
            if (isset($edit['newfullname'])) {
                $row['newfullname'] = trim((string) $edit['newfullname']);
            }
            if (isset($edit['newshortname'])) {
                $row['newshortname'] = trim((string) $edit['newshortname']);
            }
            if ($row['newfullname'] === '' || $row['newshortname'] === '') {
                $row['error'] = 'empty';
            } else if (
                \core_text::strlen($row['newfullname']) > renamer::MAXLEN
                || \core_text::strlen($row['newshortname']) > renamer::MAXLEN
            ) {
                $row['error'] = 'toolong';
            }
        }
        unset($row);

        // Short names: against the site and within the rows that will be cloned.
        $names = [];
        $count = [];
        foreach ($rows as $row) {
            if ($row['error'] === null) {
                $names[$row['newshortname']] = $row['newshortname'];
                $key = \core_text::strtolower($row['newshortname']);
                $count[$key] = ($count[$key] ?? 0) + 1;
            }
        }
        // Copies still in flight count as taken, as in the preview.
        $existing = cloneplan::taken_shortnames(array_values($names));
        foreach ($rows as &$row) {
            if ($row['error'] !== null) {
                continue;
            }
            $key = \core_text::strtolower($row['newshortname']);
            if (isset($existing[$key])) {
                $row['error'] = ($row['destcategoryid'] > 0 && in_array($row['destcategoryid'], $existing[$key], true))
                    ? 'alreadycloned' : 'shortnametaken';
            } else if ($count[$key] > 1) {
                $row['error'] = 'shortnameduplicate';
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Adds to each row the deepest existing destination category and the raw names still missing below it.
     *
     * @param array $rows
     * @param int $periodid
     * @param string $container Checked folder under the period, or ''.
     * @return array Rows with 'anchor' (int) and 'missing' (string[]).
     */
    private static function targets(array $rows, int $periodid, string $container): array {
        global $DB;
        $records = $DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name');
        $cats = [];
        foreach ($records as $record) {
            $cats[(int) $record->id] = ['id' => (int) $record->id, 'parent' => (int) $record->parent, 'name' => $record->name];
        }
        $coursecats = [];
        foreach (array_chunk(array_column($rows, 'courseid'), self::CHUNK) as $chunk) {
            foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id, category') as $course) {
                $coursecats[(int) $course->id] = (int) $course->category;
            }
        }
        $patterns = sorter::patterns();
        foreach ($rows as &$row) {
            // The same path as cloneplan::row().
            $mirror = cloner::mirror($cats, $coursecats[$row['courseid']] ?? 0, $patterns);
            $resolved = cloner::resolve($cats, $periodid, cloner::destination($mirror['relative'], $container));
            $row['anchor'] = $resolved['existing'];
            $row['missing'] = $resolved['missing'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Requires the capabilities on every destination. It throws, so the whole batch stops before any write.
     *
     * New categories inherit the permissions of the existing category they hang from, so that one is checked.
     * The copy restores into a course of the destination, hence moodle/restore:restorecourse there too:
     * without it the copy task would fail later and leave an empty course.
     *
     * @param array $rows Rows to clone, with 'anchor' and 'missing'.
     */
    private static function check_destinations(array $rows): void {
        $checked = [];
        foreach ($rows as $row) {
            $anchor = (int) $row['anchor'];
            if (!isset($checked[$anchor])) {
                $context = \context_coursecat::instance($anchor);
                require_capability('moodle/course:create', $context);
                require_capability('moodle/restore:restorecourse', $context);
                $checked[$anchor] = false;
            }
            if ($row['missing'] && !$checked[$anchor]) {
                mover::check_category($anchor);
                $checked[$anchor] = true;
            }
        }
    }

    /**
     * The form data of one copy: content only, hidden, no users and no idnumber.
     *
     * A start date of 0 keeps the dates of the seed course.
     *
     * Every field that copy_helper::process_formdata() requires; no role_* fields, so no roles are kept.
     *
     * @param array $item Payload item.
     * @param int $startdate
     * @param int $enddate
     * @return \stdClass
     */
    private static function formdata(array $item, int $startdate, int $enddate): \stdClass {
        global $DB;
        if ($startdate === 0) {
            // Without dates the copy keeps those of its seed course.
            $seed = $DB->get_record('course', ['id' => (int) $item['source']], 'startdate, enddate', MUST_EXIST);
            $startdate = (int) $seed->startdate;
            $enddate = (int) $seed->enddate;
        }
        return (object) [
            'courseid' => (int) $item['source'],
            'fullname' => $item['newfullname'],
            'shortname' => $item['newshortname'],
            'category' => (int) $item['destcategoryid'],
            'visible' => 0,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'idnumber' => '',
            'userdata' => 0,
        ];
    }

    /**
     * Queues one course copy. Runs inside the transaction of its row; protected so the tests can make it fail.
     *
     * @param array $item Payload item.
     * @param int $startdate
     * @param int $enddate
     * @return array ['backupid' => string, 'restoreid' => string]
     */
    protected static function queue(array $item, int $startdate, int $enddate): array {
        $copydata = \copy_helper::process_formdata(self::formdata($item, $startdate, $enddate));
        return \copy_helper::create_copy($copydata);
    }
}
