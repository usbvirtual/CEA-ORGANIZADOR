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

Con el foco en el árbol: flechas para navegar; **Espacio** toma el elemento y las flechas eligen el destino (Enter suelta, Esc cancela); **M** abre «Mover a…»; **S** selecciona o deselecciona; **Shift+F10** abre el menú del nodo.

### Auto ordenar varias categorías

Seleccione varias categorías (casilla o tecla **S**) y use «Auto ordenar seleccionadas» en la barra de selección. Se ve una sola vista previa con todos los cambios; al aplicarla todo queda en una sola entrada del historial, y un solo «Deshacer» devuelve todas las categorías a su orden anterior. Se comprueba `moodle/category:manage` en cada categoría cuyo contenido cambia.

### Ocultar y mostrar cursos

- **Un curso:** en el menú del curso (⋯ o Shift+F10) aparece «Ocultar» o «Mostrar», según su estado.
- **Varios cursos:** seleccione los cursos y use «Ocultar seleccionados» o «Mostrar seleccionados». Solo cuentan los cursos de la selección; las categorías seleccionadas se ignoran. Se envían en lotes de **100** cursos, uno tras otro, con un solo aviso y un solo «Deshacer» que deshace todos los lotes.

Es lo mismo que el ojo de «Gestionar cursos y categorías»: un curso oculto no lo ven los estudiantes. Hace falta `moodle/course:visibility` en **cada** curso; si falta en uno solo, no se cambia ninguno. Los cursos que ya estaban así se omiten. La etiqueta «Oculta» del árbol se actualiza sin recargar, y aparece el aviso «Deshacer». Deshacer devuelve a cada curso su visibilidad anterior y se rechaza si alguien cambió la visibilidad de alguno después.

### Ocultar todo y mostrar todo

- **Una categoría:** en su menú (⋯ o Shift+F10), «Ocultar todo» o «Mostrar todo».
- **Varias:** seleccione las categorías y use «Ocultar todo lo seleccionado» o «Mostrar todo lo seleccionado». Solo cuentan las categorías de la selección (con todo lo que tienen dentro); una categoría que está dentro de otra seleccionada ya va con ella.

Afecta a la categoría, a **todas** sus subcategorías a cualquier profundidad y a **todos** sus cursos. Antes de aplicar se cuenta lo que de verdad cambia y se pregunta una vez, por ejemplo «Se ocultarán 12 categorías y 340 cursos»; lo que ya está en ese estado no se toca.

- «Ocultar todo» deja la categoría elegida oculta a propósito, igual que el ojo de Moodle: si después alguien la muestra desde «Gestionar cursos y categorías», reaparece lo que se ocultó con ella, y lo que ya estaba oculto sigue oculto.
- «Mostrar todo» deja **todo visible**, también lo que estaba oculto a propósito.

**Permisos.** `moodle/category:manage` en cada categoría que cambia y `moodle/course:visibility` en cada curso que cambia; si falta uno solo, no se cambia nada.

**Límites.** Como máximo **500** categorías y **2000** cursos que cambien por operación; si son más, se rechaza con los números: trabaje por facultad, programa o semestre. Se escribe en tandas de 200 elementos para no bloquear la tabla de cursos; si se interrumpe a mitad, lo cambiado queda en el historial y el aviso ofrece «Deshacer».

**Deshacer.** El aviso y el historial («Ocultar todo: … · categorías: N, cursos: M») ofrecen «Deshacer», que devuelve a cada categoría y curso su estado exacto anterior. Se rechaza, con los conflictos listados, si alguien cambió después la visibilidad de alguno.

### Historial y deshacer

Cada movimiento, orden, renombrado y cambio de visibilidad queda en el historial, y aparece un aviso «Deshacer» durante 8 segundos. El historial se conserva 90 días; una tarea programada borra lo más viejo. Deshacer devuelve el estado anterior, incluida la visibilidad si el movimiento la cambió. **Se rechaza si algo cambió después de la operación** (por ejemplo, otra persona movió o renombró el elemento) y se indica qué cambió; en ese caso no se modifica nada.

### Sugerir correcciones

Propone nombres corregidos para categorías y cursos y deja aplicarlos de una vez. No cambia nada hasta que se confirma.

**Dónde está.** En la barra del organizador, «Sugerir correcciones» (revisa todo el sitio), y en el menú del nodo de una categoría (revisa esa categoría y lo que hay debajo). La casilla «Incluir cursos» decide si también se revisan los cursos. La lista muestra cada cambio con el nombre actual, el sugerido, el motivo y la categoría donde está; cada fila se puede desmarcar.

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

**Límites y deshacer.** Se muestran hasta **2000** sugerencias; si hay más, el panel lo avisa: aplique estas y vuelva a abrir el panel para ver el resto. Aplicar va en lotes de **100**, queda en el historial como renombrado y se puede deshacer (el aviso «Deshacer» deshace todos los lotes aplicados). Aplicar usa los mismos permisos que «Renombrar».

### Unificar categorías

Reúne en una sola categoría de semillas los cursos que están repartidos en varias categorías de origen (por ejemplo, las semillas de cada sede), conservando la estructura de carpetas.

**Orden recomendado.** Primero «Sugerir correcciones», luego «Unificar categorías» y al final «Clonar a periodo». Así se unifican nombres ya corregidos (los repetidos se detectan por el nombre) y las copias salen con los nombres bien escritos.

**Dónde está.** En la barra del organizador, «Unificar categorías», y en el menú del nodo de una categoría, que queda marcada como origen.

**Cómo se usa.**

1. Elegir la categoría de destino. Si no existe, «Crear categoría de semillas» crea una de nivel superior con el nombre escrito (por defecto «Semillas»). Si ya hay una de nivel superior con el mismo nombre, sin importar tildes ni mayúsculas, se reutiliza y el panel lo avisa.
2. Marcar las categorías de origen. No pueden ser el destino, contenerlo, estar dentro de él, ni estar una dentro de otra.
3. Pulsar «Vista previa»: por cada curso se ve de dónde viene, a dónde va y qué categorías se crearían.
4. Revisar los repetidos: en cada fila de un grupo se elige «Conservar», «A Repetidos» o «Dejar en el origen» (este último solo para cursos de origen). Pulsar «Unificar (N)».

**Destino en espejo.** Cada curso va al destino con la misma ruta que tenía **debajo de su categoría de origen**; la categoría de origen no se reproduce. Por ejemplo, con origen `Semillas Sede Norte` y destino `Semillas`, un curso de `Semillas Sede Norte › Ingeniería › Sistemas` queda en `Semillas › Ingeniería › Sistemas`. Las categorías que no existan en el destino se crean; las que existen se reutilizan comparando el nombre sin importar tildes ni mayúsculas (`Ingenieria` e `INGENIERÍA` son la misma).

**Repetidos.** Se comparan los cursos que van a la misma categoría de destino: los que ya están allí y los de los orígenes que llegan a ella (también entre orígenes distintos). Dos cursos de la misma categoría son el mismo si se cumple cualquiera de estas reglas:

1. **Mismo nombre** completo, sin importar tildes, mayúsculas ni espacios.
2. **Mismo nombre sin marcas al final**: se quitan «copia», «copia 2», «(copia)», «Copia de …» o «Copy of …» al principio, un periodo («2025-2», «2025-02», «(2025-2)») y un grupo («Grupo 1», «Grupo A», «G1»). «Cálculo I», «Cálculo I (copia)» y «Cálculo I 2025-2» son el mismo; «Cálculo I» y «Cálculo II», «Física 1» y «Física 2» o «Inglés I» e «Inglés IV» **no**: los números de nivel nunca se quitan.
3. **Mismo nombre corto base**: sin «copia», sin periodo al final, siempre con separador (`-2025-2`, `_2025-02`; en `CAL20261` las cifras son parte del código) y sin el contador «_1» que Moodle agrega cuando el nombre corto ya existe, sin importar mayúsculas (`CALC1`, `CALC1_1`, `CALC1-2025-2`, `CALC1_2025-02` y `CALC1 (copia)` son el mismo). Un «-1» suelto **no** se quita: `MAT-1` y `MAT-2`, `ING-I` e `ING-II` o `FIS1` y `FIS2` son cursos distintos. Si quedan menos de tres letras o dígitos, esta regla no se aplica. Además, el nombre corto solo une dos cursos cuyos nombres comparten al menos una palabra significativa (tres letras o más, sin contar «de», «del», «la», «el», «y», «en» y parecidas): `CAL101` «Cálculo Diferencial» y `CAL101_2` «Física» **no** son el mismo.

La agrupación es transitiva: si A se parece a B por el nombre y B a C por el nombre corto, A, B y C forman un grupo. Pero dos cursos cuyos nombres solo se diferencian en un número de nivel («Cálculo I» y «Cálculo II») o en el **grupo** («… G1» y «… G2», «Física Grupo A» y «Física Grupo B», «Práctica Profesional I - Grupo 1» y «- Grupo 2») nunca quedan en el mismo grupo, por ninguna regla ni a través de un tercero: son secciones distintas, con estudiantes distintos. «Cálculo I» sí puede unirse con «Cálculo I G1», pero entonces no con «Cálculo I G2». En cada grupo se conserva por defecto un curso **visible** antes que uno oculto; entre ellos, el **más completo** (el que tiene más actividades, sin contar las que se están borrando); luego el **modificado más recientemente** y, en empate, el de id más alto. Cada curso muestra cuántas actividades tiene; si el que se conserva está oculto, el panel lo avisa; los demás van a una carpeta **oculta** «Repetidos». Mover a una carpeta oculta oculta el curso (comportamiento de Moodle). Nunca se borra ningún curso.

Los repetidos de la unificación van a una carpeta oculta «Repetidos» directamente bajo la categoría de destino (se crea si no existe; si ya hay una con ese nombre, sin importar tildes ni mayúsculas, se reutiliza). Un curso que ya estaba en el destino y pierde frente a uno más reciente del origen aparece como fila propia («Ya está en el destino») y también va a «Repetidos». Lo que ya está dentro de «Repetidos» nunca se compara.

**Permisos.** Además de `tool/ceaorganizador:manage`: `moodle/category:manage` en el destino, en cada origen, en la categoría actual de cada curso movido (también la de un curso del destino que va a «Repetidos») y en la categoría existente de la que cuelgan las categorías nuevas; y `moodle/course:changecategory` en cada curso movido. Si falta uno, no se cambia nada. Para crear la categoría de semillas hace falta además `moodle/category:manage` en el sistema.

**Deshacer.** Queda una entrada en el historial («Unificar en … · cursos: N») y se puede deshacer, con dos advertencias:

- Los cursos vuelven a su categoría original, pero **no a su posición original** dentro de ella: quedan al principio.
- Las categorías que la unificación creó se borran solo si están vacías, con la misma regla que «Borrar categorías» (sin cursos, subcategorías ni contenido o configuración propia). Si tienen algo, se conservan y hay que borrarlas a mano.

Deshacer se rechaza, con los conflictos listados, si algún curso ya no está donde lo dejó la unificación o si su categoría original desapareció. Si mover a una categoría oculta (como «Repetidos») ocultó un curso, deshacer lo vuelve a mostrar. La carpeta «Repetidos» se borra al deshacer solo si la unificación la creó y quedó vacía.

**Siempre queda uno en el destino.** En un grupo que tiene un curso que ya estaba en el destino, al menos un curso debe terminar en el destino: si se deja el ganador en el origen o se mandan todos a «Repetidos», el panel lo avisa y no deja unificar (el servidor también lo rechaza, nombrando el curso).

**Carpeta visible.** Si ya existe una carpeta «Repetidos» visible, el panel avisa: «La carpeta Repetidos existe y está visible: los cursos movidos quedarán visibles». La carpeta no se cambia.

**Si falla a mitad.** Las categorías se crean en una transacción corta y los cursos se mueven después por destino, de a 100. Si una tanda falla, solo esa se revierte: lo que ya se movió queda registrado en el historial y el panel lo avisa con «Deshacer» para ese registro.

**Categorías de origen vacías.** La unificación **no** las borra. Al terminar, el panel lista con enlaces las que quedaron sin cursos ni subcategorías, de la más profunda a la más superficial; se pueden borrar después con «Borrar categorías» o desde Moodle.

**Límite.** Máximo **1000** cursos por unificación; si hay más, se rechaza. Se divide en varias unificaciones.

### Quitar repetidos

Busca cursos repetidos en una categoría y en **todas sus subcategorías** y mueve los sobrantes a una carpeta oculta «Repetidos». Cada categoría se compara solo consigo misma: «Cálculo I» en dos programas distintos son dos cursos distintos y no se tocan.

**Dónde está.** «Quitar repetidos» en la barra del organizador (pide la categoría, con el mismo buscador que el destino de «Unificar categorías») y en el menú «⋯» de cada categoría.

**Reglas.** Dos cursos de la misma categoría son el mismo si se cumple cualquiera de estas reglas:

1. **Mismo nombre** completo, sin importar tildes, mayúsculas ni espacios.
2. **Mismo nombre sin marcas al final**: se quitan «copia», «copia 2», «(copia)», «Copia de …» o «Copy of …» al principio, un periodo («2025-2», «2025-02», «(2025-2)») y un grupo («Grupo 1», «Grupo A», «G1»). «Cálculo I», «Cálculo I (copia)» y «Cálculo I 2025-2» son el mismo; «Cálculo I» y «Cálculo II», «Física 1» y «Física 2» o «Inglés I» e «Inglés IV» **no**: los números de nivel nunca se quitan.
3. **Mismo nombre corto base**: sin «copia», sin periodo al final, siempre con separador (`-2025-2`, `_2025-02`; en `CAL20261` las cifras son parte del código) y sin el contador «_1» que Moodle agrega cuando el nombre corto ya existe, sin importar mayúsculas (`CALC1`, `CALC1_1`, `CALC1-2025-2`, `CALC1_2025-02` y `CALC1 (copia)` son el mismo). Un «-1» suelto **no** se quita: `MAT-1` y `MAT-2`, `ING-I` e `ING-II` o `FIS1` y `FIS2` son cursos distintos. Si quedan menos de tres letras o dígitos, esta regla no se aplica. Además, el nombre corto solo une dos cursos cuyos nombres comparten al menos una palabra significativa (tres letras o más, sin contar «de», «del», «la», «el», «y», «en» y parecidas): `CAL101` «Cálculo Diferencial» y `CAL101_2` «Física» **no** son el mismo.

La agrupación es transitiva: si A se parece a B por el nombre y B a C por el nombre corto, A, B y C forman un grupo. Pero dos cursos cuyos nombres solo se diferencian en un número de nivel («Cálculo I» y «Cálculo II») o en el **grupo** («… G1» y «… G2», «Física Grupo A» y «Física Grupo B», «Práctica Profesional I - Grupo 1» y «- Grupo 2») nunca quedan en el mismo grupo, por ninguna regla ni a través de un tercero: son secciones distintas, con estudiantes distintos. «Cálculo I» sí puede unirse con «Cálculo I G1», pero entonces no con «Cálculo I G2». En cada grupo se conserva por defecto un curso **visible** antes que uno oculto; entre ellos, el **más completo** (el que tiene más actividades, sin contar las que se están borrando); luego el **modificado más recientemente** y, en empate, el de id más alto. Cada curso muestra cuántas actividades tiene; si el que se conserva está oculto, el panel lo avisa; los demás van a una carpeta **oculta** «Repetidos». Mover a una carpeta oculta oculta el curso (comportamiento de Moodle). Nunca se borra ningún curso.

**Cómo se usa.** La vista previa muestra cada grupo con la ruta de su categoría (de dónde viene cada curso) y, por curso, su nombre corto, la fecha de modificación y la regla que lo agrupó. En cada grupo se elige con un botón de opción cuál se conserva y con la casilla «Incluir este grupo» si se toca; un grupo sin marcar no se mueve. El botón dice cuántos cursos se moverán: «Mover N repetidos a Repetidos».

**Carpeta «Repetidos».** Una sola, directamente bajo la categoría elegida, creada **oculta** si no existe (si ya hay una con ese nombre, sin importar tildes ni mayúsculas, se reutiliza). Los cursos llegan a ella sin subcarpetas; la vista previa muestra su ruta de origen. Ninguna carpeta llamada «Repetidos», ni lo que tenga dentro, se analiza. Si la carpeta ya existe y está visible, el panel avisa que los cursos movidos quedarán visibles; la carpeta no se cambia. Si el lote se interrumpe a mitad, lo movido queda en el historial y el panel ofrece «Deshacer».

**Permisos.** Además de `tool/ceaorganizador:manage`: `moodle/category:manage` en la categoría elegida, en la carpeta «Repetidos» si ya existe y en la categoría de cada curso movido; `moodle/course:changecategory` en cada curso movido. Si falta uno, no se cambia nada.

**Deshacer.** Queda una entrada en el historial («Quitar repetidos en … · cursos: N»). Deshacer devuelve los cursos a su categoría, los vuelve a mostrar si la carpeta oculta los ocultó y borra la carpeta solo si este lote la creó y quedó vacía. Se rechaza, con los conflictos listados, si algún curso se movió después.

**Límites.** La vista previa revisa como máximo **5000** cursos (la categoría y sus subcategorías, sin contar las carpetas «Repetidos»); si hay más, pide elegir una categoría más pequeña, como un programa o un semestre. Se mueven como máximo **1000** cursos por lote; si son más, se rechaza con ese número: excluir grupos o trabajar por subcategorías.

### Posibles repetidos

Además de los repetidos seguros, «Quitar repetidos» y «Unificar categorías» señalan **posibles repetidos**: cursos de la misma categoría (o que van al mismo destino) con nombres iguales o muy parecidos que las reglas seguras no juntan. La herramienta no decide: pregunta «¿Son el mismo curso?» con [Sí, es repetido] y [No]; por defecto es **No** y no se mueve nada. Con «Sí» pasan a ser un grupo como los demás, con el ganador por defecto, que se puede cambiar. Motivos:

- **Grupos distintos** (mismo nombre con «G1» y «G2», «Grupo A» y «Grupo B»), con el aviso «son grupos distintos; normalmente NO son repetidos».
- **Mismo nombre corto** con nombres que no comparten ninguna palabra.
- **Una palabra o un paréntesis de más** al final («Física» y «Física Mecánica»).
- **Nombres casi iguales**: las mismas palabras en otro orden, o, en nombres de 12 caracteres o más, una diferencia de hasta dos letras.

Nunca se sugieren cursos de distinto nivel («Cálculo I» y «Cálculo II», «Programación» y «Programación I»). Solo se consideran cursos que no están ya en un grupo seguro. El servidor acepta un posible grupo confirmado solo si está en la vista previa que se aplica.

### Desde la selección, en un clic

Si hay categorías marcadas en el árbol, los botones de la barra no abren el panel:

- **Quitar repetidos** analiza las categorías marcadas (cada una con sus subcategorías; una marcada dentro de otra marcada se cubre con esta) y pregunta «Se moverán N repetidos a Repetidos en M categorías» con [Aplicar] [Ver detalle] [Cancelar]. [Aplicar] mueve con los ganadores por defecto, en un solo registro del historial (un solo «Deshacer»); cada categoría marcada recibe su propia carpeta «Repetidos». [Ver detalle] abre el panel con esas categorías. Si no hay nada, avisa «No se encontraron cursos repetidos».
- **Unificar categorías** usa las categorías marcadas como orígenes y como destino la categoría de nivel superior «Semillas» (sin importar tildes ni mayúsculas); si no existe, la crea al pulsar el botón, como «Crear categoría de semillas» (si luego se cancela, queda vacía y se puede borrar). Pregunta «Se moverán X cursos a Semillas, Y repetidos a Repetidos». Las marcadas que ya están dentro de Semillas no se tocan (el texto lo dice); si todas están dentro, no hay nada que unificar entre raíces y hace «Quitar repetidos» sobre ellas.
- Si hay posibles repetidos, la pregunta añade «Además hay K posibles repetidos que necesitan tu confirmación» con [Revisar], que abre el panel; [Aplicar] solo aplica los seguros.

Sin categorías marcadas, los botones abren el panel como siempre. Se respetan los mismos límites (1000 cursos movidos; 5000 revisados en «Quitar repetidos») y la misma regla de que quede un curso en el destino.

### Ver los repetidos en el árbol

Sin abrir «Quitar repetidos», el árbol señala los cursos repetidos con las mismas reglas (solo se lee, no se mueve nada):

- **En cada curso**, al abrir su categoría: «Base» en el curso que se conservaría (el mismo orden de «Quitar repetidos»: visible, más actividades, más reciente), «Repetido» en los demás de su grupo y «¿Repetido?» en los posibles repetidos. Al pasar el puntero, la insignia dice por qué («mismo nombre», «mismo nombre sin copia, periodo ni grupo», «mismo nombre corto») y de qué curso es repetido; los lectores de pantalla lo leen también. Se compara toda la categoría, no solo la página de 100 cursos que se ve.
- **En cada categoría**, «N repetidos» cuenta los repetidos seguros de la categoría y de todas sus subcategorías. Así se sigue el rastro desde la facultad hasta el semestre donde están. Un clic en la insignia abre esa categoría y, por debajo, las que tienen repetidos (hasta 25 a la vez), con sus cursos.
- **En la barra superior**, «M cursos repetidos en el sitio» con [Revisar], que abre las categorías de nivel superior que tienen repetidos.

Los posibles repetidos no se cuentan en «N repetidos» (son una pregunta, no un hecho). Cada categoría se compara solo consigo misma, y las carpetas «Repetidos» y lo que tienen dentro nunca se cuentan ni se marcan. En una búsqueda en todo el sitio los cursos no llevan insignia. Los números se calculan cada vez que se carga o recarga el árbol: con unos 6000 cursos, el cálculo agrega del orden de 150 ms.

### Borrar categorías

Borra categorías **vacías**, una o varias a la vez. Nunca borra cursos ni contenido.

**Dónde está.** «Borrar» en el menú «⋯» de una categoría, y «Borrar seleccionadas» en la barra de selección (actúa solo sobre las categorías seleccionadas; los cursos seleccionados se ignoran).

**Qué es vacía.** Sin cursos, sin subcategorías y sin nada propio que Moodle borraría o movería al borrarla: elementos de la papelera de reciclaje, del banco de contenidos, eventos, letras de calificación, categorías de preguntas (cualquiera, incluso una «top» vacía), asignaciones de roles, permisos definidos en la categoría, cohortes, bloques, archivos (también las imágenes de la descripción), comentarios, filtros ni marcos o plantillas de competencias. Tampoco se borra la categoría por defecto de las solicitudes de curso. Así nunca se pierde nada. Si se seleccionan una categoría y todas sus subcategorías vacías, se borran juntas, de la más profunda a la más superficial.

**Confirmación.** Antes de borrar, el diálogo lista lo que se borrará y lo que se conservará, con el motivo: tiene cursos, tiene subcategorías, tiene contenido propio, no tienes permiso o ya no existe. Las que se conservan nunca se borran. Al terminar, las borradas salen de la selección; lo demás (cursos incluidos) sigue seleccionado.

**Permisos.** Además de `tool/ceaorganizador:manage`: `moodle/category:manage` en la categoría y en su categoría padre (en el sistema si es de nivel superior), como exige Moodle. Sin ese permiso la categoría se conserva.

**Deshacer.** Queda una entrada en el historial («Borrar: …») y se puede deshacer: las categorías se vuelven a crear con su nombre, número de ID, descripción, visibilidad, tema (si lo tenían) y, en lo posible, su posición entre las hermanas. **Se crean con ids nuevos**: los enlaces o informes que usaban el id anterior no las encuentran. El tema solo se recupera si el sitio permite temas por categoría. Una categoría oculta a propósito vuelve oculta; una oculta solo porque su padre lo estaba vuelve con la visibilidad del padre. Deshacer se rechaza, sin cambiar nada, si la categoría padre original ya no existe, si otra categoría usa ahora su número de ID o si en su lugar ya hay otra con el mismo nombre (sin importar tildes ni mayúsculas).

**Límite.** Máximo **200** categorías por lote (un solo registro y un solo «Deshacer»); si se seleccionan más, el diálogo lo avisa y no envía nada.

### Clonar semillas a un periodo

Copia cursos semilla (plantillas) a una categoría de periodo, por ejemplo «2027-01», sin hacerlo uno por uno. Se recomienda hacerlo después de «Sugerir correcciones» y «Unificar categorías».

**Requisito: el cron de Moodle debe estar activo.** Las copias las hace la tarea ad hoc `\core\task\asynchronous_copy_task` (no es una tarea programada), que el cron ejecuta. Las copias que esperan se ven en *Administración del sitio › Servidor › Tareas › Tareas ad hoc* (página `admin/tool/task/adhoctasks.php`; el texto del menú puede variar según la versión y el paquete de idioma). Sin cron, las copias quedan «Pendiente» y no avanzan. Si pasan unos 10 minutos sin progreso, el panel muestra el aviso «Las copias dependen del cron de Moodle; verifica que esté activo.».

**Dónde está.** En el menú del nodo de una categoría y en la barra de selección, la opción «Clonar a periodo…».

**Cómo se usa.**

1. Elegir las semillas: una categoría (menú de su nodo) o una selección. Para clonar un solo curso, seleccionarlo y usar «Clonar a periodo…» en la barra de selección.
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

- **Operaciones masivas fuera de las horas pico.** «Unificar categorías», «Auto ordenar» del nivel superior (o de todo el sitio) y «Clonar a periodo» reescriben el orden de muchas categorías, mueven muchos cursos o encolan copias; cada una purga cachés de cursos y categorías de todo el sitio. Conviene hacerlas temprano, de noche o en fin de semana.
- **Renombrar y ocultar o mostrar cursos va en lotes de 100.** Cada curso pasa por la función del núcleo que actualiza el curso y purga cachés; los lotes cortos evitan peticiones largas. El panel parte la lista solo.
- **Clonar en lotes.** Cada copia es una tarea en segundo plano (copia de seguridad y restauración). Para que el cron no corra varias copias a la vez y no cargue el servidor, se recomienda limitarlas a una a la vez en `config.php`:

  ```php
  $CFG->task_concurrency_limit = ['\core\task\asynchronous_copy_task' => 1];
  ```

### Pruebas seguras

Probar primero en una categoría de prueba, nunca sobre periodos activos. Usar las vistas previas de «Auto ordenar» y «Renombrar» antes de confirmar, y comprobar que «Deshacer» funciona con una operación pequeña antes de organizar una sede completa.

---

*Texto pendiente de revisión editorial.*
