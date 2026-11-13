# CEA-ORGANIZADOR

Plugin de Moodle (`tool_ceaorganizador`) del Centro de Innovación Pedagógica y Educación Digital (CEA), USB Cali. Permite organizar el árbol de categorías y cursos de un sitio: mover, ordenar automáticamente, renombrar por lotes, clonar semillas a un periodo y deshacer. Aparece en *Administración del sitio › Cursos › Organizador de categorías (CEA)*. Requiere Moodle 4.5 o superior y PHP 8.1 a 8.4.

Instalación: ver [INSTALACION.md](INSTALACION.md). Guía para desarrollo: [CLAUDE.md](CLAUDE.md).

## Para quién

Para los administradores de las plataformas de la USB (eCampus, Mcampus, Campus M) que hoy mueven categorías y cursos uno por uno en la pantalla nativa «Gestionar cursos y categorías». La herramienta es solo para administradores del sitio: la página aparece únicamente a quien puede configurar el sitio. La capacidad `tool/ceaorganizador:manage` es un control adicional; darla a otros usuarios no les da acceso a la página.

## Qué hace

- **Árbol** de categorías con cursos por página (100), buscador con ruta completa e indicador de categorías ocultas.
- **Arrastrar y soltar** con eventos de puntero propios, más teclado (Espacio, M, S, Shift+F10) y el menú «Mover a…».
- **Auto ordenar** con vista previa y recomendación cuando una categoría está desordenada. Los periodos se detectan solos por el nombre («2026-02», «2026 II») y van del más reciente al más antiguo; solo los patrones de semilla y de facultad se configuran en los ajustes.
- **Auto ordenar varias categorías** a la vez desde la barra de selección: una sola vista previa, un solo cambio y un solo «Deshacer».
- **Ocultar y mostrar cursos** desde el menú del curso o, para varios (en lotes de 100), desde la barra de selección; se puede deshacer.
- **Ocultar todo / Mostrar todo**: una categoría con todas sus subcategorías y todos sus cursos, desde el menú de la categoría o para las categorías seleccionadas; confirma con los conteos («Se ocultarán 12 categorías y 340 cursos»), escribe por tandas (hasta 500 categorías y 2000 cursos por operación) y se puede deshacer al estado exacto anterior.
- **Renombrar en lote** categorías y cursos (reemplazar, limpiar, prefijo o sufijo, tipo oración o título), con vista previa y edición manual.
- **Sugerir correcciones** de nombres de categorías y cursos con cuatro reglas (formato, glosario, tilde y variante singular/plural), vista previa de hasta 2000 filas, aplicación en lotes de 100 y deshacer. Solo cambia nombres completos; nunca los nombres cortos.
- **Unificar categorías**: mueve a una sola categoría de semillas los cursos de varias categorías de origen (hasta 1000), en espejo; los cursos repetidos (mismo nombre, mismo nombre sin copia/periodo/grupo o mismo nombre corto base) se agrupan y por defecto se conserva el más reciente y los demás van a una carpeta oculta «Repetidos» bajo el destino; deshacer devuelve los cursos a su categoría original. Nunca borra cursos ni categorías de origen: las que quedan vacías se listan.
- **Quitar repetidos** de una categoría y todas sus subcategorías (cada una comparada solo consigo misma), con las mismas reglas: vista previa por grupos, se elige cuál se conserva y qué grupos se tocan; los demás van a una carpeta oculta «Repetidos» bajo la categoría elegida (revisa hasta 5000 cursos y mueve hasta 1000 por lote); deshacer los devuelve. Un número de nivel o un grupo nunca se ignoran: «Cálculo I» y «Cálculo II», `MAT-1` y `MAT-2` o «… G1» y «… G2» son cursos distintos; se conserva un curso visible antes que uno oculto, luego el que tiene más actividades y luego el más reciente. Los «posibles repetidos» (nombres casi iguales que las reglas seguras no juntan) se preguntan con Sí/No. Con categorías marcadas en el árbol, «Quitar repetidos» y «Unificar categorías» (hacia «Semillas») se aplican en un clic tras una sola pregunta.
- **Ver los repetidos en el árbol** sin abrir ningún panel: cada curso de un grupo de repetidos lleva «Base» (el que se conservaría) o «Repetido», y los posibles repetidos «¿Repetido?», con el motivo y el curso base al pasar el puntero; cada categoría muestra «N repetidos» de todo su subárbol (un clic abre el camino hasta ellos) y la barra superior, «M cursos repetidos en el sitio» con [Revisar]. Solo lectura.
- **Borrar categorías** vacías (sin cursos, subcategorías ni contenido o configuración propia: roles, permisos, cohortes, bloques, archivos…; nunca se pierde nada; hasta 200 por lote), una desde su menú o varias desde la barra de selección, con confirmación que lista lo que se borra y lo que se conserva con su motivo; deshacer las vuelve a crear con ids nuevos.
- **Selección** múltiple de categorías y cursos.
- **Clonar semillas a un periodo** (hasta 100 cursos por lote): vista previa, destino en espejo bajo el periodo (sin la raíz de semillas y, si se quiere, dentro de una carpeta como «Facultades»), copias en segundo plano con panel de avance; los clonados no se deshacen.
- **Historial y deshacer** (90 días); deshacer se rechaza si algo cambió después.

## Arquitectura

- `classes/local/sorter.php` y `renamer.php` son puros: reciben y devuelven datos, sin base de datos.
- `mover.php` y `journal.php` son la única puerta de escritura; usan la API del núcleo para que se disparen eventos y se limpien cachés.
- `guard.php` reúne el candado y el rollback; los permisos nativos se comprueban en `mover::check_category` y `mover::check_course` y en los servicios web; `tree.php` y `sortplan.php` leen el árbol y arman el plan de orden con su firma; `tree.php` también cuenta los repetidos de cada categoría (`dupcount`, `dupcounttotal`) y marca los cursos de una categoría (`duplicate_marks`) con las reglas de `duplicates.php`.
- `suggester.php` (reglas formato, glosario, tilde y variante; puro) arma las sugerencias; se aplican por `rename_apply`.
- `mergeplan.php` (vista previa de la unificación, con su firma y los repetidos; no escribe) y `mergerunner.php` (aplica todo o nada, registra en el historial y lo deshace) forman la unificación de semillas; las categorías nuevas y el borrado de vacías pasan por `mover.php`.
- `duplicates.php` (puro) tiene las reglas de cursos repetidos (`key_name`, `key_shortname`, `groups`, `candidates` para los posibles repetidos); las usan `mergeplan.php` y `dedupeplan.php` (vista previa de «Quitar repetidos»). `batchrunner.php` es el paso de escritura común de `mergerunner.php` y `deduperunner.php`: categorías y registro en una transacción corta y luego los movimientos por destino, de a 100, cada tanda registrada al confirmarse.
- `deleterunner.php` forma «Borrar categorías»: la vista previa (`plan`, no escribe) y el borrado (`run`, todo o nada, con su registro). La regla de qué es una categoría vacía es una sola, `mover::category_content`.
- `visibilitytree.php` forma «Ocultar todo» / «Mostrar todo»: la vista previa (`plan`, cuenta lo que cambia y firma ids) y la escritura por tandas (`run`), con su registro deshacible.
- `cloner.php` (nombres con periodo y destino en espejo; puro), `cloneplan.php` (vista previa con su firma y los errores por fila) y `clonerunner.php` (crea las categorías, encola las copias y registra el lote) forman el clonado.
- `classes/external/*.php`: diez servicios web de organización, tres del clonado (`clone_preview`, `clone_apply`, `clone_status`), `set_visibility` (ocultar y mostrar cursos), `visibility_tree_preview` y `visibility_tree_apply` (ocultar o mostrar todo), `suggest_preview` (sugerencias), tres de la unificación (`merge_preview`, `merge_apply`, `create_seedroot`) y dos de «Quitar repetidos» (`dedupe_preview`, `dedupe_apply`), uno por clase, sobre `\core_external`.
- `amd/src/`: `organizer` (arranque), `api`, `treemodel` y `treeview`, `dragdrop`, `dropzones` y `keyboard`, `movepicker` y `mover`, `sortpanel`, `renamepanel` e `historypanel`, `suggestpanel` (sugerencias), `mergepanel` (unificación), `dedupepanel` (quitar repetidos) y `oneclick` (los dos en un clic desde la selección), `selection`, `clonepanel` y `cloneprogress` (clonado y avance), `visibility` (ocultar y mostrar), `visibilitytree` (ocultar o mostrar todo), `toast`, `dialog` y `text`. Se compilan a `amd/build`.

## Desarrollo

```bash
npm install
npm test               # pruebas JS (node:test + jsdom)
npm run check-strings  # cadenas de idioma completas, iguales y ordenadas en es y en
npm run build          # compila amd/build tras tocar amd/src
npm run package        # dist/CEA-ORGANIZADOR.zip
```

Las pruebas PHPUnit corren solo en GitHub Actions (no hay Moodle local); en el equipo se comprueban con `php -l`. El verificador de código de Moodle (`moodlehq/moodle-cs`) se instala con Composer y se corre con `vendor/bin/phpcs --standard=moodle --extensions=php .`; debe dar 0 errores y 0 avisos. Los pasos exactos para Windows están en `lecciones.md` de la skill `creating-cea-moodle-plugins`.

---

*Texto pendiente de revisión editorial.*
