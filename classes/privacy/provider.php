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
 * Privacy provider.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: the journal stores who made and who undid each operation.
 *
 * Deleting anonymises the user references (0) and keeps the rows, which describe
 * changes to the site structure, not the user.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Journal table. */
    private const TABLE = 'tool_ceaorganizador_log';

    /**
     * Describes the stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, [
            'userid' => 'privacy:metadata:log:userid',
            'action' => 'privacy:metadata:log:action',
            'payload' => 'privacy:metadata:log:payload',
            'timecreated' => 'privacy:metadata:log:timecreated',
            'undoneby' => 'privacy:metadata:log:undoneby',
        ], 'privacy:metadata:log');
        return $collection;
    }

    /**
     * Contexts with data for a user: only the system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $exists = $DB->record_exists_select(
            self::TABLE,
            'userid = :userid OR undoneby = :undoneby',
            ['userid' => $userid, 'undoneby' => $userid]
        );
        if ($exists) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_ceaorganizador_log} WHERE userid > 0', []);
        $userlist->add_from_sql('undoneby', 'SELECT undoneby FROM {tool_ceaorganizador_log} WHERE undoneby > 0', []);
    }

    /**
     * Exports the operations the user made or undid.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $records = $DB->get_records_select(
            self::TABLE,
            'userid = :userid OR undoneby = :undoneby',
            ['userid' => $userid, 'undoneby' => $userid],
            'timecreated ASC, id ASC'
        );
        if (!$records) {
            return;
        }
        $operations = [];
        foreach ($records as $record) {
            $operations[] = [
                'action' => $record->action,
                'summary' => $record->summary,
                'payload' => json_decode($record->payload, true),
                'timecreated' => transform::datetime($record->timecreated),
                'madebyyou' => transform::yesno((int) $record->userid === $userid),
                'undonebyyou' => transform::yesno((int) $record->undoneby === $userid),
                'timeundone' => $record->timeundone ? transform::datetime($record->timeundone) : '',
            ];
        }
        writer::with_context($context)->export_data(
            [get_string('pluginname', 'tool_ceaorganizador')],
            (object) ['operations' => $operations]
        );
    }

    /**
     * Anonymises every user reference in the system context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $DB->set_field_select(self::TABLE, 'userid', 0, 'userid <> 0');
        $DB->set_field_select(self::TABLE, 'undoneby', 0, 'undoneby <> 0');
    }

    /**
     * Anonymises the references to one user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = (int) $contextlist->get_user()->id;
        $DB->set_field(self::TABLE, 'userid', 0, ['userid' => $userid]);
        $DB->set_field(self::TABLE, 'undoneby', 0, ['undoneby' => $userid]);
    }

    /**
     * Anonymises the references to several users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select(self::TABLE, 'userid', 0, "userid $insql", $params);
        $DB->set_field_select(self::TABLE, 'undoneby', 0, "undoneby $insql", $params);
    }
}
