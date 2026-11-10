// Compila amd/src/*.js a amd/build/*.min.js (+ .map) en formato AMD con los
// nombres de módulo que Moodle espera (<component>/<nombre>). Sustituye al
// Grunt de Moodle: su salida cambia de una rama a otra y no es reproducible.
// Origen: CEA-LECTOR (block_cealector), generalizado.
import { readdirSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { transformAsync } from '@babel/core';
import { minify } from 'terser';
import { root, component } from './plugin-info.mjs';

// Uso: node scripts/build-amd.mjs [--src <dir>] [--out <dir>]
const argValue = (flag, fallback) => {
    const index = process.argv.indexOf(flag);
    return index !== -1 && process.argv[index + 1] ? path.resolve(process.argv[index + 1]) : fallback;
};
const srcDir = argValue('--src', path.join(root, 'amd', 'src'));
const buildDir = argValue('--out', path.join(root, 'amd', 'build'));

// Red de seguridad: reescribe `import X from './y'` a `<component>/y`.
// La regla del proyecto es escribir ya el nombre Moodle en amd/src.
const moodleImports = () => ({
    visitor: {
        ImportDeclaration(p) {
            const value = p.node.source.value;
            if (value.startsWith('./')) {
                p.node.source.value = `${component}/${path.posix.basename(value).replace(/\.js$/, '')}`;
            }
        },
    },
});

mkdirSync(buildDir, { recursive: true });
const files = readdirSync(srcDir).filter((f) => f.endsWith('.js'));

for (const file of files) {
    const name = file.replace(/\.js$/, '');
    const code = readFileSync(path.join(srcDir, file), 'utf8');
    const babel = await transformAsync(code, {
        filename: file,
        sourceMaps: true,
        babelrc: false,
        configFile: false,
        presets: [['@babel/preset-env', { targets: '>0.3%, last 2 versions, not dead', modules: false }]],
        plugins: [
            moodleImports,
            ['@babel/plugin-transform-modules-amd', { moduleId: `${component}/${name}`, loose: true }],
        ],
    });
    const outName = `${name}.min.js`;
    const min = await minify(babel.code, {
        sourceMap: { content: babel.map, url: `${outName}.map` },
        format: { comments: false },
    });
    writeFileSync(path.join(buildDir, outName), min.code);
    writeFileSync(path.join(buildDir, `${outName}.map`), min.map);
    console.log(`build: ${outName}`);
}
