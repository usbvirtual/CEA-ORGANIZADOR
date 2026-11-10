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
 * Language strings (es).
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['ceaorganizador:manage'] = 'Gestionar el organizador de categorías (CEA)';
$string['conflict_categoriesorder'] = 'El orden de las subcategorías de «{$a}» cambió después de esta operación.';
$string['conflict_coursesorder'] = 'El orden de los cursos de «{$a}» cambió después de esta operación.';
$string['conflict_missing'] = 'El elemento con id {$a} ya no existe.';
$string['conflict_moved'] = '«{$a}» cambió de lugar después de esta operación.';
$string['conflict_renamed'] = '«{$a}» cambió de nombre después de esta operación.';
$string['conflict_shortnametaken'] = 'El nombre corto anterior de «{$a}» ya lo usa otro curso.';
$string['conflict_targetmissing'] = 'La posición original de «{$a}» ya no existe.';
$string['credit'] = 'Centro de Innovación Pedagógica y Educación Digital (CEA) · Universidad de San Buenaventura Cali';
$string['error_aftermismatch'] = 'La posición de destino ya no es válida; recarga el árbol.';
$string['error_alreadyundone'] = 'Esta operación ya se deshizo.';
$string['error_cannotmoveintodescendant'] = 'No se puede mover una categoría dentro de sí misma o de una de sus subcategorías.';
$string['error_ordermismatch'] = 'El orden enviado no coincide con el contenido actual; recarga el árbol.';
$string['error_toomanycourses'] = 'La categoría tiene más cursos de los que Moodle permite ordenar ({$a}); ajusta maxcoursesincategory.';
$string['intro'] = 'Organiza categorías y cursos arrastrando, ordenando y renombrando.';
$string['nativemanagement'] = 'Gestión nativa de categorías';
$string['patterns_facultad'] = 'Patrones de facultad';
$string['patterns_facultad_desc'] = 'Un patrón por línea. Texto simple: el nombre lo contiene. =texto: nombre exacto. /expresión/: expresión regular sobre el nombre en minúsculas y sin tildes.';
$string['patterns_periodo'] = 'Patrones de periodo';
$string['patterns_periodo_desc'] = 'Un patrón por línea.';
$string['patterns_semilla'] = 'Patrones de semilla';
$string['patterns_semilla_desc'] = 'Un patrón por línea. Texto simple: el nombre lo contiene. =texto: nombre exacto. /expresión/: expresión regular sobre el nombre en minúsculas y sin tildes.';
$string['pluginname'] = 'Organizador de categorías (CEA)';
$string['privacy:metadata'] = 'El organizador guarda quién hizo y quién deshizo cada operación sobre categorías y cursos.';
$string['privacy:metadata:log'] = 'Registro de las operaciones del organizador, para deshacerlas y mostrar el historial.';
$string['privacy:metadata:log:action'] = 'Tipo de operación: mover, ordenar o renombrar.';
$string['privacy:metadata:log:payload'] = 'Estado anterior y nuevo de las categorías y los cursos afectados.';
$string['privacy:metadata:log:timecreated'] = 'Fecha y hora de la operación.';
$string['privacy:metadata:log:undoneby'] = 'Usuario que deshizo la operación.';
$string['privacy:metadata:log:userid'] = 'Usuario que hizo la operación.';
$string['settings'] = 'Ajustes del organizador de categorías';
$string['summary_movecategory'] = 'Mover la categoría «{$a}»';
$string['summary_movecourse'] = 'Mover el curso «{$a}»';
$string['taskcleanup'] = 'Limpiar el registro del organizador de categorías';
$string['treeheading'] = 'Árbol de categorías';
