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

namespace tool_ceaorganizador\local;

// Cobertura declarada dos veces: el atributo para PHPUnit 11 (Moodle 5.x) y la
// anotación @covers para PHPUnit 9.6 (Moodle 4.x) y el verificador de código.
#[\PHPUnit\Framework\Attributes\CoversClass(backupcheck::class)]
/**
 * Tests for the detection of the Moodle 4.2 backup bug.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ceaorganizador\local\backupcheck
 */
final class backupcheck_test extends \advanced_testcase {
    /**
     * A Moodle with core_text::trim_ctrl_chars() (4.3 and later) is never reported as broken.
     */
    public function test_not_broken_when_core_is_fixed(): void {
        if (!method_exists('\core_text', 'trim_ctrl_chars')) {
            $this->markTestSkipped('Moodle 4.2: core_text::trim_ctrl_chars() does not exist.');
        }
        $this->assertFalse(backupcheck::broken());
    }

    /**
     * On a Moodle without the fix the answer follows the PCRE probe.
     */
    public function test_follows_pcre_without_the_fix(): void {
        if (method_exists('\core_text', 'trim_ctrl_chars')) {
            $this->markTestSkipped('Moodle 4.3 or later: core_text::trim_ctrl_chars() exists.');
        }
        $this->assertSame(@preg_match('/[\x-\x8]/', '') === false, backupcheck::broken());
    }

    /**
     * When the backups are broken, the preview refuses before writing anything.
     */
    public function test_cloneplan_refuses_when_broken(): void {
        if (!backupcheck::broken()) {
            $this->markTestSkipped('The course backups of this Moodle work.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $period = $this->getDataGenerator()->create_category(['name' => '2027-01']);
        try {
            cloneplan::build([], (int) $period->id, '2027-01', 1798761600, 1814486400);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_backupbroken', $e->errorcode);
        }
    }
}
