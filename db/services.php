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
 * Web service functions, for the organizer page (AJAX only).
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'tool_ceaorganizador_get_tree' => [
        'classname' => \tool_ceaorganizador\external\get_tree::class,
        'description' => 'All the categories, with their sort recommendation.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_get_courses' => [
        'classname' => \tool_ceaorganizador\external\get_courses::class,
        'description' => 'One page of courses of a category or of the site, with an optional search.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_move_category' => [
        'classname' => \tool_ceaorganizador\external\move_category::class,
        'description' => 'Moves a category under a parent, after a sibling.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_move_course' => [
        'classname' => \tool_ceaorganizador\external\move_course::class,
        'description' => 'Moves a course into a category, after another course.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_sort_preview' => [
        'classname' => \tool_ceaorganizador\external\sort_preview::class,
        'description' => 'Preview of an automatic sort, with its signature.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_sort_apply' => [
        'classname' => \tool_ceaorganizador\external\sort_apply::class,
        'description' => 'Applies an automatic sort when it still matches its preview.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_rename_preview' => [
        'classname' => \tool_ceaorganizador\external\rename_preview::class,
        'description' => 'Preview of a rename, with the rows that cannot be applied.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_rename_apply' => [
        'classname' => \tool_ceaorganizador\external\rename_apply::class,
        'description' => 'Applies explicit renames where the name did not change since the preview.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_undo' => [
        'classname' => \tool_ceaorganizador\external\undo::class,
        'description' => 'Undoes a recorded operation, or reports what changed since.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_history' => [
        'classname' => \tool_ceaorganizador\external\history::class,
        'description' => 'One page of the history of operations.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_clone_preview' => [
        'classname' => \tool_ceaorganizador\external\clone_preview::class,
        'description' => 'Preview of cloning seed courses into a period, with its signature.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_clone_apply' => [
        'classname' => \tool_ceaorganizador\external\clone_apply::class,
        'description' => 'Queues the copies of a cloning preview that is still current.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_clone_status' => [
        'classname' => \tool_ceaorganizador\external\clone_status::class,
        'description' => 'Progress of the copies of a cloning batch.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
    'tool_ceaorganizador_set_visibility' => [
        'classname' => \tool_ceaorganizador\external\set_visibility::class,
        'description' => 'Shows or hides courses, skipping the ones that already have that visibility.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tool/ceaorganizador:manage',
    ],
];
