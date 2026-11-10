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

namespace tool_ceaorganizador\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_ceaorganizador\local\journal;

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Tests for the privacy provider.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Records an operation as the given user.
     *
     * @param \stdClass $user
     * @param string $summary
     * @return int Log id.
     */
    private function record_as(\stdClass $user, string $summary): int {
        $this->setUser($user);
        return journal::record('sort', $summary, ['categories' => [], 'courses' => []]);
    }

    /**
     * Only the system context holds data, and only for users with rows.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->record_as($user, 'Operación');

        $contextids = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
        $this->assertEquals([\context_system::instance()->id], array_values($contextids));
        $this->assertEmpty(provider::get_contexts_for_userid((int) $other->id)->get_contextids());
    }

    /**
     * The users who made or undid operations are listed in the system context.
     */
    public function test_get_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $maker = $this->getDataGenerator()->create_user();
        $undoer = $this->getDataGenerator()->create_user();
        $logid = $this->record_as($maker, 'Operación');
        $DB->set_field('tool_ceaorganizador_log', 'undoneby', $undoer->id, ['id' => $logid]);

        $userlist = new userlist(\context_system::instance(), 'tool_ceaorganizador');
        provider::get_users_in_context($userlist);

        $userids = array_map('intval', $userlist->get_userids());
        sort($userids);
        $expected = [(int) $maker->id, (int) $undoer->id];
        sort($expected);
        $this->assertSame($expected, $userids);
    }

    /**
     * The export holds the operations of the user under the plugin name.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->record_as($user, 'Mía');
        $this->record_as($other, 'Ajena');
        $context = \context_system::instance();

        $this->export_context_data_for_user((int) $user->id, $context, 'tool_ceaorganizador');

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'tool_ceaorganizador')]);
        $this->assertCount(1, $data->operations);
        $this->assertSame('Mía', $data->operations[0]['summary']);
        $this->assertSame('sort', $data->operations[0]['action']);
    }

    /**
     * Deleting for a user sets the references to 0 and keeps the rows.
     */
    public function test_delete_data_for_user_anonymises(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $mine = $this->record_as($user, 'Mía');
        $theirs = $this->record_as($other, 'Ajena');
        // The user undid the operation of the other one.
        $DB->set_field('tool_ceaorganizador_log', 'undoneby', $user->id, ['id' => $theirs]);
        $DB->set_field('tool_ceaorganizador_log', 'timeundone', time(), ['id' => $theirs]);

        $contextlist = new approved_contextlist($user, 'tool_ceaorganizador', [\context_system::instance()->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(2, $DB->count_records('tool_ceaorganizador_log'));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'userid', ['id' => $mine]));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'undoneby', ['id' => $theirs]));
        $this->assertSame((int) $other->id, (int) $DB->get_field('tool_ceaorganizador_log', 'userid', ['id' => $theirs]));
        // Still undone: the flag comes from the time, not from the user.
        $undone = array_column(journal::history(0)['rows'], 'undone', 'id');
        $this->assertTrue($undone[$theirs]);
        $this->assertFalse($undone[$mine]);
    }

    /**
     * Deleting for a list of users anonymises only those users.
     */
    public function test_delete_data_for_users_anonymises(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $mine = $this->record_as($user, 'Mía');
        $theirs = $this->record_as($other, 'Ajena');
        $context = \context_system::instance();

        provider::delete_data_for_users(new approved_userlist($context, 'tool_ceaorganizador', [(int) $user->id]));

        $this->assertSame(2, $DB->count_records('tool_ceaorganizador_log'));
        $this->assertSame(0, (int) $DB->get_field('tool_ceaorganizador_log', 'userid', ['id' => $mine]));
        $this->assertSame((int) $other->id, (int) $DB->get_field('tool_ceaorganizador_log', 'userid', ['id' => $theirs]));
    }

    /**
     * Deleting for all users in the system context anonymises every row and keeps them.
     */
    public function test_delete_data_for_all_users_in_context_anonymises(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->record_as($user, 'Mía');
        $this->record_as($other, 'Ajena');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(2, $DB->count_records('tool_ceaorganizador_log'));
        $this->assertSame(0, $DB->count_records_select('tool_ceaorganizador_log', 'userid <> 0 OR undoneby <> 0'));
    }
}
