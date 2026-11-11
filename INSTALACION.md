# Instalación de CEA-ORGANIZADOR

Plugin de Moodle del Centro de Innovación Pedagógica y Educación Digital (CEA), Universidad de San Buenaventura Cali.

- **Requisitos:** Moodle 4.2 o superior y PHP 8.0 a 8.4. Probado en GitHub Actions con Moodle 4.2, 4.5, 5.0 y 5.1.
- **Carpeta de instalación:** `admin/tool/ceaorganizador`.

## Opción A · Desde la interfaz

1. *Administración del sitio › Extensiones › Instalar extensiones*.
2. Subir `CEA-ORGANIZADOR.zip` y seguir el asistente hasta «Actualizar la base de datos de Moodle ahora».
3. *Administración del sitio › Desarrollo › Purgar todas las cachés*.

## Opción B · Por servidor

1. Descomprimir el ZIP en `admin/tool/` (queda `admin/tool/ceaorganizador/`). En Moodle 5.1 o superior, comprobar si el código del sitio está dentro de `public/`; en ese caso, la ruta es `public/admin/tool/ceaorganizador/`.
2. Ejecutar:
   ```bash
   php admin/cli/upgrade.php --non-interactive
   php admin/cli/purge_caches.php
   ```

## Actualizar

Se instala igual, encima de la versión anterior. Moodle no permite instalar una versión anterior sobre una más nueva. **Purgar las cachés siempre**: sin eso, el tema no carga los estilos ni el JavaScript nuevos.

## Después de instalar

### Dónde aparece

*Administración del sitio › Cursos › Organizador de categorías (CEA)*, justo debajo de «Administrar cursos y categorías». Los ajustes están en *Administración del sitio › Cursos › Ajustes del organizador de categorías*.

### Quién lo usa y qué permisos lo controlan

La herramienta es solo para los **administradores del sitio**: la página se registra en el árbol de administración únicamente para quien puede configurar el sitio (`moodle/site:config`), así que nadie más llega a ella.

La capacidad `tool/ceaorganizador:manage`, en el contexto del sistema, es un control adicional que los administradores ya tienen. No se asigna a ningún rol por defecto, y darla a quien no es administrador **no** le da acceso a la página. No la asigne a otros roles: los servicios web del organizador comprueban esta capacidad. Además, en cada operación el servidor comprueba los permisos nativos de Moodle:

- `moodle/category:manage`, en la categoría de origen y en la de destino.
- `moodle/course:changecategory`, para mover cursos.
- `moodle/course:update`, para renombrar cursos.

Si falta alguno, la operación se rechaza y no cambia nada.

### Ajustes de patrones

Sirven para que «Auto ordenar» reconozca qué es una categoría semilla y una facultad. Los periodos no se configuran: se detectan solos por el nombre («2026-02», «2026 -1», «2026-2», «2026 II») y se ordenan del más reciente al más antiguo. Un patrón por línea. Se comparan contra el nombre de la categoría en minúsculas y sin tildes.

| Sintaxis | Qué hace | Ejemplo |
| --- | --- | --- |
| `texto` | El nombre contiene el texto. | `semilla` |
| `=texto` | El nombre es exactamente el texto. | `=Facultades` |
| `/expresión/` | Expresión regular sobre el nombre. | `/^facultad/` |

Valores por defecto:

- **Patrones de semilla:** `semilla`, `plantilla` y `=Facultades`.
- **Patrones de facultad:** `/^facultad/` y `/^programa/`.

### Atajos de teclado

Con el foco en el árbol: flechas para navegar; **Espacio** toma el elemento y las flechas eligen el destino (Enter suelta, Esc cancela); **M** abre «Mover a…»; **S** selecciona o deselecciona; **Shift+F10** abre el menú del nodo.

### Historial y deshacer

Cada movimiento, orden y renombrado queda en el historial, y aparece un aviso «Deshacer» durante 8 segundos. El historial se conserva 90 días; una tarea programada borra lo más viejo. Deshacer devuelve el estado anterior, incluida la visibilidad si el movimiento la cambió. **Se rechaza si algo cambió después de la operación** (por ejemplo, otra persona movió o renombró el elemento) y se indica qué cambió; en ese caso no se modifica nada.

### Clonar semillas a un periodo

Copia cursos semilla (plantillas) a una categoría de periodo, por ejemplo «2027-01», sin hacerlo uno por uno.

**Requisito: el cron de Moodle debe estar activo.** Las copias las hace la tarea programada `\core\task\asynchronous_copy_task`. Sin cron, las copias quedan «Pendiente» y no avanzan. Si pasan unos 10 minutos sin progreso, el panel muestra el aviso «Las copias dependen del cron de Moodle; verifica que esté activo.».

**Dónde está.** En el menú del nodo de una categoría y en la barra de selección, la opción «Clonar a periodo…».

**Cómo se usa.**

1. Elegir las semillas (un curso, una categoría o una selección).
2. En el panel, elegir el «Periodo de destino», escribir la «Etiqueta del periodo» (formato AAAA-PP, por ejemplo `2027-01`) y las fechas de inicio y de fin.
3. Pulsar «Vista previa»: muestra, por cada curso, la semilla, el destino, el nombre de la copia y el estado («Nueva», o el motivo por el que se omite).
4. Pulsar «Clonar N cursos». Se abre el panel «Copias en curso», que consulta el avance cada 15 segundos y marca cada copia como «Pendiente», «Listo» o «Fallido».

**Qué se copia.** Solo el contenido: actividades, recursos, secciones, bancos de preguntas y ajustes del curso. **No** se copian usuarios, matrículas, calificaciones ni entregas. Los cursos nuevos se crean **ocultos**; hay que mostrarlos cuando estén listos.

**Destino en espejo.** Cada copia se ubica bajo el periodo elegido con la misma ruta que tiene la semilla, empezando en su raíz de semillas. Por ejemplo, un curso semilla de la categoría `Facultades › Ingeniería › Programa Sistemas` clonado al periodo `2027-01` queda en `2027-01 › Facultades › Ingeniería › Programa Sistemas`. La raíz de semillas (aquí «Facultades») se reproduce como primer nivel bajo el periodo. Si el curso no está bajo una raíz de semillas, la copia va directamente a la categoría del periodo. Las categorías intermedias que no existan en el periodo se crean (al final entre sus hermanas); las que ya existen se reutilizan. El destino no puede estar dentro de las semillas.

**Nombres.**

- Nombre completo: el periodo que ya tenga se quita y la etiqueta se agrega al final, después de un espacio; si el nombre era solo el periodo, queda la etiqueta sola (`Curso 2026-02 Grupo A` pasa a `Curso Grupo A 2027-01`).
- Nombre corto: igual, pero con guion (`CALC1-2026-02` pasa a `CALC1-2027-01`; `CALC1` pasa a `CALC1-2027-01`).
- Límite de Moodle: 254 caracteres; un nombre más largo se marca «El nombre es demasiado largo.» y no se clona.
- Si el nombre corto ya existe en el sitio o se repite en el lote, la fila se omite.

**Fechas.** Moodle desplaza las fechas de las actividades según la nueva fecha de inicio del curso.

**Límite y relanzar.** Máximo **100 cursos por lote**; si hay más, el servidor lo rechaza. Relanzar el mismo lote es seguro: las copias en cola o en curso de los últimos 30 días se omiten, y las ya terminadas se omiten porque su nombre corto ya existe en esa categoría y aparecen como «Ya está clonado en este periodo.».

**No se puede deshacer.** El historial muestra el lote («Clonado a 2027-01 · N cursos»), pero «Deshacer» no aplica a los clonados. Para quitar copias que sobren, borrarlas desde la gestión de cursos de Moodle (*Administración del sitio › Cursos › Gestionar cursos y categorías*). Las categorías que se crearon quedan; se pueden borrar igual.

**Permisos.** Además de `tool/ceaorganizador:manage`, se comprueban:

- En la semilla: `moodle/backup:backupcourse`, `moodle/restore:restorecourse` y `moodle/course:create`.
- En la categoría de destino: `moodle/course:create` y `moodle/restore:restorecourse` (más estricto que el núcleo, que lo exige en el curso de destino).
- En la categoría existente de la que cuelgan las categorías nuevas: `moodle/category:manage`.

Si falta un permiso en una semilla, esa fila se omite y las demás se clonan. Si falta un permiso en el destino, la petición se rechaza entera y no se crea nada.

**Si una copia falla.** El panel la marca «Fallido» y las demás siguen. Moodle puede dejar un curso provisional «Copiando curso…» en la categoría de destino; hay que borrarlo a mano. Mientras ese curso provisional exista, la copia sigue contando como en curso y aparece como «Ya está clonado en este periodo.»; por eso hay que borrarlo antes de relanzar. Después se puede relanzar el lote: solo se reintentan las que fallaron.

### Pruebas seguras

Probar primero en una categoría de prueba, nunca sobre periodos activos. Usar las vistas previas de «Auto ordenar» y «Renombrar» antes de confirmar, y comprobar que «Deshacer» funciona con una operación pequeña antes de organizar una sede completa.

---

*Texto pendiente de revisión editorial.*
