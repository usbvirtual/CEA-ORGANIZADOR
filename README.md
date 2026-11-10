# CEA-ORGANIZADOR

Plugin de Moodle (`tool_ceaorganizador`) del Centro de Innovación Pedagógica y Educación Digital (CEA), USB Cali. Permite organizar el árbol de categorías y cursos de un sitio: mover, ordenar automáticamente, renombrar por lotes y deshacer. Aparece en *Administración del sitio › Cursos › Organizador de categorías (CEA)*. Requiere Moodle 4.2 o superior.

Instalación: ver [INSTALACION.md](INSTALACION.md). Guía para desarrollo: [CLAUDE.md](CLAUDE.md).

## Para quién

Para los administradores de las plataformas de la USB (eCampus, Mcampus, Campus M) que hoy mueven categorías y cursos uno por uno en la pantalla nativa «Gestionar cursos y categorías». La herramienta es solo para administradores del sitio: la página aparece únicamente a quien puede configurar el sitio. La capacidad `tool/ceaorganizador:manage` es un control adicional; darla a otros usuarios no les da acceso a la página.

## Qué hace

- **Árbol** de categorías con cursos por página (100), buscador con ruta completa e indicador de categorías ocultas.
- **Arrastrar y soltar** con eventos de puntero propios, más teclado (Espacio, M, S, Shift+F10) y el menú «Mover a…».
- **Auto ordenar** con vista previa y recomendación cuando una categoría está desordenada. Los periodos se detectan solos por el nombre («2026-02», «2026 II») y van del más reciente al más antiguo; solo los patrones de semilla y de facultad se configuran en los ajustes.
- **Renombrar en lote** categorías y cursos (reemplazar, limpiar, prefijo o sufijo, tipo oración o título), con vista previa y edición manual.
- **Selección** múltiple de categorías y cursos.
- **Historial y deshacer** (90 días); deshacer se rechaza si algo cambió después.

## Arquitectura

- `classes/local/sorter.php` y `renamer.php` son puros: reciben y devuelven datos, sin base de datos.
- `mover.php` y `journal.php` son la única puerta de escritura; usan la API del núcleo para que se disparen eventos y se limpien cachés.
- `guard.php` reúne el candado y el rollback; los permisos nativos se comprueban en `mover::check_category` y `mover::check_course` y en los servicios web; `tree.php` y `sortplan.php` leen el árbol y arman el plan de orden con su firma.
- `classes/external/*.php`: diez servicios web, uno por clase, sobre `\core_external` (de ahí Moodle 4.2+).
- `amd/src/`: `organizer` (arranque), `api`, `treemodel` y `treeview`, `dragdrop`, `dropzones` y `keyboard`, `movepicker` y `mover`, `sortpanel`, `renamepanel` e `historypanel`, `selection`, `toast`, `dialog` y `text`. Se compilan a `amd/build`.

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
