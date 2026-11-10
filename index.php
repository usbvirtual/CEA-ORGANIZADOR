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
 * Category organiser page.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_ceaorganizador');
require_capability('tool/ceaorganizador:manage', context_system::instance());

$id = 'tool-ceaorganizador-' . uniqid();
$PAGE->requires->js_call_amd('tool_ceaorganizador/organizer', 'init', [['root' => '#' . $id]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('tool_ceaorganizador/page', [
    'id' => $id,
    'nativeurl' => (new moodle_url('/course/management.php'))->out(false),
]);
echo $OUTPUT->footer();
