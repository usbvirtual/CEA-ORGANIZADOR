# CLAUDE.md

Guía para trabajar en este repositorio con Claude Code. Lee primero [README.md](README.md).

## Qué es

Plugin de Moodle **CEA-ORGANIZADOR** (`tool_ceaorganizador`) del Centro de Innovación Pedagógica y Educación Digital (CEA), USB Cali. Carpeta de instalación: `admin/tool/ceaorganizador`. Especificación: `docs/superpowers/specs/…`.

## Comandos

```bash
npm test               # pruebas JS; deben pasar antes de dar algo por terminado
npm run check-strings  # cadenas completas, iguales y ordenadas en es y en
npm run build          # recompila amd/build tras tocar amd/src
npm run package        # dist/CEA-ORGANIZADOR.zip (lo que se entrega al administrador)
```

No hay Moodle en este equipo: la compatibilidad real la confirma GitHub Actions.

## Reglas

Este plugin sigue la skill `creating-cea-moodle-plugins` (estándares y lecciones de los plugins del CEA) y la skill `cea-branding`. Reglas propias de este plugin:

- Requiere Moodle 4.2 o superior, porque los servicios web usan `re_external`.
- Toda escritura (mover, reordenar, renombrar) pasa solo por `classes/local/mover.php` y `classes/local/journal.php`.
- `sorter` y `renamer` son puros: no tocan la base de datos.

## Decisiones de diseño que conviene no deshacer

- Pendiente: se llenará al avanzar las tareas.
