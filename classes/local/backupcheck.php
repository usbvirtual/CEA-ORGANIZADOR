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
 * Detects a Moodle whose course backups cannot run.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Moodle 4.2 cleans the backup XML with the regex '/[\x-\x8...]/', which modern PCRE2 rejects, so every
 * backup (and every course copy) fails. Moodle 4.3 replaced it with core_text::trim_ctrl_chars().
 */
class backupcheck {
    /**
     * Whether the course backups of this Moodle are broken by that core bug.
     *
     * @return bool True only on a Moodle without core_text::trim_ctrl_chars() whose PCRE rejects the regex.
     */
    public static function broken(): bool {
        if (method_exists('\core_text', 'trim_ctrl_chars')) {
            return false;
        }
        // The same pattern core uses; with a modern PCRE it does not compile and preg_match returns false.
        return @preg_match('/[\x-\x8]/', '') === false;
    }
}
