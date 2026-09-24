/**
 * Spot Mapping — client-side map export (PNG / PDF via html-to-image + jsPDF).
 */
import { toPng } from 'html-to-image';
import { jsPDF } from 'jspdf';
import L from 'leaflet';
import { isClientOffline } from '../offline/offline-forms.js';
import { OFFLINE_MESSAGES } from '../offline/offline-status.js';

const TILE_SETTLE_MS = 900;
const EXPORT_TITLE_CLASS = 'lml-spot-map__export-title';

/**
 * @param {number|string|null|undefined} zone
 * @returns {number|null}
 */
export function normalizeExportZone(zone) {
    const value = typeof zone === 'string'
        ? Number.parseInt(zone.replace(/\D/g, ''), 10)
        : Number(zone);

    if (value >= 1 && value <= 5) {
        return value;
    }

    return null;
}

/**
 * @param {'overall'|'zone'} scope
 * @param {number|null} zone
 * @returns {string}
 */
function buildExportTitle(scope, zone) {
    if (scope === 'zone' && zone != null) {
        return `Zone ${zone} — Spot Map`;
    }

    return 'Barangay La Medalla — Spot Map';
}

/**
 * @param {'overall'|'zone'} scope
 * @param {number|null} zone
 * @returns {string}
 */
function buildFilename(scope, zone, format) {
    const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const base = scope === 'zone' && zone != null
        ? `spot-map-zone-${zone}-${stamp}`
        : `spot-map-overall-${stamp}`;

    return `${base}.${format}`;
}

function delay(ms) {
    return new Promise((resolve) => {
        window.setTimeout(resolve, ms);
    });
}

function isAuthoritativeSpotMarker(marker) {
    const data = marker?.__lmlData && typeof marker.__lmlData === 'object'
        ? marker.__lmlData
        : marker && typeof marker === 'object'
            ? marker
            : {};

    if (data.isTemp === true) {
        return false;
    }

    return true;
}

function mapCaptureOptions() {
    return {
        cacheBust: true,
        pixelRatio: 2,
        useCORS: true,
        skipFonts: false,
    };
}

/**
 * @typedef {object} SpotMapExportContext
 * @property {import('leaflet').Map} map
 * @property {Map<string, import('leaflet').Marker>} markerById
 * @property {() => import('leaflet').Marker|null} getTempMarker
 * @property {() => void} dismissPlotUi
 * @property {HTMLElement} mapWrap
 * @property {(message: string, options?: object) => void} showMessage
 * @property {() => void} hideMessage
 */

/**
 * @param {SpotMapExportContext} context
 * @param {{ zone?: number|null }} options
 * @returns {{
 *   markers: import('leaflet').Marker[],
 *   hiddenMarkers: import('leaflet').Marker[],
 *   titleEl: HTMLElement|null,
 *   previousBounds: import('leaflet').LatLngBounds|null,
 *   previousCenter: import('leaflet').LatLng,
 *   previousZoom: number,
 *   tempMarkerRemoved: boolean,
 * }}
 */
export function prepareExportView(context, { zone = null } = {}) {
    const { map, markerById, getTempMarker, dismissPlotUi, mapWrap } = context;
    const normalizedZone = zone != null ? normalizeExportZone(zone) : null;
    const allMarkers = [...markerById.values()];
    const authoritative = allMarkers.filter(isAuthoritativeSpotMarker);
    const exportMarkers = normalizedZone == null
        ? authoritative
        : authoritative.filter((marker) => normalizeExportZone(marker.__lmlData?.zone) === normalizedZone);

    dismissPlotUi();

    const tempMarker = getTempMarker();
    let tempMarkerRemoved = false;
    if (tempMarker && map.hasLayer(tempMarker)) {
        map.removeLayer(tempMarker);
        tempMarkerRemoved = true;
    }

    const hiddenMarkers = [];
    allMarkers.forEach((marker) => {
        if (exportMarkers.includes(marker)) {
            return;
        }
        if (map.hasLayer(marker)) {
            map.removeLayer(marker);
            hiddenMarkers.push(marker);
        }
    });

    const previousBounds = map.getBounds();
    const previousCenter = map.getCenter();
    const previousZoom = map.getZoom();

    if (exportMarkers.length > 0) {
        const bounds = exportMarkers.reduce(
            (acc, marker) => acc.extend(marker.getLatLng()),
            L.latLngBounds(exportMarkers[0].getLatLng(), exportMarkers[0].getLatLng()),
        );
        map.fitBounds(bounds, { padding: [48, 48], maxZoom: 18, animate: false });
    }

    map.invalidateSize();

    const scope = normalizedZone == null ? 'overall' : 'zone';
    const titleEl = document.createElement('div');
    titleEl.className = EXPORT_TITLE_CLASS;
    titleEl.setAttribute('aria-hidden', 'true');
    titleEl.textContent = buildExportTitle(scope, normalizedZone);
    mapWrap.prepend(titleEl);

    return {
        markers: exportMarkers,
        hiddenMarkers,
        titleEl,
        previousBounds,
        previousCenter,
        previousZoom,
        tempMarkerRemoved,
    };
}

/**
 * @param {SpotMapExportContext} context
 * @param {ReturnType<typeof prepareExportView>} state
 */
export function restoreExportView(context, state) {
    const { map, getTempMarker } = context;
    const {
        hiddenMarkers,
        titleEl,
        previousCenter,
        previousZoom,
        tempMarkerRemoved,
    } = state;

    hiddenMarkers.forEach((marker) => {
        if (!map.hasLayer(marker)) {
            marker.addTo(map);
        }
    });

    if (tempMarkerRemoved) {
        const tempMarker = getTempMarker();
        if (tempMarker && !map.hasLayer(tempMarker)) {
            tempMarker.addTo(map);
        }
    }

    if (titleEl?.parentNode) {
        titleEl.parentNode.removeChild(titleEl);
    }

    map.setView(previousCenter, previousZoom, { animate: false });
    map.invalidateSize();
    context.onRestoreComplete?.();
}

/**
 * @param {HTMLElement} element
 * @param {{ offline?: boolean }} [options]
 * @returns {Promise<string>}
 */
async function captureMapWrap(element) {
    return toPng(element, mapCaptureOptions());
}

/**
 * @param {string} dataUrl
 * @param {string} filename
 */
function downloadPng(dataUrl, filename) {
    const link = document.createElement('a');
    link.download = filename;
    link.href = dataUrl;
    link.click();
}

/**
 * @param {string} dataUrl
 * @param {string} filename
 */
async function downloadPdf(dataUrl, filename) {
    const image = new Image();
    await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = reject;
        image.src = dataUrl;
    });

    const width = image.naturalWidth || image.width;
    const height = image.naturalHeight || image.height;
    const orientation = width >= height ? 'landscape' : 'portrait';
    const pdf = new jsPDF({
        orientation,
        unit: 'px',
        format: [width, height],
        hotfixes: ['px_scaling'],
    });

    pdf.addImage(dataUrl, 'PNG', 0, 0, width, height);
    pdf.save(filename);
}

/**
 * @param {SpotMapExportContext} context
 * @param {{ scope: 'overall'|'zone', zone?: number|null, format: 'png'|'pdf' }} options
 */
export async function runSpotMapExport(context, options) {
    const { scope, format } = options;
    const zone = scope === 'zone' ? normalizeExportZone(options.zone) : null;

    if (isClientOffline(options)) {
        throw new Error(OFFLINE_MESSAGES.exportUnavailable);
    }

    if (scope === 'zone' && zone == null) {
        throw new Error('Please select a valid zone between 1 and 5.');
    }

    const state = prepareExportView(context, { zone });

    if (state.markers.length === 0) {
        restoreExportView(context, state);
        throw new Error(
            scope === 'zone'
                ? `No plotted households in Zone ${zone}. Plot households in this zone before exporting.`
                : 'No plotted households are available to export yet.',
        );
    }

    try {
        await new Promise((resolve) => {
            context.map.whenReady(resolve);
        });
        await delay(TILE_SETTLE_MS);

        const dataUrl = await captureMapWrap(context.mapWrap);
        const ext = format === 'pdf' ? 'pdf' : 'png';
        const filename = buildFilename(scope, zone, ext);

        if (format === 'pdf') {
            await downloadPdf(dataUrl, filename);
        } else {
            downloadPng(dataUrl, filename);
        }
    } finally {
        restoreExportView(context, state);
    }
}

/**
 * @param {HTMLElement} root
 * @param {SpotMapExportContext} context
 */
export function initSpotMappingExport(root, context) {
    const menuWrap = root.querySelector('[data-spot-map-export-menu]');
    const toggle = root.querySelector('[data-spot-map-export-toggle]');
    const menu = root.querySelector('[data-spot-map-export-menu-panel]');
    const statusEl = root.querySelector('[data-spot-map-export-status]');
    const overallButtons = root.querySelectorAll('[data-spot-map-export]');
    const zoneSelect = root.querySelector('[data-spot-map-export-zone-select]');
    const zoneImageButton = root.querySelector('[data-spot-map-export-zone-image]');
    const zonePdfButton = root.querySelector('[data-spot-map-export-zone-pdf]');

    if (!menuWrap || !toggle || !menu) {
        return;
    }

    let exporting = false;

    function setStatus(message, { isError = false } = {}) {
        if (!statusEl) {
            return;
        }

        if (!message) {
            statusEl.hidden = true;
            statusEl.textContent = '';
            statusEl.classList.remove('is-error');
            return;
        }

        statusEl.hidden = false;
        statusEl.textContent = message;
        statusEl.classList.toggle('is-error', isError);
    }

    function closeMenu() {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', () => {
        const open = menu.hidden;
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
        if (!menuWrap.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    async function handleExport(button, { scope, zone = null, format }) {
        if (exporting) {
            return;
        }

        if (isClientOffline()) {
            const message = OFFLINE_MESSAGES.exportUnavailable;
            setStatus(message, { isError: true });
            context.showMessage(message, {
                mode: 'error',
                icon: 'bi-exclamation-triangle-fill',
            });
            return;
        }

        exporting = true;
        root.classList.add('is-exporting');
        toggle.disabled = true;
        button.setAttribute('aria-busy', 'true');
        setStatus('Preparing map export…');

        try {
            await runSpotMapExport(context, { scope, zone, format });
            setStatus('');
            closeMenu();
        } catch (error) {
            const message = error instanceof Error && error.message
                ? error.message
                : 'Unable to export the map. Please try again.';
            setStatus(message, { isError: true });
            context.showMessage(message, {
                mode: 'error',
                icon: 'bi-exclamation-triangle-fill',
            });
        } finally {
            exporting = false;
            root.classList.remove('is-exporting');
            toggle.disabled = false;
            button.removeAttribute('aria-busy');
        }
    }

    overallButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const format = button.getAttribute('data-format') === 'pdf' ? 'pdf' : 'png';
            handleExport(button, { scope: 'overall', format });
        });
    });

    zoneImageButton?.addEventListener('click', () => {
        const zone = normalizeExportZone(zoneSelect?.value);
        if (zone == null) {
            const message = 'Please select a zone before exporting.';
            setStatus(message, { isError: true });
            zoneSelect?.focus();
            return;
        }

        handleExport(zoneImageButton, { scope: 'zone', zone, format: 'png' });
    });

    zonePdfButton?.addEventListener('click', () => {
        const zone = normalizeExportZone(zoneSelect?.value);
        if (zone == null) {
            const message = 'Please select a zone before exporting.';
            setStatus(message, { isError: true });
            zoneSelect?.focus();
            return;
        }

        handleExport(zonePdfButton, { scope: 'zone', zone, format: 'pdf' });
    });
}
