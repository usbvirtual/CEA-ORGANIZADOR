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
 * Upgrade steps.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin database.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_tool_ceaorganizador_upgrade($oldversion) {
    if ($oldversion < 2026111800) {
        // The automatic sort was removed: the faculty patterns only grouped faculties when sorting.
        unset_config('patterns_facultad', 'tool_ceaorganizador');
        upgrade_plugin_savepoint(true, 2026111800, 'tool', 'ceaorganizador');
    }
    if ($oldversion < 2026111801) {
        // The name suggestions were removed: their glossary is not read any more.
        unset_config('glossary', 'tool_ceaorganizador');
        upgrade_plugin_savepoint(true, 2026111801, 'tool', 'ceaorganizador');
    }
    return true;
}
