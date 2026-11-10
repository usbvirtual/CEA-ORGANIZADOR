# Instalación de CEA-ORGANIZADOR

Plugin de Moodle del Centro de Innovación Pedagógica y Educación Digital (CEA), Universidad de San Buenaventura Cali.

- **Requisitos:** Moodle 4.2 o superior y PHP 8.0 a 8.4. Escribir aquí las versiones probadas solo después de que pase la CI.
- **Carpeta de instalación:** `admin/tool/ceaorganizador`.

## Opción A · Desde la interfaz

1. *Administración del sitio › Extensiones › Instalar extensiones*.
2. Subir `CEA-ORGANIZADOR.zip` y seguir el asistente hasta «Actualizar la base de datos de Moodle ahora».
3. *Administración del sitio › Desarrollo › Purgar todas las cachés*.

## Opción B · Por servidor

1. Descomprimir el ZIP en `admin/tool/` (queda `admin/tool/ceaorganizador/`). En Moodle 5.1 o superior, comprobar si el código del sitio está dentro de `public/`; en ese caso, la ruta va allí.
2. Ejecutar:
   ```bash
   php admin/cli/upgrade.php --non-interactive
   php admin/cli/purge_caches.php
   ```

## Actualizar

Se instala igual, encima de la versión anterior. Moodle no permite instalar una versión anterior sobre una más nueva. **Purgar las cachés siempre**: sin eso, el tema no carga los estilos ni el JavaScript nuevos.

## Después de instalar

- Dónde aparece.
- Qué permiso lo controla.
- Qué ajustes revisar.
