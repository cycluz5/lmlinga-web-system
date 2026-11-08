// Spot Mapping offline basemap: one-time extract of the La Medalla + ~1 km buffer from a pinned Protomaps daily build (ODbL).
// Reads only the needed tiles via HTTP range requests (the same thing `pmtiles extract` does) and
// writes uncompressed static z/x/y .mvt files plus a manifest. Refuses to overwrite an existing output.
import { PMTiles } from 'pmtiles';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const BUILD = '20261030.pmtiles';
const SOURCE = `https://build.protomaps.com/${BUILD}`;
const BBOX = { west: 123.4110, south: 13.3520, east: 123.4567, north: 13.3959 }; // boundary extent + ~1 km
const MIN_Z = 10, MAX_Z = 15;
const OUT = process.argv[2];
if (!OUT) throw new Error('usage: npm i --no-save pmtiles@4.5.0 && node scripts/offline-map/extract-la-medalla.mjs public/maps/la-medalla/v<N> (new folder only; bump OFFLINE_MAP_VERSION)');
if (existsSync(OUT)) throw new Error('refusing to overwrite ' + OUT);

const lon2x = (lon, z) => Math.floor(((lon + 180) / 360) * 2 ** z);
const lat2y = (lat, z) => {
  const r = (lat * Math.PI) / 180;
  return Math.floor(((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2) * 2 ** z);
};

const archive = new PMTiles(SOURCE);
const header = await archive.getHeader();
const meta = await archive.getMetadata();
console.log('header', JSON.stringify({ minZoom: header.minZoom, maxZoom: header.maxZoom, tileType: header.tileType, tileCompression: header.tileCompression }));

const tiles = [];
let bytes = 0;
for (let z = MIN_Z; z <= MAX_Z; z++) {
  for (let x = lon2x(BBOX.west, z); x <= lon2x(BBOX.east, z); x++) {
    for (let y = lat2y(BBOX.north, z); y <= lat2y(BBOX.south, z); y++) {
      const res = await archive.getZxy(z, x, y);
      if (!res || !res.data) { tiles.push({ z, x, y, bytes: 0, missing: true }); continue; }
      const buf = Buffer.from(res.data);
      const dir = path.join(OUT, String(z), String(x));
      mkdirSync(dir, { recursive: true });
      writeFileSync(path.join(dir, `${y}.mvt`), buf, { flag: 'wx' });
      bytes += buf.length;
      tiles.push({ z, x, y, bytes: buf.length, sha256: createHash('sha256').update(buf).digest('hex') });
    }
  }
}

const manifest = {
  name: 'La Medalla offline basemap',
  source: SOURCE,
  build: BUILD,
  basemapVersion: meta?.version ?? null,
  license: 'ODbL 1.0 (OpenStreetMap data); Protomaps basemap schema',
  attribution: '© OpenStreetMap contributors · Protomaps',
  extractedAt: new Date().toISOString(),
  bbox: BBOX,
  minZoom: MIN_Z,
  maxDataZoom: MAX_Z,
  tileCount: tiles.filter((t) => !t.missing).length,
  missing: tiles.filter((t) => t.missing).map((t) => `${t.z}/${t.x}/${t.y}`),
  totalBytes: bytes,
  tiles: tiles.filter((t) => !t.missing).map((t) => ({ path: `${t.z}/${t.x}/${t.y}.mvt`, bytes: t.bytes, sha256: t.sha256 })),
};
writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2), { flag: 'wx' });
console.log('tiles', manifest.tileCount, 'missing', manifest.missing.length, 'bytes', bytes, 'basemap', manifest.basemapVersion);
