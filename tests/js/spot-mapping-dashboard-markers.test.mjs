import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const spot = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
const css = readFileSync(path.resolve('resources/css/pages/spot-mapping.css'), 'utf8');
const blade = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');

describe('spot mapping markers match the dashboard map', () => {
    it('uses the dashboard marker size, glyph and border', () => {
        assert.match(css, /--lml-spot-marker-size: 2rem;/);
        assert.match(css, /--lml-spot-marker-glyph: 0\.85rem;/);
        assert.match(css, /--lml-spot-marker-border: 2\.5px;/);
        assert.match(spot, /iconSize: \[32, 32\],\s*iconAnchor: \[16, 16\],/);
    });

    it('keeps a white border (no household status border colours)', () => {
        assert.doesNotMatch(css, /\.lml-spot-map__marker--status-(plotted|pending|new)[^{]*\{[^}]*border-color/);
        assert.match(css, /border: var\(--lml-spot-marker-border, 2\.5px\) solid #fff;/);
    });

    it('draws nearby houses separately; only exact-same coordinates are grouped', () => {
        assert.match(spot, /const groups = buildOverlapGroups\(items\);/);
        assert.doesNotMatch(spot, /buildOverlapGroups\(items, \{\s*getLayerPoint/);
    });

    it('legend shows barangay zones only, like the dashboard', () => {
        assert.doesNotMatch(blade, /Household Status/);
        assert.match(blade, /lml-spot-map__legend-swatch--zone-1/);
    });
});
