# CEA-ORGANIZADOR · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. REQUIRED REFERENCE: skill `creating-cea-moodle-plugins` (estándares y lecciones) y skill `cea-branding`.

**Goal:** herramienta de administración `tool_ceaorganizador` para mover y reordenar categorías y cursos arrastrando y soltando, auto ordenarlos con recomendación y renombrarlos en lote, con deshacer.

**Architecture:**
- Una página de administración (`index.php`) carga el módulo AMD `tool_ceaorganizador/organizer`, que habla con 10 funciones web de `db/services.php`.
- La lógica difícil vive en clases puras sin base de datos: `sorter` y `renamer`.
- Toda escritura pasa por `mover`, que usa la API del núcleo, y queda registrada en `journal` para deshacerla.

**Tech Stack:**
- PHP 8.0+, Moodle 4.2 a 5.3, `\core_external`.
- ES modules compilados a AMD con `scripts/build-amd.mjs`; node:test + jsdom; PHPUnit.
- GitHub Actions con moodle-plugin-ci.

**Spec:** `docs/superpowers/specs/2026-11-10-cea-organizador-design.md`

## Global Constraints

- **Componente y versiones:**
  - `$plugin->component = 'tool_ceaorganizador'`.
  - `requires = 2023042400` (4.2) y `supported = [402, 503]`.
  - `maturity = MATURITY_BETA`, `release = '0.1.0'`.
- **Nombre e instalación:** nombre visible `CEA-ORGANIZADOR`; carpeta `admin/tool/ceaorganizador`; ZIP `dist/CEA-ORGANIZADOR.zip` con raíz `ceaorganizador/`.
- **Permiso propio:** `tool/ceaorganizador:manage`, `CONTEXT_SYSTEM`, `RISK_DATALOSS | RISK_CONFIG`, `archetypes => []`.
- **Permisos nativos** comprobados en cada escritura:
  - `moodle/category:manage` en origen y destino.
  - `moodle/course:changecategory` para mover cursos.
  - `moodle/course:update` para renombrar cursos.
- **Servicios web:** `\core_external\external_api`, `external_function_parameters`, `external_value`, `external_single_structure`, `external_multiple_structure`. Nunca los alias globales: no existen en 5.3.
- **Bootstrap:** las dos clases juntas cuando difieren (`custom-select form-select`, `ml-*`/`ms-*`, `mr-*`/`me-*`, `badge-*`/`bg-*`).
- **Idiomas:** `lang/es` y `lang/en`, mismas claves, en orden alfabético, español de Colombia. `check-strings` debe pasar.
- **JavaScript:**
  - Imports entre módulos por nombre Moodle (`tool_ceaorganizador/x`).
  - Sin librerías ni recursos externos.
  - Datos de más de 1 KB nunca en `js_call_amd`.
- **CSS:** clase raíz `.tool-ceaorganizador`; prefijo `tool-ceaorganizador-`; tokens `--cea-*`; botones en cápsula; sin sombras; respeta `prefers-reduced-motion`.
- **PHPUnit:** clases `final`; `#[CoversClass]` antes del docblock y `@covers` dentro; la ruta de la prueba refleja la de la clase.
- **Límites:** cursos por página = 100; renombrado por lote ≤ 500; nombre ≤ 254 caracteres; historial borrado a los 90 días; aviso de deshacer visible 8 s.
- **Git:** el repositorio es local; los commits van en español y el push solo se hace cuando el usuario lo pide.

## Review Focus

1. **Árbol desactualizado:** otra persona movió o borró la categoría de destino mientras la página estaba abierta. El servidor debe rechazar el movimiento con un error que la nombre, sin dejar el árbol a medias. Prueba en la Tarea 6: `test_move_category_into_own_descendant_after_external_change`.
2. **Vista previa vencida:** el orden cambió entre la vista previa y «Aplicar». El servidor no aplica nada y responde `stale`. Prueba en la Tarea 8: `test_sort_apply_rejects_stale_hash`.
3. **Nombres con etiquetas multilang** (`<span lang="es" class="multilang">…`): el renombrado no debe romperlos. Esos elementos se marcan «editar a mano» y no se transforman. Prueba en la Tarea 4: `test_multilang_names_are_skipped`.
4. **Nombre corto duplicado dentro del mismo lote** (dos cursos que quedan con el mismo `shortname`): los dos se marcan en conflicto y ninguno se aplica. Prueba en la Tarea 8: `test_rename_preview_flags_duplicate_shortnames_in_batch`.
5. **Renombrado que deja el nombre vacío** (quitar un prefijo que era todo el nombre): se marca como error y no se aplica. Prueba en la Tarea 4: `test_empty_result_is_error`.

---

### Task 1: Andamiaje del plugin, página vacía y CI

**Files:**
- Create (desde `~/.claude/skills/creating-cea-moodle-plugins/plantillas/`): `scripts/*`, `tests/js/register.mjs`, `tests/js/resolver.mjs`, `.github/workflows/moodle-ci.yml`, `.gitattributes`, `.gitignore`, `package.json`, `styles.css` (de `styles-base.css`), `templates/credito.mustache`, `version.php`, `INSTALACION.md`, `CLAUDE.md`
- Create: `README.md`, `settings.php`, `index.php`, `db/access.php`, `db/install.xml`, `db/upgrade.php`, `classes/privacy/provider.php` (provisional, `null_provider`), `templates/page.mustache`, `lang/es/tool_ceaorganizador.php`, `lang/en/tool_ceaorganizador.php`, `amd/src/organizer.js`

**Interfaces:**
- Produces:
  - La página `/admin/tool/ceaorganizador/index.php` (externalpage `tool_ceaorganizador`).
  - La plantilla `tool_ceaorganizador/page`, con `data-region="ceaorganizador"`, un contenedor `data-region="tree"` y el pie con el crédito.
  - El módulo AMD `tool_ceaorganizador/organizer` con `init({root: string})`.
  - La tabla `tool_ceaorganizador_log`: id, userid, action `char(20)`, summary `char(255)`, payload `text`, timecreated, undoneby, timeundone; índice en `timecreated`.

- [ ] **Step 1:** `git init -b main` en la raíz del proyecto. Copiar las plantillas según la sección «Andamiaje» de la skill y reemplazar los marcadores:

  | Marcador | Valor |
  | --- | --- |
  | `RUTA/NOMBRE` | `admin/tool/ceaorganizador` |
  | `RUTA/` | `admin/tool/` |
  | `COMPONENTE` | `tool_ceaorganizador` |
  | `CEA-NOMBRE` y `NOMBRE-VISIBLE` | `CEA-ORGANIZADOR` |
  | `.raiz-plugin` | `.tool-ceaorganizador` |

  Además, en `package.json`, `cea.zipName = "CEA-ORGANIZADOR.zip"`. A `cea.include` se le agrega `index.php` y se le quita lo que no exista.
- [ ] **Step 2:** En la matriz de `moodle-ci.yml`, quitar las dos filas de `MOODLE_401_STABLE` y agregar `{ moodle-branch: MOODLE_402_STABLE, php: '8.0', database: pgsql }` y `{ moodle-branch: MOODLE_402_STABLE, php: '8.1', database: mariadb }`.
- [ ] **Step 3:** Escribir `version.php` con los valores de Global Constraints, `db/access.php` con la capacidad, y `settings.php`:
  - `$ADMIN->add('courses', new admin_externalpage('tool_ceaorganizador', get_string('pluginname', ...), new moodle_url('/admin/tool/ceaorganizador/index.php'), 'tool/ceaorganizador:manage'))`.
  - Una página de ajustes `tool_ceaorganizador_settings` bajo `courses`, con tres `admin_setting_configtextarea`: `patterns_periodo`, `patterns_semilla` y `patterns_facultad`. Los valores por defecto se definen en la Tarea 3; aquí van vacíos.
- [ ] **Step 4:** Escribir `index.php`:
  - `admin_externalpage_setup('tool_ceaorganizador')` y `require_capability`.
  - `$PAGE->requires->js_call_amd('tool_ceaorganizador/organizer', 'init', [['root' => '#' . $id]])`.
  - Render de `page.mustache`, con `id` y `nativeurl` (`/course/management.php`).
  - Escribir la plantilla, con un «Example context (json)» válido.
- [ ] **Step 5:** Cadenas mínimas en es y en: `ceaorganizador:manage`, `credit` (texto de la skill), `pluginname` («Organizador de categorías (CEA)» / «Category organiser (CEA)»), `privacy:metadata` y `settings`.
- [ ] **Step 6:** `npm install`. Luego `npm run check-strings` debe dar «claves verificadas» con salida 0, y `npm run package` debe crear `dist/CEA-ORGANIZADOR.zip`. Correr `php -l` en cada `.php`: «No syntax errors».
- [ ] **Step 7:** Commit `Andamiaje de CEA-ORGANIZADOR (tool_ceaorganizador)`, con `package-lock.json` y `amd/build`.

---

### Task 2: Orden natural y periodos (`sorter`, parte pura)

**Files:**
- Create: `classes/local/sorter.php`
- Test: `tests/local/sorter_test.php`

**Interfaces:**
- Produces:
  - `sorter::normalize(string $name): string`: minúsculas, sin tildes (`core_text::specialtoascii`), sin etiquetas (`strip_tags`), espacios colapsados y recortados.
  - `sorter::compare(string $a, string $b): int`: orden natural.
  - `sorter::parse_period(string $name): ?array`: devuelve `[int $year, int $term]` o `null`.
  - `sorter::roman_value(string $token): ?int`: 1…30 o `null`.

- [ ] **Step 1: Pruebas que fallan**

```php
public function test_compare_numbers_and_romans(): void {
    $names = ['Semestre 10', 'Semestre IX', 'Semestre 2', 'Semestre l', 'Semestre II', 'Semestre 1'];
    usort($names, [sorter::class, 'compare']);
    $this->assertSame(['Semestre 1', 'Semestre l', 'Semestre 2', 'Semestre II', 'Semestre IX', 'Semestre 10'], $names);
}
public function test_compare_ignores_accents_case_and_spaces(): void {
    $this->assertSame(0, sorter::compare('Facultad de Ingeniería', 'facultad  de ingenieria '));
}
public function test_roman_value(): void {
    $this->assertSame(12, sorter::roman_value('Xll'));
    $this->assertSame(1, sorter::roman_value('l'));
    $this->assertSame(4, sorter::roman_value('IV'));
    $this->assertNull(sorter::roman_value('XL'));      // > 30: no es romano para nosotros
    $this->assertNull(sorter::roman_value('ciclo'));
}
/** @dataProvider period_provider */
public function test_parse_period(string $name, ?array $expected): void {
    $this->assertSame($expected, sorter::parse_period($name));
}
public static function period_provider(): array {
    return [
        ['2026-02', [2026, 2]], ['2026 -1', [2026, 1]], ['2026-2', [2026, 2]],
        ['2026 II', [2026, 2]], ['2026-01 Producción', [2026, 1]],
        ['2026 -2 Cursos Institucionales Transversales', [2026, 2]],
        ['Cohorte 2026', null], ['2026S1C01C04 - Neuropsicología', null], ['CEA', null],
    ];
}
```

- [ ] **Step 2:** Ejecutar `php -l tests/local/sorter_test.php`. Debe salir sin errores de sintaxis; la ejecución real de PHPUnit es en la CI. Como en este equipo no hay Moodle, comprobar también la lógica con un script temporal en el scratchpad que haga `require` de la clase con *stubs* de `core_text`. El script no se versiona.
- [ ] **Step 3: Implementar**
  - `parse_period`: expresión regular `/(?<!\d)(20\d\d)\s*[-–_\/ ]\s*(0?[12]|ii|i)(?![\p{L}\d])/iu`; II = 2 e I = 1.
  - `compare` tokeniza el nombre **sin pasarlo a minúsculas**, pero ya sin etiquetas, sin tildes y con los espacios colapsados. Separa secuencias de dígitos, de letras y de otros caracteres.
    - Un token de dígitos se compara como entero.
    - Un token de letras que forma una palabra aislada y para el que `roman_value` devuelve un número, también se compara como entero.
    - Los demás tokens se comparan en minúsculas con `strcmp`.
    - Entre número y texto, el número va primero.
    - **Desempate final:** si todos los tokens empatan, `strcmp(normalize($a), normalize($b))`. Así «Semestre 1» queda antes que «Semestre l», y dos nombres que solo difieren en tildes o mayúsculas dan 0.
  - `roman_value` es sensible a mayúsculas.
    - Reemplaza solo la `l` **minúscula** por `I`, porque es el error de digitación real («Xll», «Semestre l»). La `L` mayúscula sigue siendo 50.
    - Luego exige un romano canónico entre I y XXX, en mayúsculas o en minúsculas. Por eso `XL` devuelve null (40 > 30), `Xll` devuelve 12 y `ciclo` devuelve null.
- [ ] **Step 4:** Pasar el script de comprobación del Step 2 y `php -l`.
- [ ] **Step 5:** Commit `sorter: orden natural, romanos y periodos`.

---

### Task 3: Clasificación y orden propuesto (`sorter`, parte de reglas)

**Files:**
- Modify: `classes/local/sorter.php`, `settings.php`
- Test: `tests/local/sorter_test.php`

**Interfaces:**
- Consumes: `compare`, `parse_period` y `normalize` (Tarea 2).
- Produces:
  - `sorter::DEFAULT_PATTERNS`, un array con:
    - `'periodo' => ''` (los periodos se detectan con `parse_period`).
    - `'semilla' => "semilla\nplantilla\n=Facultades"`.
    - `'facultad' => "/^facultad/\n/^programa/"`.
  - `sorter::parse_patterns(string $text): array` → lista de `['type' => 'contains'|'exact'|'regex', 'value' => string]`.
  - `sorter::DEFAULT_PATTERNS_PARSED(): array`, con la forma `['semilla' => parse_patterns(...), 'facultad' => parse_patterns(...)]`. `patterns()` devuelve la misma forma.
  - `sorter::patterns(): array`: `get_config('tool_ceaorganizador')`, con los valores por defecto cuando falta un ajuste.
  - `sorter::classify(string $name, bool $toplevel, array $patterns): string`. Devuelve `'periodo'`, `'aparte'`, `'semilla'` o `'facultad'`. La precedencia es periodo > semilla > facultad > aparte. En niveles inferiores devuelve solo `'periodo'` u `'otro'`.
  - `sorter::propose(array $items, bool $toplevel, array $patterns): int[]`: `$items` es una lista de `['id' => int, 'name' => string]`; devuelve los ids ordenados.
  - `sorter::propose_courses(array $items): int[]`: orden natural por nombre, con empate por id.
  - `sorter::differs(array $currentids, array $proposedids): bool`.

- [ ] **Step 1: Pruebas que fallan**

```php
public function test_propose_toplevel_groups(): void {
    $items = self::items(['Facultad de Ingeniería', 'CEA', '2026-01', 'Semillas', 'Facultades', '2026-02', 'Centro de Idiomas', '2025-02']);
    $order = sorter::propose($items, true, sorter::DEFAULT_PATTERNS_PARSED());
    $this->assertSame(['2026-02', '2026-01', '2025-02', 'CEA', 'Centro de Idiomas', 'Facultades', 'Semillas', 'Facultad de Ingeniería'], self::names($items, $order));
}
public function test_facultades_is_seed_only_at_top_level(): void {
    $p = sorter::DEFAULT_PATTERNS_PARSED();
    $this->assertSame('semilla', sorter::classify('Facultades', true, $p));
    $this->assertSame('otro', sorter::classify('Facultades', false, $p));
}
public function test_propose_lower_level_periods_first_then_natural(): void {
    $items = self::items(['Semestre II', '2026 -1 Cursos', 'Semestre I', '2026 -2 Cursos']);
    $this->assertSame(['2026 -2 Cursos', '2026 -1 Cursos', 'Semestre I', 'Semestre II'], self::names($items, sorter::propose($items, false, sorter::DEFAULT_PATTERNS_PARSED())));
}
public function test_same_period_ties_are_natural(): void {
    $items = self::items(['2026-02 Producción', '2026-02 Apoyo a la presencialidad']);
    $this->assertSame(['2026-02 Apoyo a la presencialidad', '2026-02 Producción'], self::names($items, sorter::propose($items, true, sorter::DEFAULT_PATTERNS_PARSED())));
}
public function test_parse_patterns(): void {
    $this->assertSame([['type' => 'contains', 'value' => 'semilla'], ['type' => 'exact', 'value' => 'facultades'], ['type' => 'regex', 'value' => '/^programa/']],
        sorter::parse_patterns("Semilla\n=Facultades\n\n/^programa/"));
}
public function test_invalid_regex_pattern_is_ignored(): void {
    $this->assertSame([], sorter::parse_patterns('/[unclosed/'));
}
```

`DEFAULT_PATTERNS_PARSED()` es un método estático que devuelve los patrones por defecto ya procesados con `parse_patterns`. `items()` y `names()` son *helpers* privados de la prueba que convierten nombres en items con id y recuperan los nombres de unos ids.

- [ ] **Step 2:** Las pruebas fallan, porque los métodos no existen. Comprobarlo con el mismo script del scratchpad.
- [ ] **Step 3: Implementar**
  - Los valores `contains` y `exact` se comparan contra `normalize()`.
  - Una expresión regular inválida se descarta en `parse_patterns`; para saberlo se usa `@preg_match` y se mira si devuelve `false`.
  - En `settings.php`, el valor por defecto de cada textarea es `sorter::DEFAULT_PATTERNS[...]`, y la descripción explica la sintaxis: texto, `=exacto`, `/regex/`.
  - Cadenas: `patterns_periodo`, `patterns_semilla` y `patterns_facultad`, cada una con su `_desc`.
- [ ] **Step 4:** Pasan las pruebas, y también `npm run check-strings`.
- [ ] **Step 5:** Commit `sorter: grupos del primer nivel y patrones configurables`.

---

### Task 4: Transformaciones de nombres (`renamer`)

**Files:**
- Create: `classes/local/renamer.php`
- Test: `tests/local/renamer_test.php`

**Interfaces:**
- Produces:
  - `renamer::apply(string $name, array $ops): string`. Las operaciones se aplican en orden. Cada operación es uno de estos arrays:
    - `['type' => 'replace', 'search' => string, 'replace' => string, 'casesensitive' => bool, 'wholeword' => bool]`
    - `['type' => 'clean', 'case' => 'none'|'title'|'sentence'|'upper']`
    - `['type' => 'affix', 'position' => 'prefix'|'suffix', 'mode' => 'add'|'remove', 'text' => string]`
  - `renamer::preview(array $items, array $ops, array $manual): array`.
    - `$items` es una lista de `['type' => 'category'|'course', 'id' => int, 'name' => string, 'shortname' => ?string]`.
    - `$manual` es un mapa `"type:id" => string` que sobrescribe el resultado.
    - Devuelve, solo para lo que cambia o tiene error, filas `['type', 'id', 'old', 'new', 'error' => ?string]`.
    - Los códigos de error son `'empty'`, `'toolong'` y `'multilang'`.
  - `renamer::MAXLEN = 254`.

- [ ] **Step 1: Pruebas que fallan**

```php
public function test_clean_spaces_and_roman_l(): void {
    $this->assertSame('Facultades › Semestre I', renamer::apply("  Facultades   ›  Semestre l ", [['type' => 'clean', 'case' => 'none']]));
    $this->assertSame('Cohorte XII - Semestre 1', renamer::apply('Cohorte Xll -Semestre 1', [['type' => 'clean', 'case' => 'none']]));
}
public function test_title_case_keeps_acronyms_romans_and_connectors(): void {
    $this->assertSame('Maestría en Gestión de la Innovación TIC II', renamer::apply('maestría en gestión DE LA innovación TIC ii', [['type' => 'clean', 'case' => 'title']]));
}
public function test_title_case_from_all_caps_has_no_acronyms(): void {
    // Todo en mayúsculas: no hay forma de saber qué es sigla; todo se trata como palabra.
    $this->assertSame('Facultad de Ingeniería', renamer::apply('FACULTAD DE INGENIERÍA', [['type' => 'clean', 'case' => 'title']]));
}
public function test_replace_whole_word_case_insensitive(): void {
    $ops = [['type' => 'replace', 'search' => 'posgrado', 'replace' => 'Posgrados', 'casesensitive' => false, 'wholeword' => true]];
    $this->assertSame('Posgrados', renamer::apply('Posgrado', $ops));
    $this->assertSame('Posgrados', renamer::apply('Posgrados', $ops)); // palabra completa: no toca "Posgrados"
}
public function test_affix_add_is_idempotent_and_remove(): void {
    $add = [['type' => 'affix', 'position' => 'prefix', 'mode' => 'add', 'text' => '2026-2 ']];
    $this->assertSame('2026-2 Cálculo', renamer::apply('2026-2 Cálculo', $add));
    $this->assertSame('Cálculo', renamer::apply('Cálculo (Docente) 2026-2', [['type' => 'affix', 'position' => 'suffix', 'mode' => 'remove', 'text' => ' (Docente) 2026-2']]));
}
public function test_empty_result_is_error(): void {
    $rows = renamer::preview([['type' => 'category', 'id' => 5, 'name' => 'Eliminar', 'shortname' => null]],
        [['type' => 'affix', 'position' => 'prefix', 'mode' => 'remove', 'text' => 'Eliminar']], []);
    $this->assertSame('empty', $rows[0]['error']);
}
public function test_multilang_names_are_skipped(): void {
    $rows = renamer::preview([['type' => 'course', 'id' => 9, 'name' => '<span lang="es" class="multilang">Cálculo</span>', 'shortname' => 'CAL']],
        [['type' => 'clean', 'case' => 'upper']], []);
    $this->assertSame('multilang', $rows[0]['error']);
}
public function test_manual_overrides_and_unchanged_are_omitted(): void {
    $items = [['type' => 'category', 'id' => 1, 'name' => 'A', 'shortname' => null], ['type' => 'category', 'id' => 2, 'name' => 'B', 'shortname' => null]];
    $rows = renamer::preview($items, [], ['category:2' => 'Bé']);
    $this->assertCount(1, $rows);
    $this->assertSame('Bé', $rows[0]['new']);
}
```

- [ ] **Step 2:** Las pruebas fallan; comprobarlo con el script del scratchpad.
- [ ] **Step 3: Implementar**
  - Usar `core_text` para mayúsculas y minúsculas en UTF-8.
  - **Siglas:** palabras de 2 a 6 letras que están en mayúsculas en un original que **no** está todo en mayúsculas. Si el original está todo en mayúsculas, no hay siglas.
  - **Conectores:** `de, del, la, las, el, los, y, e, en, para, por, con, a`. Van en minúscula salvo cuando son la primera palabra, y tienen prioridad sobre la regla de siglas: «DE» se escribe «de».
  - **Romanos:** las palabras que `sorter::roman_value` reconoce van en mayúsculas.
  - **Limpiar:** colapsa espacios, normaliza «` - `» y corrige la «l» por «I» en los romanos aislados.
  - **Prefijo o sufijo:** `add` no duplica si ya está; `remove` quita solo si está.
  - **Multilang:** si el nombre contiene `<`, se marca `multilang` sin aplicar las operaciones; la edición manual sí se permite.
- [ ] **Step 4:** Pasan las pruebas.
- [ ] **Step 5:** Commit `renamer: transformaciones de nombres con vista previa`.

---

### Task 5: Lectura del árbol y de los cursos (`tree`)

**Files:**
- Create: `classes/local/tree.php`
- Test: `tests/local/tree_test.php`

**Interfaces:**
- Consumes: `sorter::propose`, `propose_courses`, `differs` y `patterns` (Tarea 3).
- Produces:
  - `tree::categories(): array`. Una lista ordenada por recorrido del árbol: padre, luego hijas por `sortorder`. Cada fila tiene `['id', 'parent', 'name', 'path', 'depth', 'sortorder', 'visible', 'coursecount', 'needsort' => bool]`.
    - `path` es `"A / B / C"`, con `format_string`.
    - `needsort` es verdadero si sus hijas o sus cursos difieren del orden propuesto.
  - `tree::courses(int $categoryid, string $search = '', int $page = 0, int $perpage = 100): array`. Devuelve `['courses' => [['id', 'category', 'fullname', 'shortname', 'sortorder', 'visible', 'path']], 'total' => int]`.
    - Con `categoryid = 0` busca en todo el sitio.
    - La búsqueda es `$DB->sql_like` sobre `fullname` y `shortname`, sin distinguir mayúsculas.
    - Nunca devuelve el curso del sitio (`SITEID`).
  - `tree::sibling_categories(int $parentid): int[]` y `tree::category_courses(int $categoryid): int[]`: ids en el orden actual.

- [ ] **Step 1: Pruebas que fallan** (con `$this->resetAfterTest()` y el generador de datos)
  - `test_categories_have_path_and_traversal_order`: crea `A` con hijas `B` y `C`, y una categoría raíz `D`. Comprueba que el orden de ids sea `[misc, A, B, C, D]` según su `sortorder`, y que la ruta de `B` sea `'A / B'`.
  - `test_needsort_flags_unsorted_children`: `A` tiene las hijas `Semestre II` y `Semestre I`, creadas en ese orden. Comprueba que `A.needsort === true`. Luego se llama a `resort_subcategories('name')` sobre `A` y se comprueba `needsort === false`.
  - `test_needsort_counts_courses`: una categoría con los cursos «B» y «A», en ese orden, tiene `needsort === true`.
  - `test_courses_pagination_and_search`: con 105 cursos en una categoría, la página 0 trae 100 cursos con `total = 105` y la página 1 trae 5. La búsqueda `'calc'` encuentra «Cálculo I» por su `shortname` `CALC1`.
  - `test_courses_never_include_site`.
- [ ] **Step 2:** `php -l` y revisión manual; la ejecución real es en la CI.
- [ ] **Step 3: Implementar**
  - Las categorías salen de una sola consulta a `course_categories`.
  - Los cursos para `needsort` salen de una sola consulta: `SELECT id, category, fullname FROM {course} WHERE id <> :site ORDER BY category, sortorder`. Se agrupan en memoria; no se hace una consulta por categoría.
  - El nivel superior es `parent = 0`.
- [ ] **Step 4:** `php -l` y commit `tree: árbol con rutas, recomendación y cursos paginados`.

---

### Task 6: Escrituras con la API de Moodle (`mover`)

**Files:**
- Create: `classes/local/mover.php`
- Test: `tests/local/mover_test.php`

**Interfaces:**
- Consumes: `tree::sibling_categories` y `category_courses` (Tarea 5).
- Produces (todas lanzan `\moodle_exception` con códigos de `tool_ceaorganizador`):
  - `mover::move_category(int $id, int $newparent, int $afterid): array`. Devuelve `['from' => ['parent' => int, 'afterid' => int], 'to' => [...]]`.
    - Error `cannotmoveintodescendant` si `$newparent` es la propia categoría o una descendiente; se valida con `get_all_children_ids()` en el momento de mover.
    - Error `aftermismatch` si `$afterid` no es hija de `$newparent`.
    - Usa `change_parent()` si cambia el padre y luego `apply_category_order`.
  - `mover::move_course(int $id, int $categoryid, int $afterid): array`, con `['from' => ['category', 'afterid'], 'to' => [...]]`.
    - Usa `move_courses([$id], $categoryid)` si cambia la categoría y luego `course_change_sortorder_after_course($id, $afterid)`.
    - Error `aftermismatch` si `$afterid` no está en la categoría.
  - `mover::apply_category_order(int $parentid, int[] $ids): void`.
    - Error `ordermismatch` si `$ids` no es exactamente el conjunto de las hijas actuales.
    - Replica el algoritmo de `resort_subcategories` (`$i = 1`; por hija `$i++`, `sortorder = $i`, `$i += coursecount`).
    - Termina con `core_course_category::resort_categories_cleanup()` y dispara `course_category_updated` por cada categoría que cambió de posición.
  - `mover::apply_course_order(int $categoryid, int[] $ids): void`.
    - Error `ordermismatch` si el conjunto no coincide.
    - `sortorder = cat.sortorder + i` y `resort_categories_cleanup(true)`.
  - `mover::rename_category(int $id, string $name): void`, con `core_course_category::get($id)->update(['name' => $name])`.
  - `mover::rename_course(int $id, string $fullname, ?string $shortname): void`, con `update_course((object)[...])`.
  - `mover::check_category(int $id): void` y `mover::check_course(int $id, string $cap): void`: `require_capability` con la capacidad nativa en el contexto correcto.

- [ ] **Step 1: Pruebas que fallan**
  - `test_move_category_to_new_parent_and_position`: con las raíces `A`, `B` y `C`, mover `C` dentro de `A` como primera hija (`afterid = 0`). Comprueba `parent = A`, y que `from` es `['parent' => 0, 'afterid' => B]`.
  - `test_move_category_after_sibling`: con `A`, `B` y `C`, mover `A` después de `C`. Las hermanas quedan en el orden `[B, C, A]`.
  - `test_move_category_into_own_descendant_after_external_change`: `A` contiene a `B`; mover `A` dentro de `B` lanza `cannotmoveintodescendant`.
  - `test_move_course_between_categories_with_position`: los cursos de la categoría destino quedan en el orden esperado.
  - `test_apply_category_order_rejects_wrong_set`: lanza `ordermismatch`.
  - `test_apply_course_order_sets_exact_order`.
  - `test_rename_course_shortname`.
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3:** Implementar según las firmas. Envolver cada método en `$DB->start_delegated_transaction()`. Los códigos de error van en `lang`: `error_aftermismatch`, `error_cannotmoveintodescendant` y `error_ordermismatch`.
- [ ] **Step 4:** `npm run check-strings`, `php -l` y commit `mover: mover, reordenar y renombrar con la API del núcleo`.

---

### Task 7: Registro, deshacer, historial y privacidad (`journal`)

**Files:**
- Create: `classes/local/journal.php`, `classes/task/cleanup_log.php`, `db/tasks.php`
- Modify: `classes/privacy/provider.php`, que pasa de `null_provider` a `metadata\provider + core_userlist_provider + plugin\provider`
- Test: `tests/local/journal_test.php`, `tests/privacy/provider_test.php`

**Interfaces:**
- Consumes: `mover::*` (Tarea 6) y `tree::sibling_categories` y `category_courses`.
- Produces:
  - `journal::record(string $action, string $summary, array $payload): int`. `$action` es `move_category`, `move_course`, `sort` o `rename`.
  - Formatos de `payload` por acción:
    - `move_category`: `{"id", "from": {"parent", "afterid"}, "to": {...}}`.
    - `move_course`: `{"id", "from": {"category", "afterid"}, "to": {...}}`.
    - `sort`: `{"categories": [{"parentid", "before": [ids], "after": [ids]}], "courses": [{"categoryid", "before": [...], "after": [...]}]}`.
    - `rename`: `{"items": [{"type", "id", "before": {"name"} | {"fullname", "shortname"}, "after": {...}}]}`.
  - `journal::undo(int $logid): array`, que devuelve `['ok' => bool, 'conflicts' => string[]]`.
    - **Conflicto:** el estado actual no es igual a `to`/`after`. Por ejemplo, el padre ya no es el de `to.parent`, la lista de hermanas ya no es `after`, o el nombre ya no es `after.name`.
    - Con conflictos no se escribe nada.
    - Sin conflictos se restaura con `mover` y se marcan `undoneby` y `timeundone`.
    - Error `alreadyundone` si ya se deshizo.
  - `journal::history(int $page, int $perpage = 20): array`, con filas `['id', 'action', 'summary', 'userfullname', 'timecreated', 'undone' => bool]` y `'total'`.
  - La tarea programada `cleanup_log` borra las filas con `timecreated < time() - 90 * DAYSECS`; se ejecuta a diario a las 03:00.

- [ ] **Step 1: Pruebas que fallan**
  - `test_undo_move_category_restores_parent_and_position`.
  - `test_undo_rejects_when_changed_after`: mover `A`, después mover `A` a mano con `mover` a otro sitio, y luego deshacer el primer movimiento. Comprueba `ok === false`, que `conflicts` contiene el nombre de `A`, y que `A` sigue donde se movió a mano.
  - `test_undo_sort_restores_before_order`.
  - `test_undo_rename_restores_names`.
  - `test_undo_twice_throws`.
  - `test_cleanup_deletes_older_than_90_days`.
  - **Privacidad:** `test_get_contexts_for_userid` (sistema), `test_export_user_data` y `test_delete_data_for_user_anonymises`, que comprueba que `userid` queda en 0 y que la fila no se borra.
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3:** Implementar. Cadenas nuevas:
  - `privacy:metadata:log` y sus campos `privacy:metadata:log:userid`, `:action`, `:payload` y `:timecreated`.
  - `taskcleanup` y `error_alreadyundone`.
- [ ] **Step 4:** `npm run check-strings`, `php -l` y commit `journal: deshacer con detección de conflictos, historial y privacidad`.

---

### Task 8: Funciones web

**Files:**
- Create: `db/services.php` y, en `classes/external/`, `get_tree.php`, `get_courses.php`, `move_category.php`, `move_course.php`, `sort_preview.php`, `sort_apply.php`, `rename_preview.php`, `rename_apply.php`, `undo.php` y `history.php`
- Create: `classes/local/sortplan.php` (vista previa y firma, compartida por `sort_preview` y `sort_apply`)
- Modify: `version.php` (subir `version`)
- Test: `tests/external/external_test.php`

**Interfaces:**
- Consumes: Tareas 3 a 7.
- Produces:
  - Las funciones `tool_ceaorganizador_<nombre>` con `'ajax' => true`, `'type' => 'read'` o `'write'` y `'capabilities' => 'tool/ceaorganizador:manage'`.
  - Cada clase extiende `\core_external\external_api`. Su `execute()` llama a `validate_parameters`, `validate_context(context_system::instance())` y `require_capability('tool/ceaorganizador:manage', ...)`, y antes de escribir, a `mover::check_*`.
  - `sortplan::build(int $categoryid, string $scope, bool $recursive): array`.
    - `$scope` es `'categories'`, `'courses'` o `'both'`.
    - Devuelve `['changes' => [['kind' => 'categories'|'courses', 'parentid', 'parentpath', 'before' => [['id', 'name']], 'after' => [...]]], 'hash' => string]`.
    - `hash = sha1(json_encode(changes))`.
    - Con `categoryid = 0` y `recursive = true` recorre el sitio entero.
  - `sort_apply(categoryid, scope, recursive, hash)` reconstruye el plan. Si el hash no coincide, devuelve `['status' => 'stale']`. Si coincide, aplica con `mover` y `journal`, y devuelve `['status' => 'ok', 'logid']`.
  - `rename_preview(items[{type, id}], ops (json string), manual (json string), includeshortname bool)` devuelve filas con este error adicional:
    - `'shortnametaken'`: el nombre corto ya lo usa otro curso del sitio.
    - `'shortnameduplicate'`: dos filas del lote quedan con el mismo nombre corto.
    - Para los cursos, el `shortname` nuevo se calcula con las mismas operaciones.
  - `rename_apply(changes[{type, id, old, new, oldshort?, newshort?}])`:
    - Aplica solo cuando el nombre actual es igual a `old`; las demás filas vuelven con `status = 'skipped'`.
    - Más de 500 elementos devuelve el error `toomany`.
    - Devuelve `['results' => [['type', 'id', 'status' => 'ok'|'skipped'|'error', 'message']], 'logid']`.
  - `ops` y `manual` viajan como texto JSON (`PARAM_RAW`) y se validan con `json_decode` y una lista blanca de claves.
  - **Candado:**
    - `sort_apply`, `rename_apply`, `move_category`, `move_course` y `undo` toman `\core\lock\lock_config::get_lock_factory('tool_ceaorganizador')->get_lock('write', 10)` y lo liberan en `finally`.
    - Si no lo obtienen en 10 s, lanzan el error `busy` («Otra persona está reorganizando; intenta en un momento»).

- [ ] **Step 1: Pruebas que fallan**
  - `test_requires_capability`: un usuario sin el permiso recibe `required_capability_exception` en `get_tree`.
  - `test_move_category_records_journal`: devuelve `logid` y `undo(logid)` restaura.
  - `test_sort_apply_rejects_stale_hash`: tras `sort_preview`, se renombra una categoría a mano; `sort_apply` con el hash viejo devuelve `stale` y el orden no cambia.
  - `test_rename_preview_flags_duplicate_shortnames_in_batch`.
  - `test_rename_apply_skips_changed_names`.
  - `test_rename_apply_rejects_over_500`.
  - `test_execute_returns_validate`: llama a `external_api::clean_returnvalue` con lo que devuelve cada función.
- [ ] **Step 2:** `php -l`.
- [ ] **Step 3:** Implementar. Cadenas `error_busy`, `error_stale` y `error_toomany`.
- [ ] **Step 4:** `npm run check-strings`, `php -l` y commit `servicios web del organizador`.

---

### Task 9: Árbol en el navegador (modelo, vista y búsqueda)

**Files:**
- Create: `amd/src/api.js`, `amd/src/treemodel.js`, `amd/src/treeview.js`, `amd/src/toast.js`
- Modify: `amd/src/organizer.js`, `templates/page.mustache`, `styles.css`
- Test: `tests/js/treemodel.test.mjs`, `tests/js/treeview.test.mjs`

**Interfaces:**
- Consumes: las funciones web (Tarea 8) y `init({root})` (Tarea 1).
- Produces:
  - **`api.js`:** un método por función web: `getTree()`, `getCourses(categoryid, search, page)`, `moveCategory(id, newparent, afterid)`, `moveCourse(id, categoryid, afterid)`, `sortPreview(...)`, `sortApply(...)`, `renamePreview(...)`, `renameApply(...)`, `undo(logid)` y `history(page)`.
    - Todos devuelven una promesa y usan `core/ajax`.
    - `api.setTransport(fn)` sirve para las pruebas.
  - **`treemodel.js`** (puro, sin DOM): `createModel(rows)` devuelve un objeto con:
    - `children(id)`, `get(id)`, `pathOf(id)` e `isDescendant(id, ofid)`.
    - `filter(query)`, que devuelve un `Set` de ids visibles: los que coinciden y sus ancestros.
    - `applyMoveCategory(id, newparent, afterid)` y su inversa, para la actualización optimista.
  - **`treeview.js`:** `render(container, model, state)`. Crea nodos con `role="treeitem"`, `aria-expanded` y `aria-level`, sin `core/templates` por rendimiento.
    - Los cursos se piden al expandir, con «Cargar más».
    - El estado expandido se guarda en `localStorage['tool_ceaorganizador_expanded']` dentro de un try/catch.
  - **`toast.js`:** `show(message, {actionLabel, onAction, timeout = 8000})`, con `role="status"`.
  - Estructura de la página:
    - Una barra superior con el buscador y el resumen «N categorías se pueden ordenar · Revisar».
    - El árbol.
    - El enlace «Gestión nativa de Moodle».
    - El pie con el crédito.

- [ ] **Step 1: Pruebas que fallan (node:test)**
  - `treemodel`:
    - `pathOf` devuelve `['A','B']`.
    - `isDescendant(A, B)` es verdadero cuando `B` está dentro de `A`.
    - `filter('semestre')` incluye a los ancestros.
    - `applyMoveCategory` seguido de su inversa deja el modelo igual.
  - `treeview` con jsdom: con 3 categorías crea 3 `treeitem`; un nodo con `needsort` muestra la insignia (`[data-region="needsort"]`); expandir llama a `getCourses` con un transporte falso.
- [ ] **Step 2:** `npm test` falla.
- [ ] **Step 3:** Implementar. Cadenas: `search`, `searchplaceholder`, `needsort` («Te recomiendo auto ordenar»), `needsortsummary`, `loadmore`, `nativemanagement`, `hidden`, `coursecount`, `undo`, `moved` y `error_generic`.
- [ ] **Step 4:** `npm test`, `npm run check-strings` y `npm run build`.
- [ ] **Step 5:** Commit `árbol en el navegador con carga diferida y búsqueda`.

---

### Task 10: Arrastrar y soltar, teclado y «Mover a…»

**Files:**
- Create: `amd/src/dropzones.js`, `amd/src/dragdrop.js`, `amd/src/keyboard.js`, `amd/src/movepicker.js`
- Modify: `amd/src/organizer.js`, `styles.css`
- Test: `tests/js/dropzones.test.mjs`, `tests/js/keyboard.test.mjs`

**Interfaces:**
- Consumes: `treemodel` y `api` (Tarea 9) y `toast`.
- Produces:
  - **`dropzones.js`** (puro):
    - `zoneFor(offsetY, height, targetKind)`. Para una categoría: tercio superior `'before'`, tercio medio `'inside'` e inferior `'after'`. Para un curso: mitad superior `'before'` y mitad inferior `'after'`.
    - `canDrop(model, dragged, target, zone)`. Es falso si:
      - Se suelta una categoría dentro de sí misma o de una descendiente.
      - Se suelta un curso en la raíz (`'before'` o `'after'` de una categoría de primer nivel).
      - Se suelta un curso `'inside'` de un curso.
    - `destinationFor(model, dragged, target, zone)` devuelve `{parent, afterid}` o `{category, afterid}`.
  - **`dragdrop.js`:**
    - Usa eventos de puntero y un fantasma del nodo.
    - Desplaza el árbol al acercarse a menos de 40 px de un borde.
    - Las zonas válidas se marcan con `.tool-ceaorganizador-drop-before/inside/after` y las inválidas con `.tool-ceaorganizador-drop-invalid`.
    - Al soltar:
      1. Actualización optimista en el modelo.
      2. Llamada a la API.
      3. Si sale bien, `toast` «Movido · Deshacer» que llama a `api.undo(logid)`.
      4. Si falla, revierte y muestra el error.
  - **`keyboard.js`:**
    - Flechas para navegar, como en el patrón de árbol de WAI-ARIA.
    - Espacio para tomar; flechas para recorrer los destinos válidos, anunciando cada uno en `aria-live`; Enter para soltar; Esc para cancelar.
  - **`movepicker.js`:** un diálogo «Mover a…» con buscador de categorías por su ruta; mueve a la primera posición del destino.

- [ ] **Step 1: Pruebas que fallan**
  - `zoneFor(5, 30, 'category') === 'before'`, `zoneFor(15, 30, 'category') === 'inside'` y `zoneFor(25, 30, 'course') === 'after'`.
  - `canDrop` es falso para una categoría sobre su descendiente y para un curso en la raíz.
  - `destinationFor` de una categoría `'before'` de su primera hermana devuelve `afterid: 0`; `'after'` de `X` devuelve `afterid: X`.
  - `keyboard`: Espacio, flecha abajo dos veces y Enter llama a `onDrop` con el segundo destino válido; Esc no llama a nada.
- [ ] **Step 2:** `npm test` falla.
- [ ] **Step 3:** Implementar. Cadenas: `moveto`, `movetitle`, `grabbed`, `dropbefore`, `dropinside`, `dropafter`, `dropcancelled` y `invaliddrop`.
  - **CSS del «ensamblar»:** al soltar, el nodo entra con `transform: translateY(-4px)` → `0` en 250 ms con la curva de la marca; con `prefers-reduced-motion`, sin animación.
- [ ] **Step 4:** `npm test`, `npm run check-strings` y `npm run build`.
- [ ] **Step 5:** Commit `arrastrar y soltar, teclado y mover a`.

---

### Task 11: Paneles de auto ordenar, renombrado, selección e historial

**Files:**
- Create: `amd/src/selection.js`, `amd/src/sortpanel.js`, `amd/src/renamepanel.js`, `amd/src/historypanel.js`, `templates/sortpanel.mustache`, `templates/renamepanel.mustache`, `templates/historypanel.mustache`
- Modify: `amd/src/organizer.js`, `amd/src/treeview.js` (casillas y menú del nodo), `styles.css`
- Test: `tests/js/selection.test.mjs`, `tests/js/renamepanel.test.mjs`

**Interfaces:**
- Consumes: `api`, `treemodel`, `treeview` y `toast`.
- Produces:
  - **`selection.js`:** `createSelection()` devuelve un objeto con:
    - `toggle(type, id)`.
    - `addCategoryContents(model, categoryid, courses)`: la categoría, sus descendientes y los cursos ya cargados.
    - `addSearchResults(rows)`.
    - `items()`, que devuelve `[{type, id}]`.
    - `count()` y `clear()`.
  - **`sortpanel.js`:** `open({categoryid, path})`.
    - Elige el alcance (subcategorías, cursos o ambos) y la casilla «incluir lo que está por debajo».
    - Muestra la vista previa: dos columnas, antes y después, solo de lo que cambia.
    - Con Confirmar llama a `sortApply` con el hash. Si responde `stale`, muestra «El orden cambió, vuelve a revisar» y recarga la vista previa. Si sale bien, muestra el `toast` con Deshacer y recarga el árbol.
  - **`renamepanel.js`:** `open(selectionItems)`.
    - Una lista de operaciones que se pueden agregar, quitar y reordenar: reemplazar, limpiar, prefijo o sufijo.
    - La casilla «incluir nombre corto».
    - Una tabla de vista previa con el nombre nuevo editable por fila, que alimenta `manual`.
    - Los errores se muestran por fila con su cadena.
    - «Aplicar» envía solo las filas sin error.
    - Más de 500 elementos se dividen en lotes, con aviso.
    - `buildOps(formState)` es puro y se exporta para las pruebas.
  - **`historypanel.js`:** muestra la lista paginada y un botón Deshacer por entrada no deshecha. Si hay conflictos, los muestra.
  - **Menú del nodo:** Mover a…, Auto ordenar, Renombrar, Abrir en Moodle (`/course/management.php?categoryid=X`) y Seleccionar contenido.

- [ ] **Step 1: Pruebas que fallan**
  - `selection.addCategoryContents` incluye a los descendientes y no duplica.
  - `buildOps` de un formulario con «reemplazar Posgrado→Posgrados, palabra completa» devuelve la operación exacta de la Tarea 4.
  - `renamepanel` con un transporte falso que devuelve una fila con `error: 'shortnametaken'` deshabilita esa fila y no la envía en `renameApply`.
- [ ] **Step 2:** `npm test` falla.
- [ ] **Step 3:** Implementar. Cadenas:
  - Auto ordenar: `autosort`, `autosortscope_categories`, `autosortscope_courses`, `autosortscope_both`, `autosortrecursive`, `autosortpreview`, `autosortnothing`, `before`, `after` y `confirm`.
  - Renombrado: `rename`, `renameop_replace`, `renameop_clean`, `renameop_affix`, `casesensitive`, `wholeword`, `case_none`, `case_title`, `case_sentence`, `case_upper`, `prefix`, `suffix`, `affixadd`, `affixremove`, `includeshortname`, `apply` y `batchsplit`.
  - Errores de renombrado: `renameerror_empty`, `renameerror_toolong`, `renameerror_multilang`, `renameerror_shortnametaken` y `renameerror_shortnameduplicate`.
  - Historial: `history`, `history_empty`, `undoconflicts`, `opencategory`, `selectcontents`, `selected` y `stale`.
- [ ] **Step 4:** `npm test`, `npm run check-strings` y `npm run build`.
- [ ] **Step 5:** Commit `paneles de auto ordenar, renombrado e historial`.

---

### Task 12: Marca, accesibilidad, documentación y entrega

**Files:**
- Modify: `styles.css`, todas las plantillas (contexto de ejemplo válido), `README.md`, `INSTALACION.md`, `CLAUDE.md`, `version.php`
- Test: `npm test`, `npm run check-strings`, `npm run package`, `php -l` en todo

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: `dist/CEA-ORGANIZADOR.zip`.

- [ ] **Step 1: Revisión de marca** con la lista de `cea-branding`:
  - Insignia de recomendación: lavanda con filete violeta de día `#7444e6`.
  - Conflictos: filete naranja de día `#d95f06`.
  - Acción principal en turquesa de día `#0b7d90`.
  - Foco visible.
  - Nombre completo del Centro en el crédito.
  - Ni «CEV» ni nombres antiguos en ningún archivo: `grep -ri "cev\b"` debe salir vacío.
- [ ] **Step 2: Contraste.** El texto en tinta sobre papel y el blanco sobre turquesa de día dan ≥ 4,5:1. La insignia lleva texto en tinta, no en violeta.
- [ ] **Step 3: Documentación.**
  - `INSTALACION.md`: dónde aparece el plugin (*Administración del sitio › Cursos › Organizador de categorías (CEA)*), el permiso, los ajustes de patrones y la nota de `public/` desde 5.1.
  - `README.md`: qué hace, cómo se desarrolla y los comandos.
  - `CLAUDE.md`: estas reglas propias:
    - Moodle 4.2 o superior por `core_external`.
    - Escribir solo a través de `mover` y `journal`.
    - `sorter` y `renamer` son puros.
- [ ] **Step 4: Verificación final.** `npm test` (todo pasa), `npm run check-strings` (salida 0), `php -l` en cada `.php` (sin errores) y `npm run package`. Con `python -I -c "import zipfile; ..."`, comprobar que la raíz del ZIP es `ceaorganizador/` y que contiene `amd/build/organizer.min.js`, `db/services.php` e `INSTALACION.md`.
- [ ] **Step 5:** Commit `Documentación y empaquetado de CEA-ORGANIZADOR 0.1.0`.
- [ ] **Step 6:** Informar al usuario:
  - La compatibilidad queda sin confirmar hasta que exista el repositorio en `usbvirtual` y pase la CI. Pedirle la URL y si se hace push.
  - La prueba manual se hace en una categoría de prueba de eCampus, nunca sobre periodos activos.
