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
- Mover a un padre oculto oculta el elemento (comportamiento del núcleo). `journal` guarda `visible` en `from`/`to`, y deshacer restaura la visibilidad solo si el movimiento la cambió; si la visibilidad actual no es la que dejó la operación, es un conflicto.
- Deshacer es atómico y se rechaza si algo cambió después (conflictos listados). `guard::rollback` es el único helper de rollback; `mover` y `journal` lo usan.
- En las pruebas de rollback hay que llamar `$this->preventResetByRollback()` justo después de `resetAfterTest()`: en PostgreSQL `advanced_testcase` envuelve cada prueba en una transacción delegada y el rollback anidado solo marca `force_rollback`.
- Los servicios web usan `\core_external\external_api` y sus clases de parámetros, nunca los alias globales (no existen en 5.3).
- Los módulos JS que necesitan `core/*` lo reciben inyectado (`api.setTransport`, proveedor de cadenas) para que `node:test` corra sin Moodle. Sin librerías externas: el arrastre usa eventos de puntero propios.
- El candado (`guard::lock()`) vive en la capa `external`, no en `mover`. El renombrado se limita a 500 elementos por lote.
- `suggester` nunca tilda palabras de diacrítico ambiguo (stoplist: si, mas, el, tu, te, se, de, mi, solo, aun, que, como, cuando, donde, quien, cual, cuanto, este, esta, ese, esa, aquel, o): una tilde gramatical equivocada es peor que ninguna; lo que falte va al glosario.
- La regla «variante» solo empareja nombres hermanos idénticos salvo una palabra en la misma posición (singular/plural), nunca palabras sueltas entre hermanos.
- «variante» copia la palabra preferida tal como está escrita en el sitio (con su tilde); no construye sufijos.
- Deshacer una unificación solo borra categorías creadas que no tengan nada: cursos, subcategorías, papelera de reciclaje, banco de contenidos, eventos, letras de calificación ni categorías de preguntas (cualquiera, incluso una «top» vacía; `mover::delete_empty_category`). Las que tienen contenido se conservan.
- `mergeplan` rechaza orígenes anidados (uno dentro de otro, o el destino dentro/fuera de un origen); `cloneplan::path_of` y `format` son públicos para reutilizarlos.
- Los elementos del diario de unificación llevan `fromvisible`/`tovisible`: deshacer restaura la visibilidad solo si el movimiento la cambió, igual que mover.
- Con carpeta bajo el periodo, los cursos que no están bajo una raíz de semillas también van a la carpeta (`container + []`): la carpeta es donde viven las facultades del periodo.
- El destino en espejo (clonar y unificar) deja fuera la raíz de semillas (o la categoría de origen): la ruta empieza en la facultad.
- `period.suggestContainer` ignora las hijas del periodo cuyo nombre contiene un periodo (`parsePeriod`), porque el servidor rechaza esa carpeta.
- El orden de cursos de una categoría se rechaza si supera `maxcoursesincategory` (`error_toomanycourses`).
- `clonerunner` llama a `create_copy` después del commit de las categorías, una transacción delegada por copia (un fallo no deja un esqueleto ni revierte las demás).
- Las copias en curso cuentan como ya tomadas (ventana de 30 días, `cloneplan::INFLIGHT_DAYS`): relanzar el lote antes de que corra el cron no duplica.
- La acción `clone` no tiene deshacer (`error_cannotundoclone`): deshacerla borraría cursos.
- El estado de una copia sale de `backup_controllers` (restauración por `restoreid`, con respaldo por `backupid` si el respaldo falló) y, sin controladores, de la existencia del curso.
- El lote de clonado es de 100 cursos (`cloneplan::MAX_ROWS`), para que la petición sea corta.
- «Borrar categorías» solo borra categorías vacías según `mover::category_content` (la misma regla que `delete_empty_category`; no se duplica). Las demás se omiten con un motivo (`hascourses`, `haschildren`, `hascontent`, `nopermission`, `notfound`), nunca se borran.
- `deleterunner` procesa de la más profunda a la más superficial y comprueba otra vez cada una justo antes de borrarla: un padre seleccionado cuyas hijas seleccionadas y vacías se borran en el mismo lote se borra también. La vista previa simula lo mismo con la lista de hijas que se irán (`$gone`).
- El permiso de borrar sigue a `core_course_category::can_delete()`: `moodle/category:manage` en la categoría y en su padre (sistema para el nivel superior). Sin él se omite (`nopermission`), no se rechaza el lote.
- `delete_apply` toma el candado en la capa external; `deleterunner::run` es una transacción delegada: si falla el registro (`record_delete`, gancho de prueba), se revierte todo.
- El diario `delete` guarda cada categoría en orden de borrado (la más profunda primero) con nombre crudo, idnumber, descripción y formato, padre, `afterid` (hermana anterior), `visible`, `visibleold` y tema si lo tiene. Deshacer recorre al revés: crea padres antes que hijas (mapa id viejo → id nuevo) y cada una antes de la que la seguía, así `afterid` siempre existe; si la hermana anterior ya no está, la categoría queda al final.
- Deshacer un borrado crea categorías con **ids nuevos** (lo dicen la interfaz y la documentación). Una categoría oculta solo porque su padre estaba oculto (`visible` 0, `visibleold` 1) se crea visible bajo el padre oculto, para que vuelva a verse con él. No se recuperan roles, permisos ni archivos de la descripción.
- Conflictos de deshacer un borrado: el padre original (que no estaba en el lote) ya no existe, o el idnumber lo usa otra categoría. `undo::check_delete` exige `moodle/category:manage` en cada padre original que no estaba en el lote.
- En el destino se exige también `moodle/restore:restorecourse`, más estricto que el núcleo: sin él la restauración dejaría un curso «Copiando…» vacío tras el cron.

---

*Texto pendiente de revisión editorial.*
