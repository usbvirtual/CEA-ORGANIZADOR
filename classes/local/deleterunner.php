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
 * Deletes empty categories and records them so the undo can create them again.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Previews and deletes a batch of categories, keeping every one that is not empty.
 *
 * Empty is what mover::category_content() says. The deepest categories go first, so a selected parent whose
 * only subcategories are selected and empty is deleted too. A category that is not empty, that no longer exists
 * or that the user cannot delete is skipped with a reason, never deleted.
 *
 * The permission follows core_course_category::can_delete(): moodle/category:manage on the category and on its
 * parent (the system context for the top level).
 *
 * Undo (journal, action 'delete') creates the categories again, with new ids.
 */
class deleterunner {
    /** @var int Maximum number of categories in one call. */
    public const MAX_ITEMS = 500;

    /** @var string[] Reasons a category is skipped. */
    public const REASONS = ['hascourses', 'haschildren', 'hascontent', 'nopermission', 'notfound'];

    /**
     * What a deletion would do now. It writes nothing.
     *
     * @param int[] $ids
     * @return array ['delete' => [['id' => int, 'path' => string]], 'skipped' => [['id' => int, 'name' => string,
     *      'path' => string, 'reason' => string]]]; names are raw, paths formatted. Delete is deepest first.
     */
    public static function plan(array $ids): array {
        $ids = self::clean($ids);
        $cats = self::categories();
        [$order, $skipped] = self::candidates($ids, $cats);
        $paths = [];
        $gone = [];
        $delete = [];
        foreach ($order as $id) {
            $reason = mover::category_content($id, $gone);
            if ($reason === '') {
                $gone[] = $id;
                $delete[] = ['id' => $id, 'path' => cloneplan::path_of($id, $cats, $paths)];
            } else {
                $skipped[] = self::skip($id, $cats, $reason);
            }
        }
        foreach ($skipped as &$row) {
            $row['path'] = isset($cats[$row['id']]) ? cloneplan::path_of($row['id'], $cats, $paths) : '';
        }
        unset($row);
        return ['delete' => $delete, 'skipped' => $skipped];
    }

    /**
     * Deletes the empty categories of a batch in one transaction and records them, all or nothing.
     *
     * The caller holds the write lock (guard::lock()). Each category is checked again right before it is
     * deleted, once its subcategories of the batch are gone.
     *
     * @param int[] $ids
     * @return array ['status' => 'ok', 'logid' => int, 'deleted' => int, 'skipped' => [['id' => int,
     *      'name' => string, 'reason' => string]]]; logid is 0 when nothing was deleted. Names are raw.
     */
    public static function run(array $ids): array {
        global $DB;
        // Up to MAX_ITEMS deletions, each with its events and cache purges.
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $ids = self::clean($ids);
        $cats = self::categories();
        [$order, $skipped] = self::candidates($ids, $cats);
        $items = [];
        $logid = 0;
        if ($order) {
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($order as $id) {
                    // Read before the deletion: what the undo needs to create it again.
                    $item = self::snapshot($id);
                    if (mover::delete_empty_category($id)) {
                        $items[] = $item;
                        continue;
                    }
                    // Something changed since it was read (by another tool: this one holds the lock).
                    $reason = 'notfound';
                    if ($DB->record_exists('course_categories', ['id' => $id])) {
                        $reason = mover::category_content($id) ?: 'hascontent';
                    }
                    $skipped[] = self::skip($id, $cats, $reason);
                }
                if ($items) {
                    $logid = static::record_delete(['items' => $items]);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                guard::rollback($transaction, $e);
            }
        }
        return ['status' => 'ok', 'logid' => $logid, 'deleted' => count($items), 'skipped' => $skipped];
    }

    /**
     * Unique positive ids, at most MAX_ITEMS.
     *
     * @param int[] $ids
     * @return int[]
     * @throws \moodle_exception error_toomany beyond the limit.
     */
    private static function clean(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > self::MAX_ITEMS) {
            throw new \moodle_exception('error_toomany', 'tool_ceaorganizador');
        }
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new \invalid_parameter_exception('Invalid category id: ' . $id);
            }
        }
        return $ids;
    }

    /**
     * Every category, for the paths and the depths.
     *
     * @return array Map id => ['id' => int, 'parent' => int, 'name' => string, 'depth' => int].
     */
    private static function categories(): array {
        global $DB;
        $cats = [];
        foreach ($DB->get_records('course_categories', null, 'sortorder ASC, id ASC', 'id, parent, name, path') as $record) {
            $cats[(int) $record->id] = [
                'id' => (int) $record->id,
                'parent' => (int) $record->parent,
                'name' => $record->name,
                'depth' => substr_count((string) $record->path, '/'),
            ];
        }
        return $cats;
    }

    /**
     * The categories that exist and may be deleted, deepest first, and the ones skipped for that.
     *
     * @param int[] $ids
     * @param array $cats See categories().
     * @return array [int[] ids deepest first, skipped rows (notfound, nopermission)].
     */
    private static function candidates(array $ids, array $cats): array {
        $order = [];
        $skipped = [];
        foreach ($ids as $id) {
            if (!isset($cats[$id])) {
                $skipped[] = self::skip($id, $cats, 'notfound');
            } else if (!self::can_delete($id, $cats[$id]['parent'])) {
                $skipped[] = self::skip($id, $cats, 'nopermission');
            } else {
                $order[] = $id;
            }
        }
        usort($order, static function (int $a, int $b) use ($cats): int {
            return [$cats[$b]['depth'], $a] <=> [$cats[$a]['depth'], $b];
        });
        return [$order, $skipped];
    }

    /**
     * Whether the user may delete a category, as core_course_category::can_delete() decides.
     *
     * @param int $id
     * @param int $parent 0 for the top level.
     * @return bool
     */
    private static function can_delete(int $id, int $parent): bool {
        $parentcontext = $parent === 0 ? \context_system::instance() : \context_coursecat::instance($parent);
        return has_capability('moodle/category:manage', \context_coursecat::instance($id))
            && has_capability('moodle/category:manage', $parentcontext);
    }

    /**
     * A skipped row.
     *
     * @param int $id
     * @param array $cats See categories().
     * @param string $reason One of self::REASONS.
     * @return array ['id' => int, 'name' => string (raw, '' when missing), 'reason' => string]
     */
    private static function skip(int $id, array $cats, string $reason): array {
        return ['id' => $id, 'name' => isset($cats[$id]) ? (string) $cats[$id]['name'] : '', 'reason' => $reason];
    }

    /**
     * What the undo needs to create a category again, read fresh from the database.
     *
     * @param int $id
     * @return array Item of the 'delete' journal payload.
     */
    private static function snapshot(int $id): array {
        global $DB;
        $fields = 'id, name, idnumber, description, descriptionformat, parent, visible, visibleold, theme';
        $record = $DB->get_record('course_categories', ['id' => $id], $fields, MUST_EXIST);
        $parent = (int) $record->parent;
        // Its place among its siblings: the one right before it, or 0 when it is first.
        $siblings = tree::sibling_categories($parent);
        $index = array_search($id, $siblings, true);
        $item = [
            'id' => $id,
            'name' => (string) $record->name,
            'idnumber' => (string) $record->idnumber,
            'description' => (string) $record->description,
            'descriptionformat' => (int) $record->descriptionformat,
            'parent' => $parent,
            'afterid' => $index ? $siblings[$index - 1] : 0,
            'visible' => (int) $record->visible,
            'visibleold' => (int) $record->visibleold,
        ];
        if ((string) $record->theme !== '') {
            $item['theme'] = (string) $record->theme;
        }
        return $item;
    }

    /**
     * Records the deletion in the journal. Runs inside the transaction; protected so the tests can make it fail.
     *
     * @param array $payload ['items' => [...]], in deletion order.
     * @return int Journal id.
     */
    protected static function record_delete(array $payload): int {
        $names = array_column($payload['items'], 'name');
        $list = journal::list_summary($names, journal::summary_room('summary_delete'));
        return journal::record('delete', get_string('summary_delete', 'tool_ceaorganizador', $list), $payload);
    }
}
