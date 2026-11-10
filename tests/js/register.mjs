// Registra el resolvedor que traduce los imports con nombre de módulo Moodle
// ('<component>/<nombre>') a los archivos de amd/src durante las pruebas.
import { register } from 'node:module';

register('./resolver.mjs', import.meta.url);
