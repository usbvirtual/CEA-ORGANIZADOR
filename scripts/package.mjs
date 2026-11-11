// Genera dist/<zipName> con la carpeta raíz <name>/ (la carpeta de instalación,
// no el componente) y solo las rutas de package.json → cea.include.
// Escribe el ZIP con Node (zlib), sin tar ni zip del sistema: `tar` en Linux
// no genera un ZIP, y así el resultado es idéntico en Windows, Linux y CI.
// Origen: CEA-LECTOR, generalizado.
import { rmSync, mkdirSync, cpSync, existsSync, readdirSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { deflateRawSync } from 'node:zlib';
import path from 'node:path';
import { root, name, zipName, include } from './plugin-info.mjs';

const dist = path.join(root, 'dist');
const stage = path.join(dist, name);
const zip = path.join(dist, zipName);

if (!include.length) {
    throw new Error('package.json no tiene cea.include');
}

// Compila primero para que amd/build esté al día (SKIP_BUILD=1 lo omite en pruebas).
if (!process.env.SKIP_BUILD && existsSync(path.join(root, 'amd', 'src'))) {
    execFileSync(process.execPath, [path.join(root, 'scripts', 'build-amd.mjs')], { cwd: root, stdio: 'inherit' });
}

// En OneDrive las carpetas pueden quedar de solo lectura y rmSync falla con
// EPERM: en ese caso, quitar el atributo (attrib -R /S /D dist) y repetir.
rmSync(dist, { recursive: true, force: true });
mkdirSync(stage, { recursive: true });
for (const item of include) {
    const from = path.join(root, item);
    if (!existsSync(from)) {
        throw new Error(`Falta ${item}`);
    }
    // Los .map de amd/build quedan en git (CI comprueba que amd/build esté al día),
    // pero Moodle no los necesita para funcionar: no van al ZIP.
    cpSync(from, path.join(stage, item), { recursive: true, filter: (src) => !src.endsWith('.map') });
}

// --- Escritor ZIP mínimo (PKZIP, deflate, nombres UTF-8, fecha fija) ---

const CRC_TABLE = new Uint32Array(256);
for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) {
        c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    CRC_TABLE[n] = c >>> 0;
}
const crc32 = (buf) => {
    let crc = 0xffffffff;
    for (const byte of buf) {
        crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ 0xffffffff) >>> 0;
};

// Fecha DOS fija (2026-01-01 00:00) para que el ZIP sea reproducible.
const DOS_TIME = 0;
const DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1;

const walk = (dir, prefix) => {
    const out = [];
    for (const entry of readdirSync(dir).sort()) {
        const full = path.join(dir, entry);
        const rel = `${prefix}${entry}`;
        if (statSync(full).isDirectory()) {
            out.push({ name: `${rel}/`, dir: true });
            out.push(...walk(full, `${rel}/`));
        } else {
            out.push({ name: rel, data: readFileSync(full) });
        }
    }
    return out;
};

const entries = [{ name: `${name}/`, dir: true }, ...walk(stage, `${name}/`)];
const locals = [];
const centrals = [];
let offset = 0;

for (const entry of entries) {
    const nameBuf = Buffer.from(entry.name, 'utf8');
    const data = entry.dir ? Buffer.alloc(0) : entry.data;
    const deflated = entry.dir ? Buffer.alloc(0) : deflateRawSync(data, { level: 9 });
    const method = entry.dir ? 0 : 8;
    const crc = crc32(data);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6);
    local.writeUInt16LE(method, 8);
    local.writeUInt16LE(DOS_TIME, 10);
    local.writeUInt16LE(DOS_DATE, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(deflated.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(nameBuf.length, 26);
    local.writeUInt16LE(0, 28);
    locals.push(local, nameBuf, deflated);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(method, 10);
    central.writeUInt16LE(DOS_TIME, 12);
    central.writeUInt16LE(DOS_DATE, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(deflated.length, 20);
    central.writeUInt32LE(data.length, 24);
    central.writeUInt16LE(nameBuf.length, 28);
    central.writeUInt16LE(0, 30);
    central.writeUInt16LE(0, 32);
    central.writeUInt16LE(0, 34);
    central.writeUInt16LE(0, 36);
    central.writeUInt32LE(entry.dir ? 0x10 : 0, 38);
    central.writeUInt32LE(offset, 42);
    centrals.push(central, nameBuf);

    offset += local.length + nameBuf.length + deflated.length;
}

const centralDir = Buffer.concat(centrals);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(0, 4);
end.writeUInt16LE(0, 6);
end.writeUInt16LE(entries.length, 8);
end.writeUInt16LE(entries.length, 10);
end.writeUInt32LE(centralDir.length, 12);
end.writeUInt32LE(offset, 16);
end.writeUInt16LE(0, 20);

writeFileSync(zip, Buffer.concat([...locals, centralDir, end]));
console.log(`package: ${path.relative(root, zip)} (${entries.filter((e) => !e.dir).length} archivos)`);
