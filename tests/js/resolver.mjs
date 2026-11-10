// Hook de resolución de Node: '<component>/<nombre>' -> amd/src/<nombre>.js.
// El componente se lee de version.php, así el archivo no cambia entre plugins.
// Los módulos del núcleo de Moodle ('core/ajax', 'core/str'…) no existen en
// Node: las pruebas los sustituyen inyectando dependencias en los módulos.
import { readFileSync } from 'node:fs';

const version = readFileSync(new URL('../../version.php', import.meta.url), 'utf8');
const component = version.match(/\$plugin->component\s*=\s*'([a-z0-9_]+)'/)[1];
const PREFIX = `${component}/`;
const SRC = new URL('../../amd/src/', import.meta.url);

export async function resolve(specifier, context, nextResolve) {
    if (specifier.startsWith(PREFIX)) {
        const file = new URL(`${specifier.slice(PREFIX.length)}.js`, SRC).href;
        return nextResolve(file, context);
    }
    return nextResolve(specifier, context);
}
