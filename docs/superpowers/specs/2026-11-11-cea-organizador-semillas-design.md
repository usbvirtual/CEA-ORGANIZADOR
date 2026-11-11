# CEA-ORGANIZADOR · Sugerir correcciones, Unificar semillas y ajuste del clonado · Especificación

- **Fecha:** 2026-11-11
- **Estado:** diseño aprobado por el usuario en la conversación.
- **Amplía:** las especificaciones `2026-11-10-cea-organizador-design.md` y `2026-11-10-cea-organizador-clonado-design.md`. El plugin requiere Moodle 4.5 o superior y se declara compatible hasta Moodle 5.3.

## 1. Sugerir correcciones

**Propósito:** formalizar los nombres de categorías y cursos sin perder información.

### Alcance
- **Categorías:** se formaliza el nombre.
- **Cursos:** se formaliza solo el nombre completo. Se conserva todo lo que el nombre ya incluye: docente, grupo, hora y periodo.
- **Nombre corto:** nunca se toca.
- **Facultad, programa y semestre:** se usan solo para agrupar las sugerencias en pantalla.

### Reglas, de más segura a menos segura
Cada sugerencia indica qué regla la produjo.

1. **Formato** (`formato`). Es `renamer::apply($name, [['type' => 'clean', 'case' => 'none']])`, que corrige:
   - espacios repetidos o sobrantes;
   - el espaciado alrededor de los guiones;
   - romanos escritos con «l» en lugar de «I».

   No cambia las mayúsculas.
2. **Tildes aprendidas del sitio** (`tilde`).
   - Se arma un vocabulario a partir de todos los nombres de categorías y cursos del sitio: cada palabra se agrupa por su forma sin tildes y en minúsculas.
   - Si una palabra del nombre no lleva tilde y en ese grupo existe una forma con tilde que aparece al menos 2 veces y es la más frecuente del grupo, se propone esa forma.
   - Se conservan las mayúsculas de la palabra original.
   - Ejemplos: «Ingenieria» → «Ingeniería», «Estadistica» → «Estadística».
   - No se inventan formas: solo se proponen palabras que ya existen escritas así en el sitio.
3. **Variantes entre categorías hermanas** (`variante`).
   - Se compara cada categoría con sus hermanas (mismo padre).
   - Si una hermana tiene el mismo nombre salvo por el plural de una palabra, se propone unificar hacia la forma más usada entre todos los hermanos del sitio. Se cubren dos plurales: agregar «s» o «es», y «-ción» frente a «-ciones».
   - Ejemplos: «Posgrado» frente a «Posgrados», «Especialización» frente a «Especializaciones».
   - Si hay empate, gana el plural.
4. **Glosario institucional** (`glosario`).
   - Es el ajuste `glossary`: un área de texto con una regla `incorrecto => correcto` por línea.
   - El reemplazo es por palabra completa y no distingue mayúsculas.
   - Ejemplos: `Cien => Ciencias`, `Perfomance => Performance`, `Internaciones => Internacionales`.
   - Por defecto está vacío.

Las reglas se aplican en cadena sobre el mismo nombre, en este orden: formato, glosario, tildes, variante. Una sugerencia aparece solo si el nombre final es distinto del actual.

### Servicio
`tool_ceaorganizador_suggest_preview(categoryid: int (0 = todo el sitio), includecourses: bool)` (lectura).

- Devuelve `{rows: [{type, id, old (crudo), new (crudo), reasons: string[], group (ruta formateada)}], total}`.
- Como máximo 2000 filas, ordenadas por grupo y luego por nombre.

### Aplicación
Se reutiliza `tool_ceaorganizador_rename_apply` con las filas que el usuario acepta (`changes{type, id, old, new}`). Eso da:
- límite de 500 por lote;
- una sola entrada en el historial;
- deshacer;
- comprobación de conflictos.

### Interfaz
- Un botón «Sugerir correcciones» en la barra superior, que revisa todo el sitio.
- La opción «Sugerir correcciones» en el menú de cada categoría, que revisa solo esa rama.
- El panel agrupa las sugerencias por ruta y muestra en cada fila:
  - una casilla, marcada por defecto;
  - el nombre actual;
  - el nombre nuevo, editable;
  - los motivos.
- Botones «Aplicar seleccionadas (N)» y «Marcar/desmarcar todo».
- Si hay más de 500 cambios, se aplican en lotes con aviso, como en el renombrado.

## 2. Unificar semillas

**Propósito:** reunir en una sola categoría de semillas, con la estructura Facultad › Programa › Semestre, los cursos semilla que hoy están repartidos entre varias categorías raíz (por ejemplo «Facultades» y «Semillas»), sin crear categorías repetidas.

### Entrada
- **`targetid`:** la categoría destino. Debe existir. Si el administrador quiere una nueva, la crea primero con el botón «Crear categoría de semillas» del panel, que crea una categoría de primer nivel con el nombre escrito (por defecto «Semillas»).
- **`sourceids`:** las categorías origen, una o varias. No pueden ser el destino ni contenerlo.

### Mapeo
1. Para cada curso dentro de un origen (en cualquier nivel) se calcula la ruta relativa: los nombres desde el hijo del origen hasta la categoría del curso.
2. Esa ruta se resuelve bajo el destino con `cloner::resolve(..., targetid, relative)`, que empareja nombres sin importar tildes ni mayúsculas.
3. Las categorías que faltan se crean y se marcan como «Nueva».

### Repetidos
- Un curso es **posible repetido** si en la categoría destino resuelta, cuando ya existe, hay un curso con el mismo nombre completo normalizado (`sorter::normalize`).
- Esa fila tiene la acción `skip` por defecto y la marca `duplicate`. El usuario puede cambiarla a `move`.
- El plugin nunca borra cursos.

### Servicios
- **`tool_ceaorganizador_merge_preview(targetid, sourceids[])`** (lectura). Devuelve:
  - `{rows: [{courseid, fullname, from (ruta), to (ruta), newcategories[], duplicate: bool, action: 'move'|'skip'}], emptied: [rutas de categorías que quedarían vacías], hash}`;
  - como máximo 1000 cursos; si hay más, error `error_toomanymerge`.
- **`tool_ceaorganizador_merge_apply(targetid, sourceids[], rows[{courseid, action}], hash)`** (escritura).
  - Usa candado, revisa la firma y devuelve `stale` si cambió.
  - Exige `moodle/category:manage` en el destino, en cada origen y en cada padre de las categorías nuevas, y `moodle/course:changecategory` en cada curso que se mueve.
  - En **una transacción**: crea las categorías nuevas, mueve los cursos con `move_courses` y registra una entrada `merge` en el historial.
  - Devuelve `{status, logid, moved, skipped, emptied: [{id, path}]}`.

### Historial y deshacer
- La acción `merge` guarda `{created: [ids], items: [{id, fromcategory, tocategory}]}`.
- Deshacer:
  1. Comprueba conflictos: un curso que ya no está en `tocategory`, o un origen que ya no existe.
  2. Devuelve cada curso a su categoría original.
  3. Borra las categorías creadas que quedaron vacías, de la más profunda a la más alta, con `core_course_category::delete_full(false)` y solo si no tienen cursos ni subcategorías.

### Interfaz
- El panel «Unificar semillas» se abre desde la barra superior o desde el menú de una categoría (esa categoría queda como origen).
- En el panel se eligen el destino y los orígenes, con un selector como el de «Mover a…».
- Una tabla muestra por curso: el curso, de dónde sale, a dónde va (con la marca «Nueva» si se crea la categoría), y «Repetido» con un interruptor para mover u omitir.
- Botón «Unificar (N cursos)».
- Al final se listan las categorías que quedaron vacías, con un enlace a la gestión de Moodle para borrarlas.

## 3. Ajuste de «Clonar a periodo»

- La ruta en espejo deja de incluir la raíz de semillas. `cloner::mirror` devuelve en `relative` los nombres desde el hijo de la raíz hasta la categoría del curso.
- **Carpeta bajo el periodo:**
  - `clone_preview` y `clone_apply` aceptan el parámetro `container`, de tipo `PARAM_TEXT` y con valor por defecto `''`.
  - Con `''`, la ruta cuelga directamente del periodo.
  - Con un nombre, cuelga de esa subcategoría del periodo, que se crea si no existe (se empareja por nombre normalizado).
  - Ejemplo: con `container = 'Facultades'`, el destino es `2027-01 › Facultades › Facultad de Ingeniería › …`.
- **Panel de clonado:** se agrega el campo «Carpeta bajo el periodo». Su valor por defecto es el nombre de la única hija del periodo que contenga categorías de facultad, si existe; si no, queda vacío.
- **Compatibilidad:**
  - el parámetro `container` entra en la firma de la vista previa;
  - la detección de copias en curso y de «ya clonado» no cambia, porque compara nombres cortos dentro de la categoría destino.

## 4. Pruebas

- **PHPUnit:**
  - cada regla de sugerencia: formato, tildes (incluye el caso en que una forma con tilde aparece una sola vez y no se propone), variantes, glosario y el encadenado de reglas;
  - el servicio de sugerencias;
  - unificación: mapeo, emparejamiento con tildes, repetidos, permisos, firma vencida (`stale`) y una transacción;
  - deshacer una unificación, con conflicto y con borrado de las categorías creadas;
  - clonado con `container` y sin él;
  - `mirror` sin la raíz.
- **node:test:** los paneles de sugerencias y de unificación, y el campo de carpeta del clonado.
- **CI:** Moodle 4.5 a 5.3 y `main`, con MariaDB y PostgreSQL.
