/**
 * Dashboard home — read-only La Medalla map.
 * Markers are the same persisted plotted households as Spot Mapping
 * (SpotMappingService::mappedMarkers). No plot tools or coordinate writes.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { createLaMedallaBaseMap } from '../maps/la-medalla-base';

const VIEW_HH_BASE = '/household-profiling/';

const ZONE_LABELS = {
    1: 'Zone 1',
    2: 'Zone 2',
    3: 'Zone 3',
    4: 'Zone 4',
    5: 'Zone 5',
};

function parseServerMarkers(el) {
    const raw = el.getAttribute('data-markers');
    if (!raw) {
        return [];
    }

    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function normalizeZone(zone) {
    const value = typeof zone === 'string'
        ? Number.parseInt(zone.replace(/\D/g, ''), 10)
        : Number(zone);

    if (value >= 1 && value <= 5) {
        return value;
    }

    return null;
}

function isPlottableCoordinate(lat, lng) {
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return false;
    }

    if (lat === 0 && lng === 0) {
        return false;
    }

    return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function displayOrDash(value) {
    const text = String(value ?? '').trim();

    return text !== '' ? text : '—';
}

function zoneDisplay(marker) {
    const label = String(marker.zoneLabel ?? '').trim();
    if (label !== '') {
        return label;
    }

    const zone = normalizeZone(marker.zone);

    return zone ? ZONE_LABELS[zone] : '';
}

function createDashMarkerIcon(marker) {
    const zone = normalizeZone(marker.zone);
    const zoneClass = zone ? `lml-spot-map__marker--zone-${zone}` : '';
    const householdNo = escapeHtml(displayOrDash(marker.householdNo));

    return L.divIcon({
        className: 'lml-spot-map__marker-wrap',
        html: `<span class="lml-spot-map__marker ${zoneClass} lml-spot-map__marker--status-plotted" aria-label="${householdNo}"><i class="bi bi-house-door-fill" aria-hidden="true"></i></span>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        popupAnchor: [0, -14],
    });
}

function popupHtml(marker) {
    const householdNo = displayOrDash(marker.householdNo);
    const householdType = displayOrDash(marker.householdType);
    const houseHead = displayOrDash(marker.houseHead);
    const zone = displayOrDash(zoneDisplay(marker));
    const members = marker.members != null && String(marker.members).trim() !== ''
        ? String(marker.members)
        : '—';

    const canView = Boolean(marker.householdNo) && marker.householdNo !== 'HH-NEW';
    const viewLink = canView
        ? `<a class="lml-dash-map__popup-link" href="${VIEW_HH_BASE}${encodeURIComponent(String(marker.householdKey || marker.householdNo))}">View Household</a>`
        : '';

    return `
        <div class="lml-dash-map__popup">
            <p class="lml-dash-map__popup-row"><span>Household No.</span><strong>${escapeHtml(householdNo)}</strong></p>
            <p class="lml-dash-map__popup-row"><span>Household Type</span><strong>${escapeHtml(householdType)}</strong></p>
            <p class="lml-dash-map__popup-row"><span>Household Head</span><strong>${escapeHtml(houseHead)}</strong></p>
            <p class="lml-dash-map__popup-row"><span>Zone</span><strong>${escapeHtml(zone)}</strong></p>
            <p class="lml-dash-map__popup-row"><span>No. of Members</span><strong>${escapeHtml(members)}</strong></p>
            ${viewLink}
        </div>
    `;
}

function initDashboardHomeMap() {
    const el = document.querySelector('[data-lml-dash-map]');
    if (!el) {
        return;
    }

    const map = createLaMedallaBaseMap(el);

    parseServerMarkers(el).forEach((marker) => {
        const lat = Number(marker.lat);
        const lng = Number(marker.lng);
        if (!isPlottableCoordinate(lat, lng)) {
            return;
        }

        L.marker([lat, lng], {
            icon: createDashMarkerIcon(marker),
            keyboard: true,
            title: String(marker.householdNo || 'Household'),
            riseOnHover: true,
        })
            .bindPopup(popupHtml(marker), {
                maxWidth: 260,
                autoPanPadding: [12, 12],
            })
            .addTo(map);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardHomeMap);
} else {
    initDashboardHomeMap();
}
