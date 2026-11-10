# CEA-ORGANIZADOR · Clonar semillas a un periodo · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. REQUIRED REFERENCE: skill `creating-cea-moodle-plugins` (lecciones.md) y skill `cea-branding`.

**Goal:** clonar en lote cursos semilla a un periodo, con destino en espejo, nombres y fechas del periodo, usando la copia de cursos de Moodle en segundo plano.

**Architecture:**
- Una clase pura nueva, `classes/local/cloner.php`, se encarga de la etiqueta del periodo, los nombres y la ruta en espejo.
- Una clase con base de datos, `classes/local/cloneplan.php`, arma la vista previa con su firma.
- La escritura (crear categorías, registrar en el historial y encolar con `\copy_helper::create_copy`) vive en `classes/local/clonerunner.php`.
- Tres funciones web nuevas.
- Un panel JS (`clonepanel.js`) y un panel de progreso (`cloneprogress.js`).

**Tech Stack:** el del plugin (PHP 8.0+, Moodle 4.2–5.1, `core_external`, AMD, node:test, PHPUnit, moodle-plugin-ci). Para copiar se usan `\copy_helper` y `\core\task\asynchronous_copy_task`.

**Spec:** `docs/superpowers/specs/2026-11-10-cea-organizador-clonado-design.md`. La especificación base sigue vigente.

## Global Constraints

- Todas las de `docs/superpowers/plans/2026-11-10-cea-organizador.md`: componente, servicios con `\core_external`, Bootstrap 4 y 5, idiomas `es`/`en` en orden alfabético, nada de `innerHTML`, módulos AMD importados por nombre, `final` + `CoversClass` + `@covers`, moodle-cs 0/0, LF.
- `supported = [402, 501]`. `$plugin->version` sube en la Tarea 4, porque hay servicios nuevos.
- Copias: `visible = 0`, `userdata = 0`, ningún rol conservado e `idnumber = ''`.
- Nombres: completo + « » + periodo, corto + «-» + periodo. Si el nombre ya trae un periodo, se reemplaza. Máximo 254 caracteres.
- Límite: 300 cursos por lote (`toomanyclones`). El estado se consulta cada 15 s; el aviso de cron sale a los 10 minutos sin avance.
- Permisos por curso: `moodle/backup:backupcourse`, `moodle/restore:restorecourse` y `moodle/course:create`, todos en el contexto de la semilla. Además, `moodle/course:create` en la categoría destino y `moodle/category:manage` en el padre de cada categoría nueva.
- La acción `clone` del historial no se puede deshacer (`error_cannotundoclone`).
- Toda prueba que verifique una reversión llama a `preventResetByRollback()`. Toda prueba que llame a `core_course_category::get()` usa `MUST_EXIST, true`.

## Review Focus

1. **Relanzar el mismo lote:** no crea cursos duplicados (`alreadycloned`). Prueba en la Tarea 2: `test_relaunch_skips_already_cloned`.
2. **Semilla sin raíz de semillas** (un curso suelto seleccionado): la copia va a la categoría del periodo y la vista previa lo dice. Prueba en la Tarea 1: `test_mirror_without_seed_root`.
3. **Periodo destino dentro de las semillas:** se rechaza. Prueba en la Tarea 2: `test_destination_inside_seeds_rejected`.
4. **Nombre largo:** si al agregar el periodo se superan los 254 caracteres, es un error y no se trunca. Prueba en la Tarea 1: `test_name_too_long_is_error`.
5. **Registros de copia ya limpiados por Moodle:** el estado se deduce del curso nuevo, sin fallar. Prueba en la Tarea 4: `test_status_without_controllers`.

---

### Task 1: Reglas puras de clonado (`cloner`)

**Files:** Create `classes/local/cloner.php`, Test `tests/local/cloner_test.php`

**Interfaces:**
- **Consume:** `sorter::parse_period()`, `sorter::normalize()` y `sorter::classify()`.
- **Produce:**
  - `cloner::period_label(string $periodname): ?string`: «2027-01» a partir de «2027-01», «2027 -1» o «2027 I»; null si no hay periodo.
  - `cloner::new_fullname(string $name, string $label): string` y `cloner::new_shortname(string $short, string $label): string`: reemplazan el periodo si existe; si no, lo agregan con « » o «-».
  - `cloner::mirror(array $categories, int $categoryid, array $patterns): array`. `$categories` es un mapa id → `['id', 'parent', 'name']`. Devuelve `['root' => ?int, 'relative' => string[]]`: la cadena de nombres desde la raíz de semillas, incluida, hasta la categoría. Si no hay raíz de semillas, `root` es null y `relative` es `[]`.
  - `cloner::resolve(array $categories, int $periodid, array $relative): array`. Devuelve `['existing' => int, 'missing' => string[]]`: el id de la categoría más profunda que ya existe bajo el periodo, comparando con `normalize`, y los nombres que faltan crear en orden.

- [ ] **Step 1: Pruebas**

```php
public function test_period_label(): void {
    $this->assertSame('2027-01', cloner::period_label('2027-01'));
    $this->assertSame('2027-01', cloner::period_label('2027 -1'));
    $this->assertSame('2027-02', cloner::period_label('2027 II'));
    $this->assertNull(cloner::period_label('Facultades'));
}
public function test_names_add_or_replace_period(): void {
    $this->assertSame('Cálculo I 2027-01', cloner::new_fullname('Cálculo I', '2027-01'));
    $this->assertSame('Cálculo I 2027-01', cloner::new_fullname('Cálculo I 2026-02', '2027-01'));
    $this->assertSame('CALC1-2027-01', cloner::new_shortname('CALC1', '2027-01'));
    $this->assertSame('CALC1-2027-01', cloner::new_shortname('CALC1-2026-02', '2027-01'));
}
public function test_name_too_long_is_error(): void {
    $this->assertGreaterThan(254, \core_text::strlen(cloner::new_fullname(str_repeat('a', 250), '2027-01')));
}
public function test_mirror_and_resolve(): void {
    $cats = [1 => ['id' => 1, 'parent' => 0, 'name' => 'Facultades'],
             2 => ['id' => 2, 'parent' => 1, 'name' => 'Facultad de Ingeniería'],
             3 => ['id' => 3, 'parent' => 2, 'name' => 'Programa Sistemas'],
             9 => ['id' => 9, 'parent' => 0, 'name' => '2027-01'],
             10 => ['id' => 10, 'parent' => 9, 'name' => 'Facultades'],
             11 => ['id' => 11, 'parent' => 10, 'name' => 'Facultad de Ingenieria']];
    $m = cloner::mirror($cats, 3, sorter::default_patterns_parsed());
    $this->assertSame(['root' => 1, 'relative' => ['Facultades', 'Facultad de Ingeniería', 'Programa Sistemas']], $m);
    $this->assertSame(['existing' => 11, 'missing' => ['Programa Sistemas']], cloner::resolve($cats, 9, $m['relative']));
}
public function test_mirror_without_seed_root(): void {
    $cats = [5 => ['id' => 5, 'parent' => 0, 'name' => 'CEA']];
    $this->assertSame(['root' => null, 'relative' => []], cloner::mirror($cats, 5, sorter::default_patterns_parsed()));
}
```

- [ ] **Step 2:** Implementar.
  - `period_label`: usa `parse_period` y le da formato `sprintf('%d-%02d')`.
  - Reemplazar: elimina el periodo existente que `parse_period` encuentra (expresión con el mismo patrón, junto con el separador previo) y luego agrega el nuevo.
  - Comprobar con un script desechable del scratchpad con stubs (`php -l` + script).
- [ ] **Step 3:** moodle-cs 0/0. Commit «cloner: reglas puras de clonado».

### Task 2: Vista previa del clonado (`cloneplan`)

**Files:** Create `classes/local/cloneplan.php`, Test `tests/local/cloneplan_test.php`

**Interfaces:**
- **Consume:** la Tarea 1, `tree::courses()` o consultas directas, y `sorter::patterns()`.
- **Produce:** `cloneplan::build(array $items, int $periodid, string $label, int $startdate, int $enddate): array`.
  - `$items` son `[['type' => 'category'|'course', 'id' => int]]`. Una categoría se expande a todos sus cursos y los de sus descendientes, excepto `SITEID`.
  - Devuelve `['rows' => [...], 'total' => int, 'hash' => string]`.
  - Cada fila: `['courseid', 'fullname', 'shortname', 'destpath' (texto con ' / '), 'destcategoryid' (int, 0 si se creará), 'newcategories' (string[] de rutas que se crearán), 'newfullname', 'newshortname', 'error' => ?string]`.
  - **Errores posibles:** `nopermission`, `toolong`, `shortnametaken`, `shortnameduplicate`, `alreadycloned`, `destinsideseeds`.
  - **Firma:** `hash` es el sha1 de `[courseid, destpath, newshortname]` por fila, junto con `periodid`, `startdate` y `enddate`.
  - **Rechazos:** `invalid_parameter_exception` si `enddate <= startdate`, si `label` no tiene forma de periodo, o si hay más de 300 filas (`toomanyclones`).
  - **Permisos de copia:** se calculan con `has_all_capabilities(\core_course\management\helper::get_course_copy_capabilities(), context_course)`. Si faltan, la fila queda en `nopermission`; no se lanza excepción.

- [ ] **Step 1: Pruebas** (`resetAfterTest`, generador):
  - `test_mirror_destination_and_new_categories`
  - `test_relaunch_skips_already_cloned`: existe un curso con el nombre corto nuevo en la categoría destino, así que la fila es `alreadycloned`.
  - `test_shortname_taken_and_duplicate`
  - `test_destination_inside_seeds_rejected`: el periodo es descendiente de la raíz de semillas.
  - `test_category_item_expands_descendants`
  - `test_nopermission_row`: usuario sin `moodle/backup:backupcourse`.
  - `test_hash_changes_with_dates`
- [ ] **Step 2:** Implementar. Las consultas van por lotes: todas las categorías una vez y los cursos con `get_in_or_equal`. Nada de una consulta por curso, salvo la existencia del nombre corto, que se hace con una sola consulta `IN`.
- [ ] **Step 3:** `php -l`, moodle-cs 0/0, check-strings. Commit «cloneplan: vista previa del clonado».

### Task 3: Ejecutar el clonado y registrarlo (`clonerunner`, `journal`)

**Files:** Create `classes/local/clonerunner.php`. Modify `classes/local/journal.php` (acción `clone`). Test `tests/local/clonerunner_test.php`.

**Interfaces:**
- **Consume:** `cloneplan::build`, `guard::lock()`, `guard::rollback()`, `journal::record()`, `\copy_helper::process_formdata()` y `create_copy()`.
- **Produce:** `clonerunner::run(array $items, int $periodid, string $label, int $startdate, int $enddate, array $edits, string $hash): array`.
  - `$edits` es `[courseid => ['newfullname', 'newshortname']]`.
  - Devuelve `['status' => 'ok'|'stale', 'batchid' => int, 'queued' => int, 'skipped' => int]`.
  - **Pasos dentro del candado:**
    1. Reconstruir el plan y comparar la firma; si no coincide, devolver `stale`.
    2. Aplicar las ediciones y volver a validar unicidad y longitud.
    3. Abrir una transacción que crea las categorías que faltan (`moodle/category:manage` en cada padre) y registra `journal::record('clone', 'Clonado a <label> · N cursos', payload)`. El `payload` lleva `{periodid, label, startdate, enddate, categories: [ids creados], items: [{source, newfullname, newshortname, destcategoryid}]}`.
    4. Hacer commit.
    5. Encolar una copia por fila sin error: `create_copy(process_formdata((object)[courseid, fullname, shortname, category, visible => 0, startdate, enddate, idnumber => '', userdata => 0]))`.
    6. Guardar en `items[i]` del payload `backupid`, `restoreid` y `newcourseid`, este último leído de `backup_controllers.itemid` con `backupid = restoreid`. Se actualiza la fila del historial con `$DB->set_field`.
  - `journal`: `record()` acepta `clone`. `undo()` sobre una acción `clone` lanza `moodle_exception('error_cannotundoclone')`.

- [ ] **Step 1: Pruebas**
  - `test_run_queues_one_task_per_course`: comprobar con `\core\task\manager::get_adhoc_tasks('\core\task\asynchronous_copy_task')`, y que se crearon las categorías que faltaban.
  - `test_run_stale_hash`
  - `test_end_to_end_copy`: ejecutar con `$this->runAdhocTasks('\core\task\asynchronous_copy_task')` y verificar que el curso nuevo está oculto, tiene `fullname` y `shortname` nuevos y la `startdate` indicada, no tiene matrículas de usuarios y conserva la actividad que tenía la semilla.
  - `test_undo_clone_rejected`
  - `test_category_creation_rolls_back_on_error`: con `preventResetByRollback`, si un padre no tiene permiso, no se crea ninguna categoría y no se encola nada.
- [ ] **Step 2:** Implementar. Antes de encolar se hacen las comprobaciones que espera `\copy_helper::process_formdata`; leer su lista de campos obligatorios en el código de 4.2 y 5.1. También `\core_php_time_limit::raise()`.
- [ ] **Step 3:** `php -l`, moodle-cs 0/0, check-strings (cadenas `error_cannotundoclone`, `error_toomanyclones` y `summary_clone`). Commit «clonado: crear categorías, registrar y encolar copias».

### Task 4: Funciones web del clonado

**Files:** Create `classes/external/clone_preview.php`, `clone_apply.php` y `clone_status.php`. Modify `db/services.php` y `version.php` (sube `version`). Test `tests/external/clone_external_test.php`.

**Interfaces:**
- **`clone_preview(items[{type, id}], periodid, periodlabel, startdate, enddate)`** → la salida de `cloneplan::build` (`error` con `NULL_ALLOWED`; `newcategories` es una lista de `PARAM_TEXT`).
- **`clone_apply(... + rows[{courseid, newfullname, newshortname}], hash)`** → `{status, batchid, queued, skipped}`.
- **`clone_status(batchid)`** → `{rows: [{source, newcourseid, newfullname, status (pending|running|done|failed), url}], pending: int, done: int, failed: int}`.
  - **El estado sale de `backup_controllers`** (por `backupid` = `restoreid` del payload):
    - `STATUS_FINISHED_OK`: `done`.
    - `STATUS_FINISHED_ERR`: `failed`.
    - `STATUS_EXECUTING`: `running`.
    - Cualquier otro: `pending`.
  - **Si no hay registro:** se considera `done` si el curso nuevo existe y su `shortname` es el esperado; si no, `failed`.
- Todas validan el contexto de sistema y `tool/ceaorganizador:manage`.

- [ ] **Step 1: Pruebas:**
  - `clean_returnvalue` para las tres funciones.
  - `test_status_progresses_after_running_tasks`
  - `test_status_without_controllers`: borrar los registros de `backup_controllers` y comprobar que el estado se deduce del curso.
  - `test_apply_requires_capability`
- [ ] **Step 2:** Implementar.
- [ ] **Step 3:** `php -l`, moodle-cs 0/0. Commit «servicios web del clonado».

### Task 5: Panel de clonado y de progreso (JS)

**Files:** Create `amd/src/clonepanel.js` y `amd/src/cloneprogress.js`. Modify `amd/src/api.js` (`clonePreview`, `cloneApply`, `cloneStatus`), `organizer.js` (entrada en el menú del nodo de una categoría y en la barra de selección, «Clonar a periodo…»), `historypanel.js` (sin Deshacer para `clone`), `styles.css`, `lang/*`. Test `tests/js/clonepanel.test.mjs` y `tests/js/cloneprogress.test.mjs`.

**Interfaces:**
- **Consume:** `dialog.js`, `treemodel` (para listar las categorías `periodo`, que se clasifican en el cliente con la misma expresión que `parse_period`, o con `model.filter`), `text.decodeEntities` (solo para rutas y nombres que vienen formateados) y `toast`.
- **Produce:**
  - `clonepanel.open({items})`.
    - **Formulario:** selector de periodo (las categorías cuyo nombre tiene periodo, de cualquier nivel, de la más reciente a la más antigua, con su ruta), etiqueta del periodo editable (sugerida), y fechas `input[type=date]` de inicio y fin, validadas.
    - **«Vista previa»:** tabla con semilla, destino (etiqueta «Nueva» en las categorías que se crearán), nombre completo y nombre corto editables, y estado (`cloneerror_<code>`). Las filas con error quedan deshabilitadas.
    - **«Clonar N cursos»** llama a `cloneApply`. Si responde `stale`, aparece un aviso y se recarga la vista previa. Si responde `ok`, se abre `cloneprogress.open(batchid)`.
  - `cloneprogress.open(batchid, {interval = 15000, cronwarning = 600000})`.
    - Consulta `cloneStatus` hasta que `pending` llegue a 0.
    - Muestra contadores y una lista con enlaces a los cursos nuevos.
    - Sin avance en 10 minutos, muestra la cadena `cronwarning`.
    - Al cerrar el panel se detiene la consulta.
  - Las fechas se envían como timestamp en segundos, a medianoche hora local.

- [ ] **Step 1: Pruebas (jsdom, temporizadores falsos):**
  - Las filas con error se excluyen al aplicar.
  - Las ediciones viajan en `rows`.
  - `stale` recarga la vista previa.
  - El sondeo se detiene cuando `pending` llega a 0.
  - El aviso de cron aparece a los 10 minutos sin avance.
  - Un nombre con `<b>` se muestra como texto.
- [ ] **Step 2:** Implementar. Cadenas, en orden alfabético:
  - Generales: `clonetoperiod`, `cloneperiod`, `clonelabel`, `clonestart`, `cloneend`, `clonepreview`, `cloneapply`, `clonenew`, `cloneprogress`, `clonedone`, `clonefailed`, `clonepending`, `cronwarning`.
  - Errores de fila: `cloneerror_nopermission`, `cloneerror_toolong`, `cloneerror_shortnametaken`, `cloneerror_shortnameduplicate`, `cloneerror_alreadycloned`, `cloneerror_destinsideseeds`.
- [ ] **Step 3:** `npm test`, check-strings y build. Commit «panel de clonado y progreso».

### Task 6: Documentación y entrega

**Files:** Modify `INSTALACION.md`, `README.md` y `CLAUDE.md`.

- [ ] **Step 1:** Escribir la sección «Clonar semillas a un periodo» de `INSTALACION.md`.
  - **Requisito:** el cron de Moodle activo, con la tarea `\core\task\asynchronous_copy_task`.
  - **Cómo se usa:** el flujo de clonado.
  - **Qué se copia y qué no.**
  - **Nombres y fechas.**
  - **Sin deshacer:** cómo borrar desde Moodle una copia que sobre.
  - **Límite:** 300 cursos por lote.
  - **Relanzar el mismo lote** no duplica.
- [ ] **Step 2:** Agregar a `README.md` la función nueva y a `CLAUDE.md` las decisiones (`create_copy` después del commit, `clone` sin deshacer, estado por `backup_controllers`).
- [ ] **Step 3:** Verificación completa: `npm test`, check-strings, `php -l`, moodle-cs 0/0 y `npm run package` (ZIP con `classes/local/clone*.php`). Commit «documentación del clonado».

---

## Ampliación aprobada el 2026-11-10: ordenar varias y ocultar/mostrar cursos

### Task 7: Auto ordenar varias categorías seleccionadas

**Files:**
- Modify: `classes/local/sortplan.php`, `classes/external/sort_preview.php`, `classes/external/sort_apply.php`, `amd/src/api.js`, `amd/src/sortpanel.js`, `amd/src/organizer.js` (barra de selección), `lang/*`.
- Test: `tests/local/sortplan_test.php` (o el archivo de pruebas de sortplan que ya exista) y `tests/js/sortpanel.test.mjs`.

**Interfaces:**
- **`sortplan::build_many(int[] $categoryids, string $scope, bool $recursive): array`**
  - Devuelve la misma forma que `build`: `['changes' => [...], 'hash' => string]`.
  - **Deduplicación:** ids repetidos se descartan. Con `recursive`, también se descarta todo id que sea descendiente de otro seleccionado.
  - **Unión de planes:** sin cambios de padre duplicados. Si dos ids comparten padre, ese padre aparece una sola vez.
  - **Firma:** se calcula sobre la unión, por ids.
  - `build($id, ...)` sigue igual: es `build_many([$id], ...)`.
- **`sort_preview` y `sort_apply`** aceptan un parámetro opcional `categoryids` (lista `PARAM_INT`, `VALUE_DEFAULT []`).
  - Si viene con elementos, se usa en lugar de `categoryid`.
  - Si se combina con un `categoryid` distinto de 0, se rechaza con `invalid_parameter_exception`.
  - `sort_apply` sigue registrando **una sola** entrada `sort` en el historial y conserva la limpieza diferida (Regla 24: cursos antes que categorías y una sola `resort_categories_cleanup(true)`).
- **JS:**
  - `api.sortPreview` y `api.sortApply` pasan `categoryids` cuando están.
  - `sortpanel.open({categoryids, paths})` muestra «N categorías seleccionadas».
  - La barra de selección tiene el botón «Auto ordenar seleccionadas» (`autosortselected`), habilitado solo si hay al menos una categoría seleccionada.

- [ ] **Step 1: Pruebas.**
  - PHPUnit:
    - `test_build_many_dedupes_descendants`
    - `test_build_many_union_same_hash_as_single`: con un solo id, la firma es la misma que con `build`.
    - `test_sort_apply_many_records_one_entry`
    - `test_sort_apply_rejects_both_params`
  - JS: el panel envía `categoryids`, y el botón queda deshabilitado sin categorías seleccionadas.
- [ ] **Step 2:** Implementar.
- [ ] **Step 3:** Verificar con `npm test`, check-strings, build, `php -l` y moodle-cs 0/0. Commit «auto ordenar varias categorías seleccionadas».

### Task 8: Ocultar y mostrar cursos, con deshacer

**Files:**
- Modify: `classes/local/mover.php`, `classes/local/journal.php`, `db/services.php`, `version.php`, `amd/src/api.js`, `amd/src/treeview.js` (menú del curso), `amd/src/organizer.js` (barra de selección), `amd/src/historypanel.js`, `lang/*`.
- Create: `classes/external/set_visibility.php`.
- Test: `tests/local/mover_test.php`, `tests/local/journal_test.php`, `tests/external/external_test.php` y una prueba JS nueva o ampliada.

**Interfaces:**
- **`mover::set_course_visibility(int $id, bool $visible): void`**
  - Usa `course_change_visibility($id, $visible)` de `course/lib.php`, el mismo en 4.2 y 5.1. Comprobarlo antes de usarlo.
  - Rechaza `SITEID`.
- **`journal`, acción nueva `visibility`:**
  - payload `{items: [{id, before: 0|1, after: 0|1}]}`;
  - **conflicto:** el `visible` actual es distinto de `after`; se informa `conflict_visibility` con el nombre;
  - **restaurar:** volver a `before`.
- **Servicio `tool_ceaorganizador_set_visibility(courseids[], visible)`, de escritura:**
  - Abre con el candado `guard::lock()`, valida el contexto de sistema y comprueba `manage`.
  - Para cada curso exige `mover::check_course($id, 'moodle/course:visibility')`.
  - Admite como máximo 500 cursos (`error_toomany`).
  - Omite los cursos que ya tienen esa visibilidad.
  - Trabaja en **una** transacción y registra **una** entrada `visibility` en el historial, solo con los cursos que cambiaron.
  - Devuelve `{changed: int, skipped: int, logid: int}`, con `logid` 0 si no cambió nada.
- **Interfaz:**
  - **Menú del curso:** «Ocultar» o «Mostrar», según su estado.
  - **Barra de selección:** «Ocultar seleccionados» y «Mostrar seleccionados», habilitados si hay cursos seleccionados.
  - **Después de aplicar:** aviso con Deshacer (se usa `mover.undo`) y actualización de la etiqueta «Oculta» de las filas.
  - **Historial:** las entradas `visibility` se pueden deshacer.
- **Cadenas** (orden alfabético):
  - Interfaz: `hidecourse`, `showcourse`, `hideselected`, `showselected`, `visibilitychanged`.
  - Historial: `summary_hide`, `summary_show`.

- [ ] **Step 1: Pruebas.**
  - PHPUnit:
    - `test_set_visibility_hides_and_records`
    - `test_set_visibility_skips_unchanged`
    - `test_set_visibility_requires_course_visibility_cap`: usuario sin la capacidad, con `required_capability_exception` y sin cambios.
    - `test_undo_visibility_restores`
    - `test_undo_visibility_conflict`
    - `clean_returnvalue` del servicio nuevo.
  - JS: el menú muestra «Ocultar» o «Mostrar» según el curso; la barra envía los ids seleccionados; tras aplicar se actualiza la etiqueta «Oculta».
- [ ] **Step 2:** Implementar. Subir `$plugin->version`, porque hay un servicio nuevo.
- [ ] **Step 3:** Verificar con `npm test`, check-strings, build, `php -l`, moodle-cs 0/0 y `npm run package`. Actualizar `INSTALACION.md` y `README.md` con ambas funciones (sección corta). Commit «ocultar y mostrar cursos con deshacer».
