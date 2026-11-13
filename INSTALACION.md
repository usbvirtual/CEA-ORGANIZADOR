# Instalación de CEA-ORGANIZADOR

Plugin de Moodle del Centro de Innovación Pedagógica y Educación Digital (CEA), Universidad de San Buenaventura Cali.

- **Requisitos:** Moodle 4.5 o superior y PHP 8.1 a 8.4. Probado en GitHub Actions con Moodle 4.5, 5.0, 5.1, 5.2 y 5.3.
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
- `moodle/course:visibility`, para ocultar o mostrar cursos (y para deshacerlo).

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

Con el foco en el árbol: flechas para navegar; **Espacio** toma el elemento y las flechas eligen el destino (Enter suelta, Esc cancela); **M** abre «Mover a…»; **S** selecciona o deselecciona; **Shift+F10** abre el menú de una categoría.

### Dónde está cada opción

Cada opción aparece en un solo lugar:

- **Barra superior:** «Historial» y «Sugerir correcciones». Al marcar algo aparece la barra de selección: «Renombrar», «Clonar a periodo…», «Auto ordenar seleccionadas», «Ocultar seleccionados», «Mostrar seleccionados», «Borrar seleccionadas» y «Limpiar». Para actuar sobre una sola categoría o un solo curso, márquelo con su casilla (o **S**) y use la barra.
- **Cada fila:** el botón «Mover a…».
- **Menú ⋯ de una categoría:** «Seleccionar contenido» (marca todos sus cursos, también los de sus subcategorías) y «Abrir en Moodle». Los cursos no tienen menú.

### Auto ordenar varias categorías

Seleccione varias categorías (casilla o tecla **S**) y use «Auto ordenar seleccionadas» en la barra de selección. Se ve una sola vista previa con todos los cambios; al aplicarla todo queda en una sola entrada del historial, y un solo «Deshacer» devuelve todas las categorías a su orden anterior. Se comprueba `moodle/category:manage` en cada categoría cuyo contenido cambia.

### Ocultar y mostrar cursos

- Seleccione los cursos y use «Ocultar seleccionados» o «Mostrar seleccionados». Si en la selección también hay categorías, primero se ocultan o muestran ellas con todo lo que tienen dentro (ver abajo) y después los cursos marcados que no están dentro de ellas; si se cancela esa pregunta no se cambia nada. Se envían en lotes de **100** cursos, uno tras otro, con un solo aviso y un solo «Deshacer» que deshace todos los lotes.

Es lo mismo que el ojo de «Gestionar cursos y categorías»: un curso oculto no lo ven los estudiantes. Hace falta `moodle/course:visibility` en **cada** curso. Cada lote de 100 es todo o nada: si falta el permiso en un curso, no se cambia ninguno de su lote, pero los lotes anteriores ya quedaron aplicados (y el aviso «Deshacer» los deshace). Los cursos que ya estaban así se omiten. La etiqueta «Oculta» del árbol se actualiza sin recargar, y aparece el aviso «Deshacer». Deshacer devuelve a cada curso su visibilidad anterior y se rechaza si alguien cambió la visibilidad de alguno después.

### Ocultar todo y mostrar todo

- Seleccione una o varias categorías y use «Ocultar seleccionados» o «Mostrar seleccionados» (el mismo botón que para los cursos). Solo cuentan las categorías de la selección (con todo lo que tienen dentro); una categoría que está dentro de otra seleccionada ya va con ella.

Afecta a la categoría, a **todas** sus subcategorías a cualquier profundidad y a **todos** sus cursos. Antes de aplicar se cuenta lo que de verdad cambia de visibilidad y se pregunta una vez, por ejemplo «Se ocultarán 12 categorías y 340 cursos».

- «Ocultar todo» deja todo como lo dejaría el ojo de Moodle sobre la categoría: la categoría elegida queda oculta a propósito, y si después alguien la muestra desde «Gestionar cursos y categorías», reaparece solo lo que estaba visible antes de ocultar todo; lo que ya estaba oculto (a propósito o porque su categoría estaba oculta) sigue oculto. Para eso, a lo que ya estaba oculto pero habría reaparecido se le ajusta en silencio esa marca; no cuenta como «se ocultará», pero queda en el historial y el deshacer la devuelve. Lo oculto a propósito no se toca.
- «Mostrar todo» deja **todo visible**, también lo que estaba oculto a propósito.

**Permisos.** `moodle/category:manage` en cada categoría que cambia **y en su categoría padre** (en el sistema para las del nivel superior), como en Moodle, y `moodle/course:visibility` en cada curso que cambia; si falta uno solo, no se cambia nada.

**Límites.** Como máximo **500** categorías y **2000** cursos que se escriban por operación (contando los ajustes silenciosos); si son más, se rechaza con los números: trabaje por facultad, programa o semestre. Se escribe en tandas de 200 elementos para no bloquear la tabla de cursos; si se interrumpe a mitad, lo cambiado queda en el historial y el aviso ofrece «Deshacer».

**Deshacer.** El aviso y el historial («Ocultar todo: … · categorías: N, cursos: M») ofrecen «Deshacer», que devuelve a cada categoría y curso su estado exacto anterior. Se rechaza entero, con los conflictos listados (los 20 primeros y cuántos más), si alguien cambió después la visibilidad de alguno o movió alguno de sus cursos a otra categoría. Por ejemplo, si después de ocultar todo un docente vuelve a mostrar su curso, el «Deshacer» de la operación masiva se rechaza; en ese caso, use «Mostrar todo».

### Historial y deshacer

Cada movimiento, orden, renombrado y cambio de visibilidad queda en el historial, y aparece un aviso «Deshacer» durante 8 segundos. El historial se conserva 90 días; una tarea programada borra lo más viejo. Deshacer devuelve el estado anterior, incluida la visibilidad si el movimiento la cambió. **Se rechaza si algo cambió después de la operación** (por ejemplo, otra persona movió o renombró el elemento) y se indica qué cambió; en ese caso no se modifica nada.

«Unificar categorías» y «Quitar repetidos» ya no están en el organizador. Lo que se hizo con ellas antes sigue en el historial y se puede deshacer como siempre.

### Sugerir correcciones

Propone nombres corregidos para categorías y cursos y deja aplicarlos de una vez. No cambia nada hasta que se confirma.

**Dónde está.** En la barra del organizador, «Sugerir correcciones». Con una sola categoría seleccionada revisa esa categoría y lo que hay debajo; si no, todo el sitio. La casilla «Incluir cursos» decide si también se revisan los cursos. La lista muestra cada cambio con el nombre actual, el sugerido, el motivo y la categoría donde está; cada fila se puede desmarcar.

**Las cuatro reglas.** Se aplican en este orden y el motivo queda marcado en la fila:

1. **Formato:** limpia espacios de más y deja los guiones con espacios cuando ya tenían espacio a algún lado (`Ingeniería   de  Sistemas` pasa a `Ingeniería de Sistemas`). No toca periodos como `2026-2` ni códigos como `Ing-Sist`.
2. **Glosario:** reemplaza palabras según las reglas del glosario institucional (ver abajo). Es por palabra completa y no distingue mayúsculas.
3. **Tilde:** agrega la tilde a una palabra escrita sin ella cuando el propio sitio la escribe con tilde (`Ingenieria` pasa a `Ingeniería`).
4. **Variante:** unifica singular y plural entre categorías o cursos hermanos (`Ciencia Política` y `Ciencias Política`; ver los límites abajo).

**Dos límites con las tildes.**

- La tilde solo se agrega si el sitio escribe la palabra con tilde **al menos dos veces** y **más veces** que sin ella. Si hay empate, no se cambia.
- Las palabras que cambian de significado con la tilde **nunca** se tildan: si, mas, el, tu, te, se, de, mi, solo, aun, que, como, cuando, donde, quien, cual, cuanto, este, esta, ese, esa, aquel, o. Una tilde equivocada es peor que ninguna; si alguna hace falta, se corrige con el glosario.

**Variantes.** Solo se unifican nombres hermanos (mismo padre en las categorías; misma categoría en los cursos) que sean iguales salvo por **una** palabra que cambia de singular a plural (se agrega `s` o `es`). Gana la forma que más se usa en el sitio; si empatan, el plural. Se copia la palabra tal como está escrita en el sitio.

**Glosario.** Ajuste *Glosario institucional*, en *Administración del sitio › Cursos › Ajustes del organizador de categorías*. Una regla por línea, con la forma `incorrecto => correcto`; las líneas sin `=>` o con un lado vacío se ignoran. Ejemplo: `Cien => Ciencias`.

**Qué no cambia.** Solo cambian los **nombres completos**. Los nombres cortos de los cursos nunca se tocan, y los nombres de curso conservan su docente, grupo y periodo. Los nombres con etiquetas multilenguaje tampoco se tocan.

**Límites y deshacer.** Se muestran hasta **2000** sugerencias; si hay más, el panel lo avisa: aplique estas y vuelva a abrir el panel para ver el resto. Aplicar va en lotes de **100**, queda en el historial como renombrado y se puede deshacer (el aviso «Deshacer» deshace todos los lotes aplicados). Aplicar usa los mismos permisos que «Renombrar». Cada lote es todo o nada: si un cambio de un lote falla (por ejemplo, falta un permiso o un nombre corto se ocupó), no se aplica nada de ese lote, pero los lotes anteriores ya quedaron aplicados. Lo mismo vale para «Renombrar» con más de 100 elementos.

### Borrar categorías

Borra categorías **vacías**, una o varias a la vez. Nunca borra cursos ni contenido.

**Dónde está.** «Borrar seleccionadas» en la barra de selección (actúa solo sobre las categorías seleccionadas; los cursos seleccionados se ignoran).

**Qué es vacía.** Sin cursos, sin subcategorías y sin nada propio que Moodle borraría o movería al borrarla: elementos de la papelera de reciclaje, del banco de contenidos, eventos, letras de calificación, categorías de preguntas (cualquiera, incluso una «top» vacía), asignaciones de roles, permisos definidos en la categoría, cohortes, bloques, archivos (también las imágenes de la descripción), comentarios, filtros ni marcos o plantillas de competencias. Tampoco se borra la categoría por defecto de las solicitudes de curso. Así nunca se pierde nada. Si se seleccionan una categoría y todas sus subcategorías vacías, se borran juntas, de la más profunda a la más superficial.

**Confirmación.** Antes de borrar, el diálogo lista lo que se borrará y lo que se conservará, con el motivo: tiene cursos, tiene subcategorías, tiene contenido propio, no tienes permiso o ya no existe. Las que se conservan nunca se borran. Al terminar, las borradas salen de la selección; lo demás (cursos incluidos) sigue seleccionado.

**Permisos.** Además de `tool/ceaorganizador:manage`: `moodle/category:manage` en la categoría y en su categoría padre (en el sistema si es de nivel superior), como exige Moodle. Sin ese permiso la categoría se conserva.

**Deshacer.** Queda una entrada en el historial («Borrar: …») y se puede deshacer: las categorías se vuelven a crear con su nombre, número de ID, descripción, visibilidad, tema (si lo tenían) y, en lo posible, su posición entre las hermanas. **Se crean con ids nuevos**: los enlaces o informes que usaban el id anterior no las encuentran. El tema solo se recupera si el sitio permite temas por categoría. Una categoría oculta a propósito vuelve oculta; una oculta solo porque su padre lo estaba vuelve con la visibilidad del padre. Deshacer se rechaza, sin cambiar nada, si la categoría padre original ya no existe, si otra categoría usa ahora su número de ID o si en su lugar ya hay otra con el mismo nombre (sin importar tildes ni mayúsculas).

**Límite.** Máximo **200** categorías por lote (un solo registro y un solo «Deshacer»); si se seleccionan más, el diálogo lo avisa y no envía nada.

### Clonar semillas a un periodo

Copia cursos semilla (plantillas) a una categoría de periodo, por ejemplo «2027-01», sin hacerlo uno por uno. Se recomienda hacerlo después de «Sugerir correcciones».

**Requisito: el cron de Moodle debe estar activo.** Las copias las hace la tarea ad hoc `\core\task\asynchronous_copy_task` (no es una tarea programada), que el cron ejecuta. Las copias que esperan se ven en *Administración del sitio › Servidor › Tareas › Tareas ad hoc* (página `admin/tool/task/adhoctasks.php`; el texto del menú puede variar según la versión y el paquete de idioma). Sin cron, las copias quedan «Pendiente» y no avanzan. Si pasan unos 10 minutos sin progreso, el panel muestra el aviso «Las copias dependen del cron de Moodle; verifica que esté activo.».

**Dónde está.** En la barra de selección, la opción «Clonar a periodo…».

**Cómo se usa.**

1. Elegir las semillas: una o varias categorías o cursos seleccionados. Para clonar un solo curso, seleccionarlo y usar «Clonar a periodo…» en la barra de selección.
2. En el panel, elegir el «Periodo de destino», escribir la «Etiqueta del periodo» (formato AAAA-01 o AAAA-02, por ejemplo `2027-01`), revisar la «Carpeta bajo el periodo» (opcional, ver abajo) y, si se quiere, poner las fechas de inicio y de fin. Las fechas son opcionales: sin fechas, cada copia conserva las de su curso semilla; con solo la de inicio, el curso queda sin fecha de fin.
3. Pulsar «Vista previa»: muestra, por cada curso, la semilla, el destino, el nombre de la copia y el estado («Nueva», o el motivo por el que se omite).
4. Pulsar «Clonar cursos (N)». Solo se clonan las filas que el panel envía: una fila con un nombre vacío o con un motivo de omisión no se envía y no se clona. Se abre el panel «Copias en curso», que consulta el avance cada 15 segundos y marca cada copia como «Pendiente», «Listo» o «Fallido». Si una consulta falla, se reintenta a los 15, 30 y 60 segundos antes de mostrar el error. Para volver a ver el avance más tarde, abrir el historial y pulsar «Ver avance» en la entrada del clonado.

**Qué se copia.** Solo el contenido: actividades, recursos, secciones, bancos de preguntas y ajustes del curso. **No** se copian usuarios, matrículas, calificaciones ni entregas. Los cursos nuevos se crean **ocultos**; hay que mostrarlos cuando estén listos.

**Destino en espejo.** Cada copia se ubica bajo el periodo elegido con la misma ruta que tiene la semilla **debajo** de su raíz de semillas (la categoría de nivel superior que coincide con los patrones de semilla): la ruta empieza en la facultad y la raíz no se reproduce. Por ejemplo, un curso semilla de la categoría `Semillas › Ingeniería › Programa Sistemas` clonado al periodo `2027-01` queda en `2027-01 › Ingeniería › Programa Sistemas`. Un curso que está directamente en la raíz de semillas, o que no está bajo ninguna, va a la categoría del periodo (o a la carpeta, si se indicó). Las categorías intermedias que no existan en el periodo se crean (al final entre sus hermanas); las que ya existen se reutilizan. El destino no puede estar dentro de las semillas.

**Carpeta bajo el periodo.** Campo opcional del panel. Si se escribe un nombre, por ejemplo `Facultades`, las rutas cuelgan de esa subcategoría del periodo: `2027-01 › Facultades › Ingeniería › Programa Sistemas`. Si no existe, se crea; si ya existe una con el mismo nombre (sin importar tildes ni mayúsculas, por ejemplo «FACULTADES»), se reutiliza. Al elegir el periodo, el panel propone como carpeta la única subcategoría del periodo que contiene categorías cuyo nombre empieza por «Facultad»; si no hay ninguna o hay varias, el campo queda vacío. No puede ser un nombre de periodo (por ejemplo `2027-01`).

**Nombres.**

- Nombre completo: el periodo que ya tenga se quita y la etiqueta se agrega al final, después de un espacio; si el nombre era solo el periodo, queda la etiqueta sola (`Curso 2026-02 Grupo A` pasa a `Curso Grupo A 2027-01`).
- Nombre corto: igual, pero con guion (`CALC1-2026-02` pasa a `CALC1-2027-01`; `CALC1` pasa a `CALC1-2027-01`).
- Límite de Moodle: 254 caracteres; un nombre más largo se marca «El nombre es demasiado largo.» y no se clona.
- Si el nombre corto ya existe en el sitio o se repite en el lote, la fila se omite.

**Fechas.** Moodle desplaza las fechas de las actividades según la nueva fecha de inicio del curso.

**Límite y relanzar.** Máximo **100 cursos por lote**; si hay más, el servidor lo rechaza. Relanzar el mismo lote es seguro: las copias en cola o en curso de los últimos 30 días se omiten, y las ya terminadas se omiten porque su nombre corto ya existe en esa categoría y aparecen como «Ya está clonado en este periodo.».

**No se puede deshacer.** El historial muestra el lote («Clonado a 2027-01 · cursos: N»), pero «Deshacer» no aplica a los clonados. Para quitar copias que sobren, borrarlas desde la gestión de cursos de Moodle (*Administración del sitio › Cursos › Gestionar cursos y categorías*). Las categorías que se crearon quedan; se pueden borrar igual.

**Permisos.** Además de `tool/ceaorganizador:manage`, se comprueban:

- En la semilla: `moodle/backup:backupcourse`, `moodle/restore:restorecourse` y `moodle/course:create`.
- En la categoría de destino: `moodle/course:create` y `moodle/restore:restorecourse` (más estricto que el núcleo, que lo exige en el curso de destino).
- En la categoría existente de la que cuelgan las categorías nuevas: `moodle/category:manage`.

Si falta un permiso en una semilla, esa fila se omite y las demás se clonan. Si falta un permiso en el destino, la petición se rechaza entera y no se crea nada.

**Si una copia falla.** El panel la marca «Fallido» y las demás siguen. Moodle puede dejar un curso provisional «Copiando curso…» en la categoría de destino; hay que borrarlo a mano. Mientras ese curso provisional exista, la copia sigue contando como en curso y aparece como «Ya está clonado en este periodo.»; por eso hay que borrarlo antes de relanzar. Después se puede relanzar el lote: solo se reintentan las que fallaron.

### Rendimiento: buenas prácticas

En un sitio grande y con mucho uso (como eCampus, con miles de cursos y muchos docentes y estudiantes conectados a la vez):

- **Operaciones masivas fuera de las horas pico.** «Auto ordenar» del nivel superior (o de todo el sitio) y «Clonar a periodo» reescriben el orden de muchas categorías, mueven muchos cursos o encolan copias; cada una purga cachés de cursos y categorías de todo el sitio. Conviene hacerlas temprano, de noche o en fin de semana.
- **Renombrar y ocultar o mostrar cursos va en lotes de 100.** Cada curso pasa por la función del núcleo que actualiza el curso y purga cachés; los lotes cortos evitan peticiones largas. El panel parte la lista solo.
- **Clonar en lotes.** Cada copia es una tarea en segundo plano (copia de seguridad y restauración). Para que el cron no corra varias copias a la vez y no cargue el servidor, se recomienda limitarlas a una a la vez en `config.php`:

  ```php
  $CFG->task_concurrency_limit = ['\core\task\asynchronous_copy_task' => 1];
  ```

### Pruebas seguras

Probar primero en una categoría de prueba, nunca sobre periodos activos. Usar las vistas previas de «Auto ordenar» y «Renombrar» antes de confirmar, y comprobar que «Deshacer» funciona con una operación pequeña antes de organizar una sede completa.

---

*Texto pendiente de revisión editorial.*
