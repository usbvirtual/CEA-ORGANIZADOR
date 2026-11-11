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

$string['affixadd'] = 'Add';
$string['affixmode'] = 'Action';
$string['affixposition'] = 'Position';
$string['affixremove'] = 'Remove';
$string['affixtext'] = 'Text';
$string['after'] = 'After';
$string['apply'] = 'Apply';
$string['autosort'] = 'Auto sort';
$string['autosortdone'] = 'Order applied';
$string['autosortnothing'] = 'There is nothing to sort here.';
$string['autosortpreview'] = 'This is what would change';
$string['autosortrecursive'] = 'Include everything below';
$string['autosortscope'] = 'What to sort';
$string['autosortscope_both'] = 'Subcategories and courses';
$string['autosortscope_categories'] = 'Subcategories';
$string['autosortscope_courses'] = 'Courses';
$string['autosortselected'] = 'Auto sort selected';
$string['batchsplit'] = 'There are many items: they will be applied in {$a} batches.';
$string['before'] = 'Before';
$string['case_none'] = 'Keep the capitals as they are';
$string['case_sentence'] = 'Sentence case';
$string['case_title'] = 'Title case';
$string['case_upper'] = 'ALL CAPITALS';
$string['casesensitive'] = 'Match case';
$string['ceaorganizador:manage'] = 'Manage the category organiser (CEA)';
$string['clearselection'] = 'Clear';
$string['cloneapply'] = 'Clone courses ({$a})';
$string['clonecronnote'] = 'Copies run in the background and take as long as the Moodle cron allows.';
$string['clonedest'] = 'Destination';
$string['clonedone'] = 'Done';
$string['cloneend'] = 'End date';
$string['cloneerror_alreadycloned'] = 'Already cloned in this period.';
$string['cloneerror_dates'] = 'The end date must be after the start date.';
$string['cloneerror_destinsideseeds'] = 'The destination cannot be inside the seeds.';
$string['cloneerror_empty'] = 'The name cannot be empty.';
$string['cloneerror_label'] = 'Write the period as YYYY-01 or YYYY-02, for example 2027-01.';
$string['cloneerror_noperiod'] = 'Choose the destination period.';
$string['cloneerror_nopermission'] = 'You do not have permission to clone this course.';
$string['cloneerror_queuefailed'] = 'The copy could not be queued.';
$string['cloneerror_shortnameduplicate'] = 'The short name is repeated in the batch.';
$string['cloneerror_shortnametaken'] = 'A course with that short name already exists.';
$string['cloneerror_toolong'] = 'The name is too long.';
$string['clonefailed'] = 'Failed';
$string['clonelabel'] = 'Period label';
$string['clonenames'] = 'Name of the copy';
$string['clonenew'] = 'New';
$string['clonenewfullname'] = 'Full name of the copy';
$string['clonenewshortname'] = 'Short name of the copy';
$string['clonenoperiods'] = 'There are no categories with a period (for example 2027-01) to clone into.';
$string['clonenothing'] = 'No course was left to clone.';
$string['clonepending'] = 'Pending';
$string['cloneperiod'] = 'Destination period';
$string['clonepreview'] = 'Preview';
$string['cloneprogress'] = 'Copies in progress';
$string['clonesource'] = 'Seed';
$string['clonestale'] = 'Something changed since the preview; it was generated again.';
$string['clonestart'] = 'Start date';
$string['clonestate'] = 'Status';
$string['clonetoperiod'] = 'Clone to period…';
$string['clonetotal'] = '{$a} courses';
$string['cloneviewprogress'] = 'View progress';
$string['close'] = 'Close';
$string['confirm'] = 'Confirm';
$string['conflict_categoriesorder'] = 'The order of the subcategories of «{$a}» changed after this operation.';
$string['conflict_coursesorder'] = 'The order of the courses of «{$a}» changed after this operation.';
$string['conflict_missing'] = 'The item with id {$a} no longer exists.';
$string['conflict_moved'] = '«{$a}» moved after this operation.';
$string['conflict_renamed'] = '«{$a}» was renamed after this operation.';
$string['conflict_shortnametaken'] = 'The previous short name of «{$a}» is now used by another course.';
$string['conflict_targetmissing'] = 'The original position of «{$a}» no longer exists.';
$string['conflict_visibility'] = 'The visibility of «{$a}» changed after this operation.';
$string['coursecount'] = '{$a} courses';
$string['credit'] = 'Centro de Innovación Pedagógica y Educación Digital (CEA) · Universidad de San Buenaventura Cali';
$string['cronwarning'] = 'Copies depend on the Moodle cron; check that it is running.';
$string['dropafter'] = 'After {$a}';
$string['dropbefore'] = 'Before {$a}';
$string['dropcancelled'] = 'Move cancelled';
$string['dropinside'] = 'Inside {$a}';
$string['error_aftermismatch'] = 'The target position is no longer valid; reload the tree.';
$string['error_alreadyundone'] = 'This operation has already been undone.';
$string['error_busy'] = 'Someone else is reorganising; try again in a moment.';
$string['error_cannotmoveintodescendant'] = 'A category cannot be moved into itself or into one of its subcategories.';
$string['error_cannotundoclone'] = 'Clones cannot be undone; delete the extra courses in Moodle if needed.';
$string['error_generic'] = 'Something went wrong. Reload the page and try again.';
$string['error_ordermismatch'] = 'The order sent does not match the current contents; reload the tree.';
$string['error_stale'] = 'The order changed since the preview; review it again.';
$string['error_toomany'] = 'At most 500 items per batch.';
$string['error_toomanyclones'] = 'At most {$a} courses per cloning batch.';
$string['error_toomanycourses'] = 'The category has more courses than Moodle can sort ({$a}); adjust maxcoursesincategory.';
$string['grabbed'] = 'Picked up: {$a}. Use the arrow keys to choose the destination, Enter to drop, Esc to cancel.';
$string['hidden'] = 'Hidden';
$string['hidecourse'] = 'Hide';
$string['hideselected'] = 'Hide selected';
$string['history'] = 'History';
$string['history_empty'] = 'There are no operations in the history yet.';
$string['historypage'] = 'Page {$a}';
$string['historyundone'] = 'Undone';
$string['includeshortname'] = 'Include short name';
$string['intro'] = 'Organise categories and courses by dragging, sorting and renaming.';
$string['invaliddrop'] = 'It cannot be dropped here';
$string['keyboardhint'] = 'Keyboard: arrows to navigate · Space grabs · M move to · S select · Shift+F10 menu';
$string['loadingcourses'] = 'Loading courses…';
$string['loadmore'] = 'Load more';
$string['moved'] = 'Moved';
$string['movetitle'] = 'Move "{$a}" to…';
$string['moveto'] = 'Move to…';
$string['movetoroot'] = 'Top level';
$string['nativemanagement'] = 'Native category management';
$string['needsort'] = 'Auto sort recommended';
$string['needsortsummary'] = '{$a} categories can be sorted';
$string['nextpage'] = 'Next';
$string['nodemenu'] = 'More actions for «{$a}»';
$string['opencategory'] = 'Open in Moodle';
$string['opmovedown'] = 'Move the operation down';
$string['opmoveup'] = 'Move the operation up';
$string['opremove'] = 'Remove the operation';
$string['patterns_facultad'] = 'Faculty patterns';
$string['patterns_facultad_desc'] = 'One pattern per line. Plain text: the name contains it. =text: exact name. /regex/: regular expression on the lowercase, accent-free name.';
$string['patterns_semilla'] = 'Seed patterns';
$string['patterns_semilla_desc'] = 'One pattern per line. Plain text: the name contains it. =text: exact name. /regex/: regular expression on the lowercase, accent-free name.';
$string['pluginname'] = 'Category organiser (CEA)';
$string['prefix'] = 'Prefix';
$string['prevpage'] = 'Previous';
$string['privacy:metadata'] = 'The organiser stores who made and who undid each operation on categories and courses.';
$string['privacy:metadata:log'] = 'Journal of the organiser operations, used to undo them and to show the history.';
$string['privacy:metadata:log:action'] = 'Type of operation: move, sort, rename, hide or show courses, or clone.';
$string['privacy:metadata:log:payload'] = 'Previous and new state of the affected categories and courses.';
$string['privacy:metadata:log:summary'] = 'Summary of the operation, which may include the name of the affected category or course.';
$string['privacy:metadata:log:timecreated'] = 'Date and time of the operation.';
$string['privacy:metadata:log:timeundone'] = 'Date and time when the operation was undone.';
$string['privacy:metadata:log:undoneby'] = 'User who undid the operation.';
$string['privacy:metadata:log:userid'] = 'User who made the operation.';
$string['rename'] = 'Rename';
$string['renameaddop'] = 'Add operation';
$string['renamedone'] = '{$a} renamed';
$string['renameerror_empty'] = 'The new name is empty.';
$string['renameerror_multilang'] = 'The name uses multilang tags and cannot be changed here.';
$string['renameerror_shortnameduplicate'] = 'Another course of the batch ends with the same short name.';
$string['renameerror_shortnametaken'] = 'Another course already uses that short name.';
$string['renameerror_toolong'] = 'The new name is longer than 254 characters.';
$string['renamenothing'] = 'With these operations no name changes.';
$string['renameop_affix'] = 'Prefix or suffix';
$string['renameop_clean'] = 'Clean up';
$string['renameop_replace'] = 'Replace';
$string['renameoptype'] = 'Type of operation';
$string['renamepreview'] = 'Preview';
$string['renameskipped'] = 'Skipped: it changed since the preview.';
$string['replacewith'] = 'Replace with';
$string['resultscount'] = 'Showing {$a->shown} of {$a->total} courses';
$string['review'] = 'Review';
$string['search'] = 'Search';
$string['searchfor'] = 'Search for';
$string['searchplaceholder'] = 'Search category or course…';
$string['selectcontents'] = 'Select contents';
$string['selected'] = '{$a} selected';
$string['selectitem'] = 'Select «{$a}»';
$string['selectresults'] = 'Select results';
$string['settings'] = 'Category organiser settings';
$string['shortname'] = 'Short name';
$string['showcourse'] = 'Show';
$string['showselected'] = 'Show selected';
$string['sortselectedcount'] = '{$a} categories selected';
$string['stale'] = 'The order changed, review it again.';
$string['suffix'] = 'Suffix';
$string['summary_clone'] = 'Cloned to {$a->label} · courses: {$a->count}';
$string['summary_hide'] = 'Hide: {$a}';
$string['summary_movecategory'] = 'Move the category «{$a}»';
$string['summary_movecourse'] = 'Move the course «{$a}»';
$string['summary_rename'] = 'Rename {$a} items';
$string['summary_show'] = 'Show: {$a}';
$string['summary_sort'] = 'Auto sort «{$a}»';
$string['summarymore'] = '{$a->list} and {$a->count} more';
$string['taskcleanup'] = 'Clean up the category organiser journal';
$string['treeheading'] = 'Category tree';
$string['undo'] = 'Undo';
$string['undoconflicts'] = 'It could not be undone because something changed:';
$string['visibilitychanged'] = 'Visibility updated';
$string['wholeword'] = 'Whole word only';
