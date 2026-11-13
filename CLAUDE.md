# CLAUDE.md

Guía para trabajar en este repositorio con Claude Code. Lee primero [README.md](README.md).

## Qué es

Plugin de Moodle **CEA-ORGANIZADOR** (`tool_ceaorganizador`) del Centro de Innovación Pedagógica y Educación Digital (CEA), USB Cali. Carpeta de instalación: `admin/tool/ceaorganizador`. Especificación: `docs/superpowers/specs/…`.

## Comandos

```bash
npm test               # pruebas JS; deben pasar antes de dar algo por terminado
npm run check-strings  # cadenas completas, iguales y ordenadas en es y en
npm run build          # recompila amd/build tras tocar amd/src
npm run package        # dist/CEA-ORGANIZADOR.zip (lo que se entrega al administrador)
```

No hay Moodle en este equipo: la compatibilidad real la confirma GitHub Actions.

## Reglas

Este plugin sigue la skill `creating-cea-moodle-plugins` (estándares y lecciones de los plugins del CEA) y la skill `cea-branding`. Reglas propias de este plugin:

- Requiere Moodle 4.5 o superior y PHP 8.1 a 8.4 (decisión del usuario).
- Toda escritura (mover, reordenar, renombrar) pasa solo por `classes/local/mover.php` y `classes/local/journal.php`.
- `sorter` y `renamer` son puros: no tocan la base de datos.

## Decisiones de diseño que conviene no deshacer

- El hash de la vista previa de «Auto ordenar» (`sortplan`) se calcula solo sobre ids, no sobre nombres: las diferencias de `format_string` no deben dar falsos «el orden cambió».
- `rename_apply` es atómico: aplica todas las filas elegibles en una sola transacción delegada; si una falla, se revierte todo el lote. Las filas cuyo nombre cambió desde la vista previa se omiten, no son error.
- `renamer` solo transforma nombres. El nombre corto nuevo se calcula en `rename_preview` con `renamer::apply($shortname, $ops)`; la tabla manual edita solo el nombre completo.
- `renamer::clean` pone un guion como « - » solo si ya tiene espacio a algún lado: así no se rompen periodos como `2026-2` ni códigos como `Ing-Sist`.
- Los nombres crudos de `rename_*` jamás se decodifican. Los nombres formateados (`format_string`: árbol, cursos, vista previa de orden, historial) se decodifican con `amd/src/text.js` (`decodeEntities`, vía `DOMParser`, nunca insertando HTML) y se pintan con `textContent`.
- «Ocultar seleccionados» / «Mostrar seleccionados» es un solo botón (decisión del usuario: no repetir opciones): con categorías marcadas corre «Ocultar todo»/«Mostrar todo» sobre las exteriores (`outerIds`) y, si no se cancela, luego la visibilidad de los cursos marcados que no están dentro de ellas (`visibility.visibilityTargets`, categoría del curso por la caché del árbol; los que no se conocen se envían y el servidor omite los que ya están así). `visibilitytree.run` acepta `then`, que corre tras aplicar o sin nada que escribir, nunca al cancelar.
- Cada opción está en un solo lugar (decisión del usuario): las acciones en la barra superior y la de selección; cada fila tiene solo «Mover a…» y el menú ⋯ de una categoría solo «Seleccionar contenido» y «Abrir en Moodle»; los cursos no tienen menú. «Sugerir correcciones» revisa la categoría marcada si hay una sola; si no, todo el sitio.
- Mover a un padre oculto oculta el elemento (comportamiento del núcleo). `journal` guarda `visible` en `from`/`to`, y deshacer restaura la visibilidad solo si el movimiento la cambió; si la visibilidad actual no es la que dejó la operación, es un conflicto.
- Deshacer es atómico y se rechaza si algo cambió después (conflictos listados). `guard::rollback` es el único helper de rollback; `mover` y `journal` lo usan.
- En las pruebas de rollback hay que llamar `$this->preventResetByRollback()` justo después de `resetAfterTest()`: en PostgreSQL `advanced_testcase` envuelve cada prueba en una transacción delegada y el rollback anidado solo marca `force_rollback`.
- Los servicios web usan `\core_external\external_api` y sus clases de parámetros, nunca los alias globales (no existen en 5.3).
- Los módulos JS que necesitan `core/*` lo reciben inyectado (`api.setTransport`, proveedor de cadenas) para que `node:test` corra sin Moodle. Sin librerías externas: el arrastre usa eventos de puntero propios.
- El candado (`guard::lock()`) vive en la capa `external`, no en `mover`; en «Borrar categorías» lo toma `delete_apply` y en «Ocultar todo» `visibility_tree_apply`. El renombrado y la visibilidad de cursos se limitan a 100 elementos por lote (`rename_apply::MAXITEMS`, `set_visibility::MAXITEMS`; en JS `MAX_BATCH` de `renamepanel`, `suggestpanel` y `visibility`): cada curso pasa por `update_course()`, que purga cachés del sitio. La interfaz parte la lista y ofrece un solo «Deshacer» que recorre los lotes al revés y recarga el árbol una sola vez al final (`mover.undo(logid, {reload: false})`). Cada lote es todo o nada; los anteriores quedan aplicados.
- `suggester` nunca tilda palabras de diacrítico ambiguo (stoplist: si, mas, el, tu, te, se, de, mi, solo, aun, que, como, cuando, donde, quien, cual, cuanto, este, esta, ese, esa, aquel, o): una tilde gramatical equivocada es peor que ninguna; lo que falte va al glosario.
- La regla «variante» solo empareja nombres hermanos idénticos salvo una palabra en la misma posición (singular/plural), nunca palabras sueltas entre hermanos.
- «variante» copia la palabra preferida tal como está escrita en el sitio (con su tilde); no construye sufijos.
- «Unificar categorías» y «Quitar repetidos» se quitaron del plugin (decisión del usuario): servicios, clases, paneles, reglas de repetidos (`duplicates`) y marcas del árbol. Se conservan solo el deshacer de las acciones `merge` y `dedupe` del diario (`journal::conflicts_merge`, `restore_merge`; `undo::check_merge`), para que las entradas ya registradas se puedan deshacer, y sus resúmenes ya guardados. No volver a escribir esas acciones.
- Deshacer una unificación antigua solo borra categorías creadas que no tengan nada, con la regla de `mover::category_content` (ver «Borrar categorías»; `mover::delete_empty_category`). Las que tienen contenido se conservan.
- Los elementos del diario de unificación (antiguos) llevan `fromvisible`/`tovisible`: deshacer restaura la visibilidad solo si el movimiento la cambió, igual que mover.
- Con carpeta bajo el periodo, los cursos que no están bajo una raíz de semillas también van a la carpeta (`container + []`): la carpeta es donde viven las facultades del periodo.
- El destino en espejo del clonado deja fuera la raíz de semillas (o la categoría de origen): la ruta empieza en la facultad.
- `period.suggestContainer` ignora las hijas del periodo cuyo nombre contiene un periodo (`parsePeriod`), porque el servidor rechaza esa carpeta.
- El orden de cursos de una categoría se rechaza si supera `maxcoursesincategory` (`error_toomanycourses`).
- `clonerunner` llama a `create_copy` después del commit de las categorías, una transacción delegada por copia (un fallo no deja un esqueleto ni revierte las demás).
- Las copias en curso cuentan como ya tomadas (ventana de 30 días, `cloneplan::INFLIGHT_DAYS`): relanzar el lote antes de que corra el cron no duplica.
- La acción `clone` no tiene deshacer (`error_cannotundoclone`): deshacerla borraría cursos.
- El estado de una copia sale de `backup_controllers` (restauración por `restoreid`, con respaldo por `backupid` si el respaldo falló) y, sin controladores, de la existencia del curso.
- El lote de clonado es de 100 cursos (`cloneplan::MAX_ROWS`), para que la petición sea corta.
- «Solo vacías: nunca se pierde nada» (decisión del usuario). `mover::category_content` es la única regla (la usan `delete_empty_category`, el deshacer de una unificación y «Borrar categorías»): cursos, subcategorías, papelera, eventos, la categoría por defecto de solicitudes (`$CFG->defaultrequestcategory`) y, en su contexto, banco de contenidos, letras de calificación, categorías de preguntas, asignaciones de roles, permisos (`role_capabilities`), cohortes (`delete_full` las movería al padre), bloques (`parentcontextid`), archivos (`filename <> '.'`, también imágenes de la descripción), comentarios, filtros y marcos o plantillas de competencias. Una consulta indexada por tabla.
- Si `delete_full()` devuelve cursos borrados (carrera con la interfaz del núcleo), `delete_empty_category` lanza `error_notempty` y la transacción se revierte.
- «Borrar categorías» solo borra categorías vacías según esa regla. Las demás se omiten con un motivo (`hascourses`, `haschildren`, `hascontent`, `nopermission`, `notfound`), nunca se borran.
- `deleterunner` procesa de la más profunda a la más superficial y comprueba otra vez cada una justo antes de borrarla: un padre seleccionado cuyas hijas seleccionadas y vacías se borran en el mismo lote se borra también. La vista previa simula lo mismo con la lista de hijas que se irán (`$gone`).
- El permiso de borrar sigue a `core_course_category::can_delete()`: `moodle/category:manage` en la categoría y en su padre (sistema para el nivel superior). Sin él se omite (`nopermission`), no se rechaza el lote.
- `delete_apply` toma el candado en la capa external; `deleterunner::run` es una transacción delegada: si falla el registro (`record_delete`, gancho de prueba), se revierte todo.
- El diario `delete` guarda cada categoría en orden de borrado (la más profunda primero) con nombre crudo, idnumber, descripción y formato, padre, `afterid` (hermana anterior), `visible`, `visibleold` y tema si lo tiene. Deshacer recorre al revés: crea padres antes que hijas (mapa id viejo → id nuevo) y cada una antes de la que la seguía, así `afterid` siempre existe; si la hermana anterior ya no está, la categoría queda al final.
- Deshacer un borrado crea categorías con **ids nuevos** (lo dicen la interfaz y la documentación). Con `visibleold` 0 (oculta a propósito) se crea oculta; si no, se crea visible y hereda la visibilidad del padre, como en el núcleo.
- Deshacer un borrado no es O(n²): crea todas las categorías (padres antes que hijas), calcula el orden final de cada padre con la cadena de `afterid` y llama una vez `apply_category_order(..., false)` por padre y una sola vez `resort_categories_cleanup()`.
- El lote de borrado es de 200 categorías (`deleterunner::MAX_ITEMS`, `error_toomanydelete`); el JS lo comprueba antes de pedir nada y no parte el lote (un solo «Deshacer»). Tras borrar, solo las categorías borradas salen de la selección (`selection.removeCategories`).
- Conflictos de deshacer un borrado: el padre original (que no estaba en el lote) ya no existe, el idnumber lo usa otra categoría, o bajo ese padre ya hay una categoría con el mismo nombre normalizado (`sorter::normalize`, comparado en PHP: `conflict_nametaken`). `undo::check_delete` exige `moodle/category:manage` en cada padre original que no estaba en el lote.
- `cloner::resolve` recibe el índice `padre → nombre normalizado → id` (`cloner::index`, el primero gana, igual que el recorrido de antes); `cloneplan` y `clonerunner` lo construyen una vez por plan.
- En el destino se exige también `moodle/restore:restorecourse`, más estricto que el núcleo: sin él la restauración dejaría un curso «Copiando…» vacío tras el cron.
- Rendimiento (revisión de plataforma, eCampus): `mover::move_category` no repite `apply_category_order` cuando, tras `change_parent()` (que ya corre `fix_course_sortorder()`), los hermanos ya están en el orden pedido (siempre que va al final): las sortorders salen idénticas porque el núcleo las numera solo por el orden. Si hay que reordenar, se mantiene la segunda limpieza: con `$cleanup = false` quedarían las sortorders locales. `sorter` calcula la clave de cada nombre una vez antes de `usort` (`key`/`compare_keys`, mismo resultado que `compare`). `get_tree` lee las categorías una vez (`tree::overview`). Los nombres cortos ocupados se resuelven con `tree::taken_shortnames` (una consulta `IN` por 500 nombres; si la tanda tiene algún dueño, cada nombre sin coincidencia exacta se pregunta uno a uno, porque en MariaDB «abc» puede estar ocupado por el dueño de «ABC»: la misma respuesta que `update_course()`); lo usan `rename_preview` y `journal::conflicts_rename`, que además lee los elementos con `get_records_list`.
- «Ocultar todo» / «Mostrar todo» (`visibilitytree`, servicios `visibility_tree_preview` y `visibility_tree_apply`, acción del diario `visibilitytree`): la categoría, todas sus subcategorías a cualquier profundidad y todos sus cursos. «Ocultar todo» deja lo que dejaría `hide_raw()` en todo el subárbol: cada raíz elegida 0/0 (`visible`/`visibleold`, oculta a propósito, también si ya estaba oculta por su padre, 0/1); lo visible 0/1 (vuelve con el «Mostrar» del núcleo); lo oculto que volvería con ese «Mostrar» (0/1) pasa en silencio a 0/0, así sigue oculto; lo 0/0 no se toca. Esos arreglos silenciosos se escriben, se registran, entran en el hash y en los límites, pero no cuentan como «se ocultarán» (`plan()['changes']`; la vista previa devuelve aparte `total`). «Mostrar todo» no es el «Mostrar» del núcleo: lo oculto termina 1/1, también lo oculto a propósito; lo visible no se toca. Las elegidas dentro de otra elegida se omiten.
- Escritura masiva, no `hide()`/`show()` ni `course_change_visibility()` por elemento: `mover::set_visibility_states` hace un `UPDATE … WHERE id IN` por par (`visible`, `visibleold`) y 1000 ids, como `hide_raw()` escribe los cursos. Un `course_category_updated` por categoría cambiada (`mover::category_updated_events`), ningún `course_updated` por curso (el núcleo tampoco lo lanza al ocultar una categoría con sus cursos) y una sola purga de `changesincoursecat` y `changesincourse` al final (`mover::purge_visibility_caches`, en un `finally`, después de los commits), y luego los eventos, como `hide()`. Deshacer también purga y lanza los eventos después del `allow_commit()` de `journal::undo` (`visibilitytree_after_undo`); los otros deshacer usan funciones del núcleo que purgan por dentro y se dejan como están. `course_modinfo` no guarda la visibilidad del curso: no se reconstruye.
- Tandas de `visibilitytree::CHUNK` (200) elementos por transacción, primero categorías y luego cursos, con el registro del diario reescrito en la misma transacción: si una tanda falla después de otra confirmada, `partial_exception` y `visibility_tree_apply` responde `status: partial` con el `logid`; si falla la primera, el registro vacío se borra (`journal::discard`). Los nombres de las raíces para el resumen se leen una vez por escritura. Límite `MAX_CATEGORIES` (500) y `MAX_COURSES` (2000) de cambios, contados antes de cargar los cursos (`error_toomanyvisibilitytree`, en la vista previa y al aplicar). El hash firma solo ids (raíces, categorías y cursos que cambian, y el sentido).
- El candado lo toma `visibility_tree_apply`; `visibilitytree::run` comprueba `moodle/category:manage` en cada categoría que escribe y en su padre (sistema para el nivel superior; `mover::check_categories_and_parents`, como `can_change_visibility()`) y `moodle/course:visibility` en cada curso (`mover::check_courses` precarga los contextos), antes de escribir. El payload guarda de cada elemento el estado anterior y el escrito (`visible`, `visibleold`, `tovisible`, `tovisibleold`) y, de cada curso, su `category`; deshacer reescribe el anterior en una sola transacción y es conflicto si un elemento ya no tiene exactamente el estado escrito o si un curso está en otra categoría (lista de conflictos con tope `journal::CONFLICTS_SHOWN`, 20, y «y N más»). Deshacer sigue siendo atómico (decisión): si un docente volvió a mostrar su curso, se rechaza y la alternativa es «Mostrar todo». `undo::check_visibilitytree` exige los mismos permisos.

---

*Texto pendiente de revisión editorial.*
