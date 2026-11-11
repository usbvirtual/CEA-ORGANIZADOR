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

$string['affixadd'] = 'Agregar';
$string['affixmode'] = 'Acción';
$string['affixposition'] = 'Posición';
$string['affixremove'] = 'Quitar';
$string['affixtext'] = 'Texto';
$string['after'] = 'Después';
$string['apply'] = 'Aplicar';
$string['autosort'] = 'Auto ordenar';
$string['autosortdone'] = 'Orden aplicado';
$string['autosortnothing'] = 'No hay nada que ordenar aquí.';
$string['autosortpreview'] = 'Esto es lo que cambiaría';
$string['autosortrecursive'] = 'Incluir lo que está por debajo';
$string['autosortscope'] = 'Qué ordenar';
$string['autosortscope_both'] = 'Subcategorías y cursos';
$string['autosortscope_categories'] = 'Subcategorías';
$string['autosortscope_courses'] = 'Cursos';
$string['autosortselected'] = 'Auto ordenar seleccionadas';
$string['batchsplit'] = 'Son muchos elementos: se aplicarán en {$a} lotes.';
$string['before'] = 'Antes';
$string['case_none'] = 'Dejar las mayúsculas como están';
$string['case_sentence'] = 'Tipo oración';
$string['case_title'] = 'Tipo título';
$string['case_upper'] = 'TODO EN MAYÚSCULAS';
$string['casesensitive'] = 'Distinguir mayúsculas y minúsculas';
$string['ceaorganizador:manage'] = 'Gestionar el organizador de categorías (CEA)';
$string['clearselection'] = 'Limpiar';
$string['cloneapply'] = 'Clonar cursos ({$a})';
$string['clonecronnote'] = 'Las copias se hacen en segundo plano y tardan según la capacidad del cron de Moodle.';
$string['clonedest'] = 'Destino';
$string['clonedone'] = 'Listo';
$string['cloneend'] = 'Fecha de fin';
$string['cloneerror_alreadycloned'] = 'Ya está clonado en este periodo.';
$string['cloneerror_dates'] = 'La fecha de fin debe ser posterior a la de inicio.';
$string['cloneerror_destinsideseeds'] = 'El destino no puede estar dentro de las semillas.';
$string['cloneerror_empty'] = 'El nombre no puede quedar vacío.';
$string['cloneerror_label'] = 'Escribe el periodo como AAAA-01 o AAAA-02, por ejemplo 2027-01.';
$string['cloneerror_noperiod'] = 'Elige el periodo de destino.';
$string['cloneerror_nopermission'] = 'No tienes permiso para clonar este curso.';
$string['cloneerror_queuefailed'] = 'No se pudo encolar la copia.';
$string['cloneerror_shortnameduplicate'] = 'El nombre corto se repite en el lote.';
$string['cloneerror_shortnametaken'] = 'Ya existe un curso con ese nombre corto.';
$string['cloneerror_toolong'] = 'El nombre es demasiado largo.';
$string['clonefailed'] = 'Fallido';
$string['clonelabel'] = 'Etiqueta del periodo';
$string['clonenames'] = 'Nombre de la copia';
$string['clonenew'] = 'Nueva';
$string['clonenewfullname'] = 'Nombre completo de la copia';
$string['clonenewshortname'] = 'Nombre corto de la copia';
$string['clonenoperiods'] = 'No hay categorías con periodo (por ejemplo 2027-01) donde clonar.';
$string['clonenothing'] = 'No quedó ningún curso por clonar.';
$string['clonepending'] = 'Pendiente';
$string['cloneperiod'] = 'Periodo de destino';
$string['clonepreview'] = 'Vista previa';
$string['cloneprogress'] = 'Copias en curso';
$string['clonesource'] = 'Semilla';
$string['clonestale'] = 'Algo cambió desde la vista previa; la volví a generar.';
$string['clonestart'] = 'Fecha de inicio';
$string['clonestate'] = 'Estado';
$string['clonetoperiod'] = 'Clonar a periodo…';
$string['clonetotal'] = '{$a} cursos';
$string['cloneviewprogress'] = 'Ver avance';
$string['close'] = 'Cerrar';
$string['confirm'] = 'Confirmar';
$string['conflict_categoriesorder'] = 'El orden de las subcategorías de «{$a}» cambió después de esta operación.';
$string['conflict_coursesorder'] = 'El orden de los cursos de «{$a}» cambió después de esta operación.';
$string['conflict_missing'] = 'El elemento con id {$a} ya no existe.';
$string['conflict_moved'] = '«{$a}» cambió de lugar después de esta operación.';
$string['conflict_renamed'] = '«{$a}» cambió de nombre después de esta operación.';
$string['conflict_shortnametaken'] = 'El nombre corto anterior de «{$a}» ya lo usa otro curso.';
$string['conflict_targetmissing'] = 'La posición original de «{$a}» ya no existe.';
$string['conflict_visibility'] = 'La visibilidad de «{$a}» cambió después de esta operación.';
$string['coursecount'] = '{$a} cursos';
$string['credit'] = 'Centro de Innovación Pedagógica y Educación Digital (CEA) · Universidad de San Buenaventura Cali';
$string['cronwarning'] = 'Las copias dependen del cron de Moodle; verifica que esté activo.';
$string['dropafter'] = 'Después de {$a}';
$string['dropbefore'] = 'Antes de {$a}';
$string['dropcancelled'] = 'Movimiento cancelado';
$string['dropinside'] = 'Dentro de {$a}';
$string['error_aftermismatch'] = 'La posición de destino ya no es válida; recarga el árbol.';
$string['error_alreadyundone'] = 'Esta operación ya se deshizo.';
$string['error_busy'] = 'Otra persona está reorganizando; intenta en un momento.';
$string['error_cannotmoveintodescendant'] = 'No se puede mover una categoría dentro de sí misma o de una de sus subcategorías.';
$string['error_cannotundoclone'] = 'Los clonados no se pueden deshacer; borra los cursos desde Moodle si sobran.';
$string['error_generic'] = 'Algo salió mal. Recarga la página e inténtalo de nuevo.';
$string['error_ordermismatch'] = 'El orden enviado no coincide con el contenido actual; recarga el árbol.';
$string['error_toomany'] = 'Máximo 500 elementos por lote.';
$string['error_toomanyclones'] = 'Máximo {$a} cursos por lote de clonado.';
$string['error_toomanycourses'] = 'La categoría tiene más cursos de los que Moodle permite ordenar ({$a}); ajusta maxcoursesincategory.';
$string['error_toomanymerge'] = 'Máximo {$a} cursos por unificación.';
$string['glossary'] = 'Glosario institucional';
$string['glossary_desc'] = 'Una regla por línea, con la forma incorrecto => correcto. El reemplazo es por palabra completa y no distingue mayúsculas. Ejemplo: Cien => Ciencias.';
$string['grabbed'] = 'Tomado: {$a}. Usa las flechas para elegir el destino, Enter para soltar, Esc para cancelar.';
$string['hidden'] = 'Oculta';
$string['hidecourse'] = 'Ocultar';
$string['hideselected'] = 'Ocultar seleccionados';
$string['history'] = 'Historial';
$string['history_empty'] = 'Todavía no hay operaciones en el historial.';
$string['historypage'] = 'Página {$a}';
$string['historyundone'] = 'Deshecha';
$string['includeshortname'] = 'Incluir nombre corto';
$string['intro'] = 'Organiza categorías y cursos arrastrando, ordenando y renombrando.';
$string['invaliddrop'] = 'No se puede soltar aquí';
$string['keyboardhint'] = 'Teclado: flechas para navegar · Espacio toma · M mover a · S seleccionar · Shift+F10 menú';
$string['loadingcourses'] = 'Cargando cursos…';
$string['loadmore'] = 'Cargar más';
$string['merge'] = 'Unificar semillas';
$string['mergeaction'] = 'Acción';
$string['mergeapply'] = 'Unificar ({$a})';
$string['mergecourse'] = 'Curso';
$string['mergecreate'] = 'Crear categoría de semillas';
$string['mergedone'] = 'Cursos unificados: {$a}';
$string['mergeduplicate'] = 'Repetido';
$string['mergeemptied'] = 'Quedaron vacías; puedes borrarlas desde Moodle:';
$string['mergefrom'] = 'Desde';
$string['mergemove'] = 'Mover';
$string['mergenothing'] = 'No hay cursos para unificar.';
$string['mergepreview'] = 'Vista previa';
$string['mergeseeddefault'] = 'Semillas';
$string['mergeseedexists'] = 'Ya existía una categoría con ese nombre; se usará esa.';
$string['mergeseedname'] = 'Nombre de la categoría de semillas';
$string['mergeskip'] = 'Omitir';
$string['mergesources'] = 'Categorías de origen';
$string['mergestale'] = 'Algo cambió desde la vista previa; la volví a generar.';
$string['mergetarget'] = 'Categoría de destino';
$string['mergetargetnow'] = 'Destino: {$a}';
$string['mergeto'] = 'Hacia';
$string['moved'] = 'Movido';
$string['movetitle'] = 'Mover «{$a}» a…';
$string['moveto'] = 'Mover a…';
$string['movetoroot'] = 'Raíz (primer nivel)';
$string['nativemanagement'] = 'Gestión nativa de categorías';
$string['needsort'] = 'Te recomiendo auto ordenar';
$string['needsortsummary'] = '{$a} categorías se pueden ordenar';
$string['nextpage'] = 'Siguiente';
$string['nodemenu'] = 'Más acciones de «{$a}»';
$string['opencategory'] = 'Abrir en Moodle';
$string['opmovedown'] = 'Bajar la operación';
$string['opmoveup'] = 'Subir la operación';
$string['opremove'] = 'Quitar la operación';
$string['patterns_facultad'] = 'Patrones de facultad';
$string['patterns_facultad_desc'] = 'Un patrón por línea. Texto simple: el nombre lo contiene. =texto: nombre exacto. /expresión/: expresión regular sobre el nombre en minúsculas y sin tildes.';
$string['patterns_semilla'] = 'Patrones de semilla';
$string['patterns_semilla_desc'] = 'Un patrón por línea. Texto simple: el nombre lo contiene. =texto: nombre exacto. /expresión/: expresión regular sobre el nombre en minúsculas y sin tildes.';
$string['pluginname'] = 'Organizador de categorías (CEA)';
$string['prefix'] = 'Prefijo';
$string['prevpage'] = 'Anterior';
$string['privacy:metadata'] = 'El organizador guarda quién hizo y quién deshizo cada operación sobre categorías y cursos.';
$string['privacy:metadata:log'] = 'Registro de las operaciones del organizador, para deshacerlas y mostrar el historial.';
$string['privacy:metadata:log:action'] = 'Tipo de operación: mover, ordenar, renombrar, ocultar o mostrar cursos, o clonar.';
$string['privacy:metadata:log:payload'] = 'Estado anterior y nuevo de las categorías y los cursos afectados.';
$string['privacy:metadata:log:summary'] = 'Resumen de la operación, que puede incluir el nombre de la categoría o del curso afectado.';
$string['privacy:metadata:log:timecreated'] = 'Fecha y hora de la operación.';
$string['privacy:metadata:log:timeundone'] = 'Fecha y hora en que se deshizo la operación.';
$string['privacy:metadata:log:undoneby'] = 'Usuario que deshizo la operación.';
$string['privacy:metadata:log:userid'] = 'Usuario que hizo la operación.';
$string['reason_formato'] = 'Formato';
$string['reason_glosario'] = 'Glosario';
$string['reason_tilde'] = 'Tilde';
$string['reason_variante'] = 'Variante';
$string['rename'] = 'Renombrar';
$string['renameaddop'] = 'Agregar operación';
$string['renamedone'] = '{$a} renombrados';
$string['renameerror_empty'] = 'El nombre nuevo queda vacío.';
$string['renameerror_multilang'] = 'El nombre usa etiquetas multilenguaje y no se puede cambiar aquí.';
$string['renameerror_shortnameduplicate'] = 'Otro curso del lote queda con el mismo nombre corto.';
$string['renameerror_shortnametaken'] = 'Otro curso ya usa ese nombre corto.';
$string['renameerror_toolong'] = 'El nombre nuevo supera los 254 caracteres.';
$string['renamenothing'] = 'Con estas operaciones no cambia ningún nombre.';
$string['renameop_affix'] = 'Prefijo o sufijo';
$string['renameop_clean'] = 'Limpiar';
$string['renameop_replace'] = 'Reemplazar';
$string['renameoptype'] = 'Tipo de operación';
$string['renamepreview'] = 'Vista previa';
$string['renameskipped'] = 'Omitido: cambió desde la vista previa.';
$string['replacewith'] = 'Reemplazar por';
$string['resultscount'] = 'Se muestran {$a->shown} de {$a->total} cursos';
$string['review'] = 'Revisar';
$string['search'] = 'Buscar';
$string['searchfor'] = 'Buscar';
$string['searchplaceholder'] = 'Buscar categoría o curso…';
$string['selectcontents'] = 'Seleccionar contenido';
$string['selected'] = '{$a} seleccionados';
$string['selectitem'] = 'Seleccionar «{$a}»';
$string['selectresults'] = 'Seleccionar resultados';
$string['settings'] = 'Ajustes del organizador de categorías';
$string['shortname'] = 'Nombre corto';
$string['showcourse'] = 'Mostrar';
$string['showselected'] = 'Mostrar seleccionados';
$string['sortselectedcount'] = '{$a} categorías seleccionadas';
$string['stale'] = 'El orden cambió, vuelve a revisar.';
$string['suffix'] = 'Sufijo';
$string['suggest'] = 'Sugerir correcciones';
$string['suggestapply'] = 'Aplicar seleccionadas ({$a})';
$string['suggestcourses'] = 'Incluir cursos';
$string['suggestnone'] = 'No hay correcciones sugeridas.';
$string['suggestroot'] = 'Nivel superior';
$string['suggesttoggleall'] = 'Marcar/desmarcar todo';
$string['suggesttruncated'] = 'Hay {$a} correcciones y se muestran solo las primeras 2000. Aplica estas y vuelve a abrir el panel para ver el resto.';
$string['summary_clone'] = 'Clonado a {$a->label} · cursos: {$a->count}';
$string['summary_hide'] = 'Ocultar: {$a}';
$string['summary_merge'] = 'Unificar en {$a->target} · cursos: {$a->count}';
$string['summary_movecategory'] = 'Mover la categoría «{$a}»';
$string['summary_movecourse'] = 'Mover el curso «{$a}»';
$string['summary_rename'] = 'Renombrar {$a} elementos';
$string['summary_show'] = 'Mostrar: {$a}';
$string['summary_sort'] = 'Auto ordenar «{$a}»';
$string['summarymore'] = '{$a->list} y {$a->count} más';
$string['taskcleanup'] = 'Limpiar el registro del organizador de categorías';
$string['treeheading'] = 'Árbol de categorías';
$string['undo'] = 'Deshacer';
$string['undoconflicts'] = 'No se pudo deshacer porque algo cambió:';
$string['visibilitychanged'] = 'Visibilidad actualizada';
$string['wholeword'] = 'Solo palabra completa';
