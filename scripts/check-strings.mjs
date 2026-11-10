// Comprueba que toda cadena de idioma usada en PHP, Mustache y amd/src exista
// en lang/es y lang/en, que ambos archivos tengan las mismas claves y que las
// claves estén en orden alfabético (lo exige el verificador de Moodle).
// Origen: CEA-LECTOR, generalizado (lee el componente de version.php).
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import path from 'node:path';
import { root, component, plugintype, name } from './plugin-info.mjs';

const SKIP = ['node_modules', '.git', 'dist', 'docs', '.superpowers', 'lang', 'build', 'tests'];
const walk = (dir, acc = []) => {
    for (const entry of readdirSync(dir)) {
        if (SKIP.includes(entry)) {
            continue;
        }
        const full = path.join(dir, entry);
        if (statSync(full).isDirectory()) {
            walk(full, acc);
        } else if (/\.(php|mustache|js)$/.test(entry)) {
            acc.push(full);
        }
    }
    return acc;
};

const langKeys = (lang) => {
    const file = path.join(root, 'lang', lang, `${component}.php`);
    if (!existsSync(file)) {
        return null;
    }
    return [...readFileSync(file, 'utf8').matchAll(/\$string\['([\w:.-]+)'\]/g)].map((m) => m[1]);
};

const c = component;
const patterns = [
    new RegExp(`get_string\\(\\s*'([\\w:.-]+)'\\s*,\\s*'${c}'`, 'g'),
    new RegExp(`\\{\\{#str\\}\\}\\s*([\\w:.-]+)\\s*,\\s*${c}`, 'g'),
    new RegExp(`new lang_string\\(\\s*'([\\w:.-]+)'\\s*,\\s*'${c}'`, 'g'),
    new RegExp(`getString\\(\\s*'([\\w:.-]+)'\\s*,\\s*'${c}'`, 'g'),
    new RegExp(`key:\\s*'([\\w:.-]+)'\\s*,\\s*component:\\s*'${c}'`, 'g'),
];

const used = new Set(['pluginname']);
for (const file of walk(root)) {
    const text = readFileSync(file, 'utf8');
    for (const re of patterns) {
        for (const m of text.matchAll(re)) {
            used.add(m[1]);
        }
    }
}

// Cada capacidad de db/access.php necesita su cadena (<name>:<capacidad>).
const access = path.join(root, 'db', 'access.php');
if (existsSync(access)) {
    const capRe = new RegExp(`'${plugintype}/${name}:([\\w]+)'`, 'g');
    for (const m of readFileSync(access, 'utf8').matchAll(capRe)) {
        used.add(`${name}:${m[1]}`);
    }
}
// Toda clase privacy necesita al menos privacy:metadata.
if (existsSync(path.join(root, 'classes', 'privacy', 'provider.php'))) {
    used.add('privacy:metadata');
}

let failed = false;
const lists = { es: langKeys('es'), en: langKeys('en') };
for (const [lang, keys] of Object.entries(lists)) {
    if (!keys) {
        console.error(`check-strings: falta lang/${lang}/${component}.php`);
        failed = true;
        continue;
    }
    const set = new Set(keys);
    const missing = [...used].filter((k) => !set.has(k)).sort();
    if (missing.length) {
        console.error(`check-strings: faltan en lang/${lang}: ${missing.join(', ')}`);
        failed = true;
    }
    const sorted = [...keys].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const firstOut = keys.findIndex((k, i) => k !== sorted[i]);
    if (firstOut !== -1) {
        console.error(`check-strings: lang/${lang} no está en orden alfabético (primera fuera de lugar: ${keys[firstOut]})`);
        failed = true;
    }
}
if (lists.es && lists.en) {
    const es = new Set(lists.es);
    const en = new Set(lists.en);
    const onlyEs = [...es].filter((k) => !en.has(k));
    const onlyEn = [...en].filter((k) => !es.has(k));
    if (onlyEs.length || onlyEn.length) {
        console.error(`check-strings: claves desiguales. Solo es: ${onlyEs.join(', ') || '-'}; solo en: ${onlyEn.join(', ') || '-'}`);
        failed = true;
    }
}
if (failed) {
    process.exit(1);
}
console.log(`check-strings: ${used.size} claves verificadas en es y en.`);
