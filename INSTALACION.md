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

*Administración del sitio › Cursos › Organizador de categorías (CEA)*. Los ajustes están en *Administración del sitio › Cursos › Ajustes del organizador de categorías*.

### Qué permiso lo controla

La capacidad `tool/ceaorganizador:manage`, en el contexto del sistema. No se asigna a ningún rol por defecto: hay que dársela a quien deba usarlo (los administradores del sitio ya pueden). Además, en cada operación el servidor comprueba los permisos nativos de Moodle:

- `moodle/category:manage`, en la categoría de origen y en la de destino.
- `moodle/course:changecategory`, para mover cursos.
- `moodle/course:update`, para renombrar cursos.

Si falta alguno, la operación se rechaza y no cambia nada.

### Ajustes de patrones

Sirven para que «Auto ordenar» reconozca qué es un periodo, una categoría semilla y una facultad. Un patrón por línea. Se comparan contra el nombre de la categoría en minúsculas y sin tildes.

| Sintaxis | Qué hace | Ejemplo |
| --- | --- | --- |
| `texto` | El nombre contiene el texto. | `semilla` |
| `=texto` | El nombre es exactamente el texto. | `=Facultades` |
| `/expresión/` | Expresión regular sobre el nombre. | `/^facultad/` |

Valores por defecto:

- **Patrones de semilla:** `semilla`, `plantilla` y `=Facultades`.
- **Patrones de facultad:** `/^facultad/` y `/^programa/`.
- **Patrones de periodo:** vacío. Si se deja así, se usa el criterio interno.

### Atajos de teclado

Con el foco en el árbol: flechas para navegar; **Espacio** toma el elemento y las flechas eligen el destino (Enter suelta, Esc cancela); **M** abre «Mover a…»; **S** selecciona o deselecciona; **Shift+F10** abre el menú del nodo.

### Historial y deshacer

Cada movimiento, orden y renombrado queda en el historial, y aparece un aviso «Deshacer» durante 8 segundos. El historial se conserva 90 días; una tarea programada borra lo más viejo. Deshacer devuelve el estado anterior, incluida la visibilidad si el movimiento la cambió. **Se rechaza si algo cambió después de la operación** (por ejemplo, otra persona movió o renombró el elemento) y se indica qué cambió; en ese caso no se modifica nada.

### Pruebas seguras

Probar primero en una categoría de prueba, nunca sobre periodos activos. Usar las vistas previas de «Auto ordenar» y «Renombrar» antes de confirmar, y comprobar que «Deshacer» funciona con una operación pequeña antes de organizar una sede completa.

---

*Texto pendiente de revisión editorial.*
