// Datos del plugin leídos de version.php y package.json, para que los demás
// scripts no lleven el nombre del plugin escrito a mano.
//   component: 'tool_ceaorganizador'  (de $plugin->component)
//   name:      'ceaorganizador'       (carpeta de instalación y raíz del ZIP)
//   zipName:   'CEA-ORGANIZADOR.zip'  (package.json → cea.zipName)
//   include:   lista de rutas que van al ZIP (package.json → cea.include)
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const version = readFileSync(path.join(root, 'version.php'), 'utf8');
const match = version.match(/\$plugin->component\s*=\s*'([a-z]+)_([a-z0-9_]+)'/);
if (!match) {
    throw new Error('version.php no declara $plugin->component');
}

export const component = `${match[1]}_${match[2]}`;
export const plugintype = match[1];
export const name = match[2];

const pkg = JSON.parse(readFileSync(path.join(root, 'package.json'), 'utf8'));
export const zipName = pkg.cea?.zipName ?? `${name.toUpperCase()}.zip`;
export const include = pkg.cea?.include ?? [];
