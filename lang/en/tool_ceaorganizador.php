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
 * Language strings (en).
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['ceaorganizador:manage'] = 'Manage the category organiser (CEA)';
$string['conflict_categoriesorder'] = 'The order of the subcategories of «{$a}» changed after this operation.';
$string['conflict_coursesorder'] = 'The order of the courses of «{$a}» changed after this operation.';
$string['conflict_missing'] = 'The item with id {$a} no longer exists.';
$string['conflict_moved'] = '«{$a}» moved after this operation.';
$string['conflict_renamed'] = '«{$a}» was renamed after this operation.';
$string['conflict_shortnametaken'] = 'The previous short name of «{$a}» is now used by another course.';
$string['conflict_targetmissing'] = 'The original position of «{$a}» no longer exists.';
$string['conflict_visibility'] = 'The visibility of «{$a}» changed after this operation.';
$string['credit'] = 'Centro de Innovación Pedagógica y Educación Digital (CEA) · Universidad de San Buenaventura Cali';
$string['error_aftermismatch'] = 'The target position is no longer valid; reload the tree.';
$string['error_alreadyundone'] = 'This operation has already been undone.';
$string['error_cannotmoveintodescendant'] = 'A category cannot be moved into itself or into one of its subcategories.';
$string['error_ordermismatch'] = 'The order sent does not match the current contents; reload the tree.';
$string['error_toomanycourses'] = 'The category has more courses than Moodle can sort ({$a}); adjust maxcoursesincategory.';
$string['intro'] = 'Organise categories and courses by dragging, sorting and renaming.';
$string['nativemanagement'] = 'Native category management';
$string['patterns_facultad'] = 'Faculty patterns';
$string['patterns_facultad_desc'] = 'One pattern per line. Plain text: the name contains it. =text: exact name. /regex/: regular expression on the lowercase, accent-free name.';
$string['patterns_periodo'] = 'Period patterns';
$string['patterns_periodo_desc'] = 'One pattern per line.';
$string['patterns_semilla'] = 'Seed patterns';
$string['patterns_semilla_desc'] = 'One pattern per line. Plain text: the name contains it. =text: exact name. /regex/: regular expression on the lowercase, accent-free name.';
$string['pluginname'] = 'Category organiser (CEA)';
$string['privacy:metadata'] = 'The organiser stores who made and who undid each operation on categories and courses.';
$string['privacy:metadata:log'] = 'Journal of the organiser operations, used to undo them and to show the history.';
$string['privacy:metadata:log:action'] = 'Type of operation: move, sort or rename.';
$string['privacy:metadata:log:payload'] = 'Previous and new state of the affected categories and courses.';
$string['privacy:metadata:log:timecreated'] = 'Date and time of the operation.';
$string['privacy:metadata:log:undoneby'] = 'User who undid the operation.';
$string['privacy:metadata:log:userid'] = 'User who made the operation.';
$string['settings'] = 'Category organiser settings';
$string['summary_movecategory'] = 'Move the category «{$a}»';
$string['summary_movecourse'] = 'Move the course «{$a}»';
$string['taskcleanup'] = 'Clean up the category organiser journal';
$string['treeheading'] = 'Category tree';
