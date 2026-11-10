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
 * Admin pages and settings.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Right below "Manage courses and categories" (coursemgmt): insert before the page that
    // follows it in core (addcategory, same order from 4.2 to 5.3). If it is missing, append.
    $ADMIN->add(
        'courses',
        new admin_externalpage(
            'tool_ceaorganizador',
            get_string('pluginname', 'tool_ceaorganizador'),
            new moodle_url('/admin/tool/ceaorganizador/index.php'),
            'tool/ceaorganizador:manage'
        ),
        $ADMIN->locate('addcategory') ? 'addcategory' : null
    );

    $settings = new admin_settingpage(
        'tool_ceaorganizador_settings',
        get_string('settings', 'tool_ceaorganizador')
    );
    foreach (['semilla', 'facultad'] as $name) {
        $settings->add(new admin_setting_configtextarea(
            'tool_ceaorganizador/patterns_' . $name,
            get_string('patterns_' . $name, 'tool_ceaorganizador'),
            get_string('patterns_' . $name . '_desc', 'tool_ceaorganizador'),
            \tool_ceaorganizador\local\sorter::DEFAULT_PATTERNS[$name]
        ));
    }
    $ADMIN->add('courses', $settings);
}
