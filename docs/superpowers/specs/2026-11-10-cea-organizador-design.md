# CEA-ORGANIZADOR · Especificación de diseño

- **Fecha:** 2026-11-10
- **Estado:** diseño aprobado por el usuario en conversación; especificación pendiente de su revisión.
- **Plugin:** `tool_ceaorganizador` · nombre visible **CEA-ORGANIZADOR** · carpeta de instalación `admin/tool/ceaorganizador`.
- **Dueño:** Centro de Innovación Pedagógica y Educación Digital (CEA), USB Cali.
- **Estándares de calidad:** skill `creating-cea-moodle-plugins` (reúne las lecciones de CEA-LECTOR y CEA-ESPACIO). Lo que aquí no se diga, lo dice esa skill.

## 1. Propósito

La pantalla nativa «Gestionar cursos y categorías» de Moodle obliga a mover las cosas una por una, con flechas y menús. En las plataformas de la USB hay cientos de categorías y miles de cursos, con nombres inconsistentes. CEA-ORGANIZADOR permite a los administradores:

1. Mover y reordenar categorías y cursos **arrastrando y soltando**.
2. **Auto ordenar** categorías con un criterio institucional, y recibir la recomendación cuando algo está desordenado.
3. **Renombrar en lote** categorías y cursos.

### Éxito
- Mover un curso o una categoría toma un gesto, y se puede deshacer.
- Un administrador ordena una sede completa sin abrir la pantalla nativa.
- Las inconsistencias que se ven en los datos reales (ver 1.1) se corrigen en minutos, con vista previa y sin perder el estado anterior.

### 1.1 Lo que muestran los datos reales

Fuente: bases locales del Dash Board Moodle (`data/moodle*.db`), extracción del 2026-11-09, periodos 2026-01 y 2026-02. Se analizaron eCampus, Mcampus y Campus M (Medellín).

- **Estructura:** `Periodo › Facultad(es) › Pregrado | Posgrados › Programa › Semestre | Cohorte`. Fuera de los periodos hay categorías sueltas (CEA, Centro de Idiomas, Inducción, Educación Continua…).
- **Nombres del mismo nivel con varias formas:** «Posgrado / Posgrados / Postgrados», «Especialización / Especializaciones», «Maestrias / Maestrías».
- **Facultades duplicadas por tilde en Mcampus:** «Facultad de Ingeniería» e «Ingenieria»; «Ciencias Políticas» y «Politicas».
- **Espacios dobles y finales:** «Facultades  › …», «Programa Economía ».
- **Romanos con L minúscula y mezcla con arábigos:** «Semestre l» y «Cohorte Xll»; «Semestre I» frente a «Semestre 1».
- **Periodos con formatos distintos:** «2026-02» frente a «2026 -1» (Medellín).
- **Errores de digitación:** «Internaciones», «Perfomance».
- **Cursos con docente y periodo en el nombre:** «Estadística I G4 (Docente) 2026-2».

No se consultó ninguna plataforma en vivo.

## 2. Alcance

**Incluye:** árbol con arrastrar y soltar, buscador, auto ordenar con recomendación, renombrado masivo, deshacer e historial.

**No incluye:** crear, borrar u ocultar categorías o cursos; mover en lote por arrastre; reubicar automáticamente cursos en otra categoría; permisos por categoría para gestores. Para crear, borrar u ocultar se enlaza la pantalla nativa.

## 3. Usuarios, permisos y compatibilidad

- **Quién lo usa:** solo administradores del sitio.
- **Permiso propio:** `tool/ceaorganizador:manage` en `CONTEXT_SYSTEM`, `RISK_DATALOSS | RISK_CONFIG`, `archetypes => []`.
- **Comprobaciones en el servidor en cada operación:** además del permiso propio, los permisos nativos.
  - `moodle/category:manage` en el contexto de origen y en el de destino.
  - `moodle/course:changecategory` para mover cursos.
  - `moodle/course:update` para renombrar cursos.
- **Página:** `admin_externalpage` bajo *Administración del sitio › Cursos* (`courses`), con el nombre «Organizador de categorías (CEA)».
- **Compatibilidad declarada:** Moodle 4.2 a 5.3 (`requires = 2023042400`, `supported = [402, 503]`) y PHP 8.0 a 8.4. No se soporta 4.1, por esta razón:
  - Los servicios web usan `\core_external`, que existe desde 4.2.
  - Los alias globales (`external_api`…) ya no están en 5.3: se verificó en `public/lib/externallib.php` de `MOODLE_503_STABLE`, el 2026-11-10.
  - eCampus está en 4.5.12. Las versiones de Mcampus y Campus M no están verificadas.

## 4. Arquitectura

```
admin/tool/ceaorganizador/
  index.php                     página única: admin_externalpage_setup + plantilla + js_call_amd
  settings.php                  registra la página y los ajustes de clasificación
  version.php, styles.css, README.md, INSTALACION.md
  db/access.php                 tool/ceaorganizador:manage
  db/services.php               funciones AJAX (ajax => true, loginrequired)
  db/install.xml, db/upgrade.php  tabla tool_ceaorganizador_log
  classes/local/tree.php        lectura del árbol y de los cursos (consultas por lotes)
  classes/local/mover.php       mover y reordenar categorías y cursos (envuelve la API de Moodle)
  classes/local/sorter.php      clasificación y orden natural (sin base de datos: pura y probada)
  classes/local/renamer.php     transformaciones de nombres (pura y probada)
  classes/local/journal.php     registro de estados anteriores y deshacer
  classes/external/*.php        una clase por función web, valida parámetros y permisos
  classes/privacy/provider.php  metadata + userlist + plugin provider (el registro guarda userid)
  templates/*.mustache          página, nodo del árbol, paneles, aviso de deshacer
  amd/src/*.js                  organizer (arranque), tree, dragdrop, keyboard, sortpanel, renamepanel, api, toast
  lang/es, lang/en
  tests/ (PHPUnit) y tests/js/ (node:test)
```

Regla: `sorter` y `renamer` no tocan la base de datos. Reciben y devuelven datos, para que la lógica difícil se pruebe sin Moodle. `mover` y `journal` son la única puerta para escribir.

### 4.1 Funciones web (`db/services.php`)

Todas son `ajax => true`. Todas exigen `tool/ceaorganizador:manage` y validan con `validate_context(context_system::instance())`.

| Función | Entrada | Salida |
| --- | --- | --- |
| `tool_ceaorganizador_get_tree` | — | todas las categorías: id, padre, nombre, ruta, sortorder, visible, número de cursos, profundidad; además, cuántas recomiendan auto ordenar |
| `tool_ceaorganizador_get_courses` | categoryid (0 = todo el sitio), search (opcional), page (100 por página) | cursos: id, categoría, fullname, shortname, sortorder, visible |
| `tool_ceaorganizador_move_category` | id, newparent, afterid (0 = primera posición) | nuevo padre y posición + id del registro de deshacer |
| `tool_ceaorganizador_move_course` | id, categoryid, afterid (0 = primera posición) | igual |
| `tool_ceaorganizador_sort_preview` | categoryid, alcance (subcategorías, cursos, ambos), recursivo | lista antes/después por categoría |
| `tool_ceaorganizador_sort_apply` | lo mismo + firma de la vista previa | id del registro |
| `tool_ceaorganizador_rename_preview` | elementos (tipo, id), operación y opciones | lista antes/después, con conflictos marcados |
| `tool_ceaorganizador_rename_apply` | cambios explícitos {tipo, id, nombre_anterior, nombre_nuevo} | resultado por elemento + id del registro |
| `tool_ceaorganizador_undo` | id del registro | resultado; si hubo cambios ajenos posteriores, lo rechaza y dice qué cambió |
| `tool_ceaorganizador_history` | page | últimas operaciones (quién, qué, cuándo, si se deshizo) |

- **Firma de la vista previa:** `sort_apply` recibe un hash del orden mostrado. Si el estado cambió desde la vista previa, responde «el orden cambió, vuelve a revisar» y no aplica nada.
- **Renombrado:** `rename_apply` aplica solo los elementos cuyo nombre actual sigue siendo `nombre_anterior`. Los demás vuelven como «omitido: cambió desde la vista previa».

### 4.2 Escritura con la API de Moodle

Se usan siempre las funciones del núcleo, para que se disparen los eventos y se limpien las cachés.

- **Mover una categoría a otro padre:** `core_course_category::change_parent()`.
- **Mover cursos a otra categoría:** `move_courses()`.
- **Posición de un curso:** `course_change_sortorder_after_course()`.
- **Renombrar una categoría:** `core_course_category::update()`.
- **Renombrar un curso:** `update_course()`.
- **Posición exacta de una categoría entre sus hermanas** (verificado en 4.1 y 5.0 el 2026-11-10): el núcleo solo ofrece `change_sortorder_by_one()` y `resort_subcategories($field)`. `mover` replica el algoritmo de `resort_subcategories()` con un orden propio y termina con `core_course_category::resort_categories_cleanup()`. Ese algoritmo empieza con `$i = 1`; en cada hija hace `$i++`, escribe `sortorder = $i` y luego `$i += coursecount`. La limpieza ejecuta `fix_course_sortorder()` y purga `changesincoursecat`. Por cada categoría que cambia se dispara `course_category_updated`.
- **Orden completo de cursos** (auto ordenar): `mover` replica `resort_courses()` (`sortorder = categoría.sortorder + i`) y llama a `resort_categories_cleanup(true)`.
- **Operaciones masivas:** se ejecutan en una transacción por lote y con un candado (`\core\lock\lock_config::get_lock_factory('tool_ceaorganizador')`). Así, dos administradores no reordenan la misma categoría a la vez.

## 5. La página

### 5.1 Árbol
- Al abrir se cargan todas las categorías (`get_tree`).
- Los cursos se piden al expandir una categoría, de 100 en 100, con «Cargar más».
- Cada nodo muestra el nombre, el número de cursos y un indicador de oculta.
- Si la categoría está desordenada, lleva la insignia «Te recomiendo auto ordenar».
- El nodo tiene un menú: Mover a…, Auto ordenar, Renombrar, y Abrir en Moodle (la gestión nativa de esa categoría).
- **Buscador:** filtra por nombre en el cliente para las categorías. Para cursos consulta el servidor (`get_courses` con texto), porque no están cargados. Cada resultado muestra la **ruta completa**.
- Estado expandido o contraído recordado por usuario con `localStorage`. Si no está disponible, todo arranca contraído.

### 5.2 Arrastrar y soltar
- Implementación propia con eventos de puntero (sin librerías: en eCampus las librerías UMD ya rompieron el RequireJS de Moodle).
- **Zonas de caída:** antes, dentro o después de una categoría; antes o después de un curso, o dentro de una categoría.
- Las zonas inválidas se marcan y no aceptan: una categoría dentro de sí misma o de una descendiente, y un curso en la raíz.
- Al soltar, el nodo se mueve en pantalla de inmediato y se llama al servidor:
  - Si responde bien, aparece el aviso «Movido · Deshacer» (8 s).
  - Si falla, el nodo vuelve a su sitio y se muestra el error.
- **Teclado:**
  - Flechas para navegar.
  - Espacio para tomar el elemento; flechas para elegir el destino (con anuncio en `aria-live`); Enter para soltar y Esc para cancelar.
- **Menú «Mover a…»:** selector de categoría con búsqueda, para quien no arrastra.
- **Desplazamiento automático** al arrastrar cerca de los bordes, porque el árbol es largo.

### 5.3 Selección
- Casillas en categorías y cursos. Hay atajos para «seleccionar todo lo de esta categoría» y «seleccionar los resultados de la búsqueda».
- La barra de selección muestra cuántos elementos hay y abre el renombrado.

## 6. Auto ordenar

### 6.1 Clasificación (`sorter.php`)

**Primer nivel**, grupos en este orden:

| Orden | Grupo | Detección por defecto (editable en ajustes) |
| --- | --- | --- |
| 1 | Periodos | el nombre contiene un periodo: año de 4 dígitos + separador opcional con espacios + 1/2/01/02/I/II (`2026-02`, `2026 -1`, `2026-2`, `2026 II`) |
| 2 | Categorías aparte | todo lo que no cae en otro grupo (CEA, Centro de Idiomas, Inducción…) |
| 3 | Semillas (cursos plantilla) | contiene «semilla» o «plantilla», o se llama exactamente «Facultades» en el primer nivel |
| 4 | Facultades y programas | empieza por «Facultad» o «Programa» |

**Dentro de cada grupo:**
- Periodos del más reciente al más antiguo: año descendente y luego periodo descendente. Si hay varias categorías con el mismo periodo, como «2026-02 Producción» y «2026-02 Apoyo a la presencialidad» en Medellín, van en orden natural.
- El resto, en orden natural.

**Niveles inferiores:** sin grupos. Si hay periodos entre las hermanas, van primero, recientes antes. El resto, en orden natural.

**Cursos:** orden natural por nombre completo.

**Orden natural** (una sola función, en PHP; el navegador no ordena, solo muestra lo que calcula el servidor):
- Ignora mayúsculas, tildes y espacios repetidos.
- Compara los números como números: 2 va antes que 10.
- Lee como número los romanos que forman una palabra sola (I…XXX), incluidos los escritos con «l» minúscula en lugar de «I» (`Semestre l`, `Cohorte Xll`). Así, I < II < IX < X, y «Semestre 1» y «Semestre I» quedan juntos.
- En empate, decide el id, para que el orden sea estable.

**Ajustes** (`settings.php`): una lista de patrones por grupo, uno por línea. Puede ser texto que contiene, `=nombre exacto` o `/expresión/`. Los valores por defecto son los de la tabla.

### 6.2 Recomendación
- `get_tree` calcula, para cada categoría, si sus subcategorías o sus cursos difieren del orden propuesto. Los cursos se evalúan con una sola consulta por lotes, no una por categoría.
- Si difieren, aparece la insignia «Te recomiendo auto ordenar» en el nodo y un resumen arriba («N categorías se pueden ordenar · Revisar»).

### 6.3 Flujo
1. Pulsar «Auto ordenar» (en un nodo o en el resumen).
2. Elegir el alcance (subcategorías, cursos o ambos) y si se aplica a lo que está por debajo.
3. Ver el antes y el después (solo las categorías que cambian).
4. Confirmar.
5. Aviso con Deshacer.

## 7. Renombrado masivo (`renamer.php`)

**Se aplica a:** la selección, el contenido de una categoría o los resultados de una búsqueda. Puede incluir categorías y cursos.

**Operaciones** (se pueden encadenar en el orden en que se agregan):
1. **Buscar y reemplazar:** texto literal, con opción de distinguir mayúsculas y de solo palabra completa.
2. **Limpiar formato:**
   - Recorta espacios y deja uno solo entre palabras.
   - Normaliza los espacios alrededor de «-».
   - Corrige la «l» por «I» en romanos aislados.
   - Mayúsculas opcionales: Tipo Título, Tipo oración o MAYÚSCULAS. Las siglas (palabras de 2 a 6 letras ya en mayúsculas), los romanos y los conectores en minúscula («de», «y», «en», «la», «del», «para») se respetan en Tipo Título.
3. **Prefijo o sufijo:** agregar, o quitar si está presente.
4. **Edición en tabla:** cada nombre nuevo se edita a mano. Es la última capa: sobrescribe lo calculado.

**Campos:**
- En categorías, el nombre.
- En cursos, el nombre completo. El nombre corto se incluye solo si se marca. Antes de aplicarlo, la vista previa comprueba que cada nombre corto nuevo siga siendo único (en el sitio y dentro del lote); los que choquen se marcan y no se aplican.

**Otras reglas:**
- Vista previa obligatoria, que muestra solo lo que cambia.
- Lotes de hasta 500 elementos. Si hay más, se divide y se avisa.
- Los nombres vacíos o de más de 254 caracteres (límite de `fullname`) se rechazan en la vista previa.

## 8. Deshacer e historial (`journal.php`)

Tabla `tool_ceaorganizador_log`:

| Campo | Tipo | Notas |
| --- | --- | --- |
| id | int | |
| userid | int | quien hizo la operación |
| action | char(20) | `move_category`, `move_course`, `sort`, `rename` |
| summary | char(255) | texto corto para el historial |
| payload | text | JSON con, por elemento, el estado anterior y el nuevo (padre, sortorder, nombres) |
| timecreated | int | |
| undoneby | int | 0 si no se ha deshecho |
| timeundone | int | |

- **Deshacer** restaura el estado anterior solo si el estado actual coincide con el «nuevo» guardado. Si algo cambió después, se rechaza, se listan los elementos en conflicto y no se toca nada.
- **Historial:** se muestra en un panel. Las entradas de más de 90 días se borran con una tarea programada.
- **Privacidad:** el proveedor declara `userid` y anonimiza poniéndolo en 0 al borrar los datos de un usuario, igual que CEA-ESPACIO.

## 9. Errores y casos límite

- **Sin permiso nativo sobre una categoría concreta:** la operación se rechaza con un mensaje que la nombra. La interfaz no la oculta, porque es un administrador.
- **El elemento ya no existe** (otro administrador lo borró): error claro y recarga de esa rama.
- **Mover una categoría dentro de sí misma o de una descendiente:** se valida en el cliente y también en el servidor.
- **Sesión vencida o sesskey inválida:** mensaje con el enlace para volver a entrar. Lo que estaba en pantalla queda en su posición anterior.
- **Categorías muy grandes:** «Cargar más» en los cursos. Auto ordenar y renombrar trabajan por lotes en el servidor.

## 10. Marca

Sigue la skill `cea-branding`.

- **Raíz:** `.tool-ceaorganizador`, con variables `--cea-*`.
- **Colores:** tinta para el texto, papel para el fondo, línea `#cad6d0`.
- **Acción principal** en turquesa de día `#0b7d90`. La insignia de recomendación, en lavanda con un filete en violeta de día `#7444e6`. Los avisos de conflicto, con un filete en naranja de día `#d95f06`.
- **Formas y movimiento:** botones en cápsula, tarjetas de 14 px, sin sombras. Movimiento de 250 ms con `cubic-bezier(.16, 1, .3, 1)`; con `prefers-reduced-motion`, sin animación.
- **Al soltar:** el elemento se «ensambla» en su lugar con un desplazamiento breve, que remite a las piezas que encajan.
- **Pie:** el símbolo del CEA y el crédito «Centro de Innovación Pedagógica y Educación Digital (CEA)».
- **Textos:** en español de Colombia, pendientes de revisión editorial.

## 11. Pruebas

- **PHPUnit (CI):**
  - `sorter_test`: periodos en todos los formatos vistos en 1.1; romanos con «l»; grupos del primer nivel; «Facultades» como semilla solo en el primer nivel; estabilidad.
  - `renamer_test`: cada operación; siglas; conectores; unicidad del nombre corto.
  - `mover_test`: mover y reordenar contra la base de prueba, verificando el `sortorder` final.
  - `journal_test`: deshacer y conflicto.
  - `external_test`: permisos y validación.
  - `privacy_test`.
- **node:test:**
  - El cálculo de zonas de caída y la validación de descendientes.
  - La navegación por teclado.
  - El modelo del árbol en JS (rutas, descendientes, filtro).
- **CI** (GitHub Actions + moodle-plugin-ci): la matriz y los pasos de la skill de calidad.
- **Manual** en eCampus, en un entorno de prueba si existe; si no, en producción con una categoría de prueba, nunca sobre periodos activos.

## 12. Entrega

`dist/CEA-ORGANIZADOR.zip`, con la carpeta raíz `ceaorganizador/` y la guía de instalación dentro. Se instala en *Administración del sitio › Extensiones › Instalar extensiones*, o copiando la carpeta en `admin/tool/`. Después se actualiza y se purgan las cachés.

## 13. Para el plan de implementación

- Verificar en el código de Moodle 4.1 y 5.x:
  - La vía soportada para la posición exacta de una categoría (4.2).
  - La firma de `course_change_sortorder_after_course()`.
  - Qué eventos dispara cada función.
- Confirmar la versión de Moodle de Mcampus y Campus M antes de la entrega a esas sedes.
