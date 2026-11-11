# CEA-ORGANIZADOR · Clonar semillas a un periodo · Especificación

- **Fecha:** 2026-11-10
- **Estado:** diseño aprobado por el usuario en conversación; especificación pendiente de su revisión.
- **Amplía:** `2026-11-10-cea-organizador-design.md` (CEA-ORGANIZADOR 0.1.0). Lo que aquí no se diga, lo dice esa especificación y la skill `creating-cea-moodle-plugins`.

## 1. Propósito

Al abrir un periodo académico, el equipo copia los **cursos semilla** (plantillas) a la categoría del periodo nuevo. Hoy se hace curso por curso con «Copiar curso» de Moodle. La función nueva lo hace en lote, en pocos pasos: el administrador elige las semillas y el periodo destino, revisa una vista previa y lanza las copias.

### Éxito
- Clonar todas las semillas de una facultad a un periodo nuevo toma un solo flujo, sin abrir cada curso.
- Cada copia queda en la misma ruta dentro del periodo, con nombre y fechas del periodo, oculta y sin usuarios.
- Antes de lanzar las copias, el administrador ve exactamente qué se creará.

## 2. Alcance

**Incluye:**
- clonar en lote desde una categoría de semillas (con todo lo que tiene debajo) o desde una selección de cursos;
- destino en espejo, creando las categorías que falten;
- nombres con el periodo;
- fechas del periodo;
- vista previa editable;
- seguimiento del progreso;
- registro en el historial.

**No incluye:**
- copiar usuarios, calificaciones o entregas;
- deshacer automático, porque borraría cursos;
- clonar un curso suelto a una categoría cualquiera (para eso está «Copiar curso» de Moodle);
- programar copias para más tarde.

## 3. Decisiones del usuario (2026-11-10)

| Tema | Decisión |
| --- | --- |
| Uso | Semillas a un periodo nuevo |
| Contenido | Solo contenido: actividades, recursos, secciones, bancos de preguntas y configuración. Sin usuarios ni datos de usuario |
| Nombres | Nombre completo + « » + periodo («Cálculo I 2027-01»); nombre corto + «-» + periodo («CALC1-2027-01»). Ambos editables en la vista previa |
| Visibilidad | Ocultos |
| Destino | Espejo de la estructura, creando las categorías que falten |
| Fechas | Inicio y fin del periodo indicados en el panel. Moodle desplaza las fechas de las actividades al nuevo inicio |

## 4. Motor: copia de cursos de Moodle

Se usa `\copy_helper::create_copy(\stdClass $copydata)`, verificado en `backup/util/helper/copy_helper.class.php` de 4.2, 4.5 y 5.1. En 5.1 está bajo `public/`.

- **Entrada:** el objeto que devuelve `\copy_helper::process_formdata()`, con estos campos:
  - `courseid`
  - `fullname`
  - `shortname`
  - `category`
  - `visible = 0`
  - `startdate`
  - `enddate`
  - `idnumber = ''`
  - `userdata = 0`
  - sin roles conservados
- **Qué hace:**
  1. Crea el curso destino de inmediato, con un nombre provisional («Copiando…»).
  2. Encola una tarea `\core\task\asynchronous_copy_task`.
  3. El **cron** ejecuta la tarea: copia de seguridad, restauración en el curso nuevo, aplicación de nombre y fechas, y desplazamiento de las fechas de las actividades al nuevo inicio.
- **Progreso:** se lee con `\copy_helper::get_copies($userid)`, o con los registros de `backup_controllers` de los ids que devolvió `create_copy`.

## 5. Destino en espejo

- **Raíz de semillas:** es la categoría de primer nivel, ancestro de la semilla, clasificada como `semilla` por `sorter::classify(..., toplevel = true)`. Por ejemplo «Facultades».
- **Ruta relativa:** la ruta de la categoría del curso bajo esa raíz, incluida la propia raíz. Por ejemplo «Facultades › Ingeniería › Programa Sistemas».
- **Destino:** el periodo elegido, más la ruta relativa: «2027-01 › Facultades › Ingeniería › Programa Sistemas».
- **Comparación de nombres:** cada nivel se compara con `sorter::normalize()`, sin mayúsculas, tildes ni espacios repetidos, para encontrar la subcategoría existente. Así «Facultad de Ingeniería» y «Facultad de Ingenieria» se toman como la misma.
- **Categorías nuevas:** las que faltan se crean con `core_course_category::create(['name' => <nombre de la semilla>, 'parent' => ...])`, visibles (la visibilidad de los cursos es lo que importa), antes de encolar las copias. Así cada copia tiene su categoría.
- **Si el curso no tiene una raíz de semillas** (por ejemplo, se seleccionó un curso de otra parte): la copia va directamente a la categoría del periodo elegido y la vista previa lo indica.

## 6. Nombres

- **Texto del periodo:** sale de `sorter::parse_period()` aplicado al nombre del periodo destino, en formato `AAAA-PP` (2027-01). Es editable en el panel.
- **Nombre completo:** si el nombre de la semilla ya contiene un periodo, este se **reemplaza**; si no, se agrega « 2027-01» al final.
- **Nombre corto:** las mismas reglas, con «-» como separador.
- **Límites:** nombre completo y nombre corto de hasta 254 caracteres. Lo que exceda se marca como error en la vista previa.
- **Unicidad:** si el nombre corto ya existe en el sitio o se repite en el lote, se marca `shortnametaken` o `shortnameduplicate` y esa fila no se clona.
- **Ya clonada:** si ya existe un curso con ese nombre corto en la categoría destino, la fila se marca `alreadycloned` y se omite. Así relanzar el mismo lote no duplica.
- **El `idnumber`** de las copias queda vacío, porque debe ser único.

## 7. Permisos

Se suman a `tool/ceaorganizador:manage` y se verifican en el servidor para cada curso:

- **En el contexto de la semilla:** todas las capacidades de `\core_course\management\helper::get_course_copy_capabilities()`. Son las mismas que exige `backup/copy.php`.
- **En el contexto de la categoría destino:** `moodle/course:create`.
- **En el padre de cada categoría nueva:** `moodle/category:manage`.

## 8. Funciones web nuevas

| Función | Tipo | Entrada | Salida |
| --- | --- | --- | --- |
| `tool_ceaorganizador_clone_preview` | read | `items` [{type: category\|course, id}]; `periodid`; `periodlabel`; `startdate`; `enddate` | filas: courseid, fullname actual, ruta destino, `newcategories` (rutas que se crearán), `newfullname`, `newshortname`, `error` (nulo o código); además el total y la firma (`hash`) |
| `tool_ceaorganizador_clone_apply` | write | lo mismo + `rows` [{courseid, newfullname, newshortname}] (con las ediciones) + `hash` | `status` (`ok` \| `stale`), `batchid`, `queued`, `skipped` |
| `tool_ceaorganizador_clone_status` | read | `batchid` | por curso: id del curso nuevo, `status` (pending \| running \| done \| failed), enlace |

- `clone_apply` toma el candado de escritura (`guard::lock()`) y vuelve a calcular la vista previa. Si la firma no coincide, devuelve `stale`.
- Crea las categorías que faltan dentro de una transacción. Las copias se encolan después del commit, una por curso: `create_copy` crea el curso provisional y la tarea, y no es transaccional.
- **Límite por lote:** 100 cursos, con el error `toomanyclones`.
- **Historial:** se registra una entrada `clone` con el periodo, las categorías creadas y la lista {semilla → curso nuevo}. Esa entrada **no se puede deshacer**: el botón Deshacer no aparece y `undo` la rechaza con `error_cannotundoclone`.

## 9. Interfaz

- En el menú del nodo de una categoría, y en la barra de selección, aparece **«Clonar a periodo…»**. Abre el panel de clonado, construido sobre `dialog.js` como los demás.
- **Selector de periodo:** lista las categorías clasificadas como `periodo` (de cualquier nivel), con su ruta, de la más reciente a la más antigua.
- **Fechas:** dos campos `date` (inicio y fin). El fin debe ser posterior al inicio.
- **Vista previa:** una tabla con semilla, destino (con la etiqueta «Nueva» en las categorías que se crearán), nombre completo y nombre corto editables, y estado. Las filas con error se deshabilitan.
- **Al confirmar:** aparece el panel **«Copias en curso»**, que consulta `clone_status` cada 15 s mientras quede algo pendiente. Muestra contadores y una lista con el enlace a cada curso nuevo.
- **Aviso de cron:** si nada avanzó en 10 minutos, se muestra «Las copias dependen del cron de Moodle; verifica que esté activo».
- El historial muestra el lote como «Clonado a 2027-01 · cursos: N», sin botón Deshacer, con un botón «Ver avance» que vuelve a abrir el panel de progreso.

## 10. Errores y casos límite

- **Copia fallida:** si la tarea en segundo plano falla, el curso provisional queda en la categoría destino, como en el «Copiar curso» de Moodle. El estado lo marca `failed` con enlace al curso, para revisarlo o borrarlo en la pantalla nativa.
- **Semilla sin permiso:** esa fila se marca y no se clona; las demás siguen.
- **Periodo destino dentro de la raíz de semillas:** se rechaza («El destino no puede estar dentro de las semillas»).
- **Clonar dos veces el mismo lote:** las filas ya clonadas se omiten (sección 6).
- **Lote grande:** el límite es 100. El panel avisa que las copias tardarán según la capacidad del cron.

## 11. Pruebas

- **PHPUnit:**
  - **Espejo:** la ruta relativa, las categorías que se crean, la comparación sin tildes y la falta de raíz de semillas.
  - **Nombres:** reemplazar o agregar el periodo, unicidad y `alreadycloned`.
  - **Permisos:** cada capacidad faltante se rechaza.
  - **`clone_apply`:**
    - encola una tarea `asynchronous_copy_task` por curso, lo que se verifica con `\core\task\manager::get_adhoc_tasks`;
    - crea las categorías;
    - devuelve `stale`;
    - registra el lote en el historial.
  - **Deshacer:** `undo` de un lote `clone` se rechaza.
  - **Prueba de punta a punta:** ejecutar las tareas con `$this->runAdhocTasks()` y comprobar que el curso clonado está oculto, sin usuarios, con nombre y fecha de inicio nuevos, y con las actividades de la semilla.
- **node:test:** construcción de la vista previa, ediciones, filas con error excluidas y el sondeo de estado (con temporizador falso).
- **CI:** la misma matriz, 4.2 a 5.1.

## 12. Para el plan

- Verificar qué contiene `get_course_copy_capabilities()` en 4.2 y 5.1, y si `create_copy` exige `moodle/course:create` por su cuenta.
- Verificar cómo informa `copy_helper::get_copies()` las copias de otro usuario, o si conviene leer `backup_controllers` por `backupid`.
- Verificar que `process_formdata` acepta los campos sin `role_*`.
