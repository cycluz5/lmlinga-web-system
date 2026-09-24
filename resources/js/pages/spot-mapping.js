/**
 * Spot Mapping — DB18-C: MySQL-backed markers/stats; coordinates on households.
 * Browser Geolocation API for field plotting UX. Environmental Health handoff
 * remains temporarily compatible until DB18-D.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { createLaMedallaBaseMap, LA_MEDALLA_CENTER, LA_MEDALLA_ZOOM } from '../maps/la-medalla-base';
import { isClientOffline, isNetworkFailure, queuePlotNewHousehold } from '../offline/offline-forms';
import { persistPlotHouseholdReadModel } from '../offline/offline-hp-store';
import { initSpotMappingExport } from './spot-mapping-export';
import {
    applyOverlapLayerPlan,
    buildOverlapGroups,
    groupAriaLabel,
    planOverlapVisuals,
} from './spot-mapping-overlap';

/**
 * Spot Mapping scope: Barangay La Medalla, Iriga City, Camarines Sur only.
 * Fixed map center from PhilAtlas (13.3806, 123.4312).
 *
 * DEMO_SCOPE_RADIUS_METERS is a UI-only field safeguard.
 * Do not treat this radius as legal barangay boundaries.
 */
const DEMO_CENTER = LA_MEDALLA_CENTER;
const DEMO_ZOOM = LA_MEDALLA_ZOOM;
const GPS_ZOOM = 17;
const DEMO_SCOPE_RADIUS_METERS = 2000;

const OUTSIDE_SCOPE_MESSAGE =
    'You appear to be outside Barangay La Medalla. Household plotting is limited to Barangay La Medalla.';

const GENERIC_PLOT_FAILURE =
    'Unable to register this household. Check the details and try again.';

function parseServerMarkers(root) {
    const raw = root.getAttribute('data-markers');
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

function parsePendingCandidates(root) {
    const raw = root.getAttribute('data-pending-candidates');
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

const STATUS_LABELS = {
    plotted: 'Completed / Plotted',
    pending: 'Pending',
    new: 'Pending',
};

const MARKER_ICONS = {
    plotted: 'bi-house-door-fill',
    pending: 'bi-house-door-fill',
    new: 'bi-plus-lg',
};

const ZONE_LABELS = {
    1: 'Zone 1',
    2: 'Zone 2',
    3: 'Zone 3',
    4: 'Zone 4',
    5: 'Zone 5',
};

const GEO_OPTIONS = {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 0,
};

function escapeAttr(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
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

/**
 * Unique client-side ID for each temporary plot in the current session.
 * Prevents markerById collisions when multiple households are plotted
 * without a page refresh.
 */
function createTempHouseholdId() {
    if (
        typeof crypto !== 'undefined'
        && typeof crypto.randomUUID === 'function'
    ) {
        return `demo-temp-${crypto.randomUUID()}`;
    }

    return `demo-temp-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function zoneLabel(zone) {
    const normalized = normalizeZone(zone);
    return normalized ? ZONE_LABELS[normalized] : '—';
}

function canonicalHouseholdType(value) {
    const raw = String(value || '').trim();
    if (raw === '') {
        return '';
    }

    const upper = raw.toUpperCase();
    if (upper === 'HHTS' || upper === 'NHTS') {
        return 'NHTS';
    }
    if (upper === 'NON-HHTS' || upper === 'NON-NHTS') {
        return 'Non-NHTS';
    }

    return '';
}

function plotFormHouseholdType(canonical) {
    if (canonical === 'NHTS' || canonical === 'Non-NHTS') {
        return canonical;
    }

    return '';
}

function markerAccessibleName(data) {
    const statusLabel = STATUS_LABELS[data.status] || 'Household';
    const zone = zoneLabel(data.zone);
    const zonePart = zone !== '—' ? `, ${zone}` : '';
    return `${statusLabel}: ${data.householdNo}${zonePart}`;
}

function createMarkerIcon(data, selected = false, accessibleName = '', count = 1) {
    const safeStatus = STATUS_LABELS[data.status] ? data.status : 'plotted';
    const iconClass = MARKER_ICONS[safeStatus];
    const selectedClass = selected ? ' is-selected' : '';
    const zone = normalizeZone(data.zone);
    const zoneClass = zone ? `lml-spot-map__marker--zone-${zone}` : '';
    const statusClass = `lml-spot-map__marker--status-${safeStatus}`;
    const label = accessibleName || markerAccessibleName(data);
    const countBadge = count > 1
        ? `<span class="lml-spot-map__marker-count" aria-hidden="true">${count}</span>`
        : '';

    return L.divIcon({
        className: 'lml-spot-map__marker-wrap',
        html: `<span class="lml-spot-map__marker ${zoneClass} ${statusClass}${selectedClass}" aria-label="${escapeAttr(label)}">${countBadge}<i class="bi ${iconClass}" aria-hidden="true"></i></span>`,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
        popupAnchor: [0, -12],
    });
}

function applyMarkerAccessibleName(marker, accessibleName) {
    const el = marker.getElement();
    if (!el) {
        return;
    }

    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', accessibleName);
}

function formatCoord(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return '—';
    }

    return value.toFixed(6);
}

function formatAccuracy(meters) {
    if (typeof meters !== 'number' || Number.isNaN(meters)) {
        return '—';
    }

    return `±${Math.round(meters)} m`;
}

function geolocationErrorMessage(error) {
    const code = error?.code;

    if (code === 1) {
        return 'Location permission was denied. Please enable location/GPS for this site, then tap Plot New Household again — or tap the map to place a location manually.';
    }

    if (code === 2) {
        return 'Location is unavailable. Please turn on device location/GPS, then try again — or tap the map to place a location manually.';
    }

    if (code === 3) {
        return 'Location request timed out. Please check GPS signal and try again — or tap the map to place a location manually.';
    }

    return 'Location access is unavailable. Please enable GPS or manually select a location on the map.';
}

/**
 * Haversine distance in meters between two WGS84 coordinates.
 */
function distanceMeters(lat1, lng1, lat2, lng2) {
    const toRad = (degrees) => (degrees * Math.PI) / 180;
    const earthRadiusMeters = 6371000;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;

    return 2 * earthRadiusMeters * Math.asin(Math.min(1, Math.sqrt(a)));
}

/**
 * UI-only demo scope check against DEMO_CENTER + DEMO_SCOPE_RADIUS_METERS.
 * Swap this helper later for polygon / GIS / backend geofencing without
 * changing the GPS success / manual fallback workflow.
 */
function isWithinDemoScope(lat, lng) {
    const [centerLat, centerLng] = DEMO_CENTER;
    return distanceMeters(centerLat, centerLng, lat, lng) <= DEMO_SCOPE_RADIUS_METERS;
}

export function queuedSpotHouseholdNo(value) {
    const raw = String(value ?? '').trim();
    return /^[0-9]{3}$/.test(raw) || /^HH-[0-9]+$/i.test(raw) ? raw : '';
}

export function plotSyncHouseholdNo(detail = {}) {
    const synced = Array.isArray(detail.synced) ? detail.synced : [];
    for (let i = synced.length - 1; i >= 0; i -= 1) {
        const row = synced[i];
        if (row?.operation_type !== 'PLOT_HOUSEHOLD_WITH_HEAD') {
            continue;
        }
        const no = queuedSpotHouseholdNo(
            row.identities?.household_no || row.body?.household?.household_no,
        );
        if (no) {
            return no;
        }
    }
    return '';
}

export function environmentalHealthUrlForHousehold(householdNo) {
    const no = queuedSpotHouseholdNo(householdNo);
    return no ? `/environmental-health/household-water-supply?household=${encodeURIComponent(no)}` : '';
}

export function shouldReconcileQueuedSpotPlot({
    hasTempMarker = false,
    isQueued = false,
    householdNo = '',
    pending,
} = {}) {
    if (Number(pending) !== 0) {
        return false;
    }

    return Boolean(hasTempMarker && isQueued && queuedSpotHouseholdNo(householdNo));
}

export function buildPromotedSpotMarkerFromQueued(input = {}) {
    const householdNo = queuedSpotHouseholdNo(input.householdNo);
    const first = String(input.headFirstName || '').trim();
    const middle = String(input.headMiddleName || '').trim();
    const last = String(input.headLastName || '').trim();
    const houseHead = [first, middle, last].filter(Boolean).join(' ') || '—';
    const zone = normalizeZone(input.zone);

    return {
        id: `hh-${householdNo}`,
        householdNo,
        houseHead,
        headFirstName: first,
        headMiddleName: middle,
        headLastName: last,
        householdType: String(input.householdType || '').trim(),
        zone,
        zoneLabel: zone ? ZONE_LABELS[zone] : '',
        members: 1,
        lat: Number(input.lat),
        lng: Number(input.lng),
        status: 'plotted',
        isTemp: false,
    };
}

export function statsAfterQueuedSpotPlotPromotion(stats = {}) {
    return {
        total: (Number(stats.total) || 0) + 1,
        plotted: (Number(stats.plotted) || 0) + 1,
        pending: Number(stats.pending) || 0,
    };
}

export const OFFLINE_PLOT_PROMPT =
    "You're offline. Click on the map to plot the household location.";

/**
 * Decide GPS vs manual plot without touching Leaflet.
 * Offline never requests geolocation or moves the viewport.
 */
export function gpsPlotPlan(input = {}) {
    if (input.offline === true) {
        return {
            requestGps: false,
            flyTo: false,
            placing: true,
            locating: false,
            preserveViewport: true,
            overlay: OFFLINE_PLOT_PROMPT,
            overlayMode: 'info',
            zoom: null,
            branch: 'offline',
        };
    }

    if (input.error) {
        return {
            requestGps: true,
            flyTo: false,
            placing: true,
            locating: false,
            preserveViewport: true,
            overlayMode: 'error',
            zoom: null,
            branch: 'gps-error',
        };
    }

    if (input.gpsReceived === true) {
        if (input.inScope === true) {
            return {
                requestGps: true,
                flyTo: true,
                placing: false,
                locating: false,
                preserveViewport: false,
                zoom: 17,
                branch: 'gps-in-scope',
            };
        }

        return {
            requestGps: true,
            flyTo: false,
            placing: true,
            locating: false,
            preserveViewport: true,
            overlayMode: 'error',
            zoom: null,
            branch: 'gps-outside-scope',
        };
    }

    return {
        requestGps: true,
        flyTo: false,
        placing: false,
        locating: true,
        preserveViewport: true,
        overlay: 'Getting your current location…',
        overlayMode: 'loading',
        zoom: null,
        branch: 'gps-request',
    };
}

export function reconcileQueuedSpotPlotAfterSync(context = {}, detail = {}) {
    const householdNo = queuedSpotHouseholdNo(context.householdNo) || plotSyncHouseholdNo(detail);
    if (
        !shouldReconcileQueuedSpotPlot({
            hasTempMarker: Boolean(context.tempMarker),
            isQueued: Boolean(context.isQueued),
            householdNo,
            pending: detail.pending,
        })
    ) {
        return { promoted: false };
    }

    const tempData = context.tempMarker?.__lmlData && typeof context.tempMarker.__lmlData === 'object'
        ? context.tempMarker.__lmlData
        : {};
    const form = context.formValues && typeof context.formValues === 'object'
        ? context.formValues
        : {};
    const markerId = `hh-${householdNo}`;
    const alreadyHad = context.markerById?.has?.(markerId) === true;
    const promoted = buildPromotedSpotMarkerFromQueued({
        householdNo,
        headFirstName: form.headFirstName ?? tempData.headFirstName,
        headMiddleName: form.headMiddleName ?? tempData.headMiddleName,
        headLastName: form.headLastName ?? tempData.headLastName,
        zone: form.zone ?? tempData.zone,
        householdType: form.householdType ?? tempData.householdType,
        lat: tempData.lat,
        lng: tempData.lng,
    });

    const mapped = context.upsertMappedMarker?.(promoted);
    if (!mapped && !alreadyHad) {
        return { promoted: false };
    }

    context.closePanel?.({ removeTemp: true });
    context.resetQueuedUi?.();

    if (!alreadyHad) {
        const current = typeof context.readStats === 'function'
            ? context.readStats()
            : { total: 0, plotted: 0, pending: 0 };
        context.applyStats?.(statsAfterQueuedSpotPlotPromotion(current));
    }

    return { promoted: true, markerId, alreadyHad, householdNo };
}

function initSpotMapping() {
    const root = document.querySelector('[data-lml-spot-map]');
    if (!root) {
        return;
    }

    const mapEl = root.querySelector('#lml-spot-map-canvas');
    const plotBtn = root.querySelector('[data-spot-map-plot]');
    const plotExistingBtn = root.querySelector('[data-spot-map-plot-existing]');
    const pendingSection = root.querySelector('[data-spot-map-pending]');
    const pendingListEl = root.querySelector('[data-spot-map-pending-list]');
    const overlay = root.querySelector('[data-spot-map-overlay]');
    const overlayIcon = root.querySelector('[data-spot-map-overlay-icon]');
    const overlayText = root.querySelector('[data-spot-map-overlay-text]');
    const panel = root.querySelector('[data-spot-map-panel]');
    const noteEl = root.querySelector('[data-spot-map-note]');
    const closeBtn = root.querySelector('[data-spot-map-close]');
    const cancelBtn = root.querySelector('[data-spot-map-cancel]');
    const confirmBtn = root.querySelector('[data-spot-map-confirm]');
    const viewHhLink = root.querySelector('[data-spot-map-view-hh]');
    const zoneBadge = root.querySelector('[data-zone-badge]');
    const zoneDot = root.querySelector('[data-zone-dot]');
    const zoneText = root.querySelector('[data-zone-text]');
    const zoneSelect = root.querySelector('[data-spot-map-zone-select]');
    const hhTypeSelect = root.querySelector('[data-spot-map-hh-type-select]');
    const hhTypeText = root.querySelector('[data-hh-type-text]');
    const headFirstEl = root.querySelector('[data-spot-map-head-first]');
    const headMiddleEl = root.querySelector('[data-spot-map-head-middle]');
    const headLastEl = root.querySelector('[data-spot-map-head-last]');
    const headBirthdayEl = root.querySelector('[data-spot-map-head-birthday]');
    const headBirthdayWrap = root.querySelector('[data-spot-map-head-birthday-wrap]');
    const headSexEl = root.querySelector('[data-spot-map-head-sex]');
    const headSexWrap = root.querySelector('[data-spot-map-head-sex-wrap]');
    const headCivilStatusEl = root.querySelector('[data-spot-map-head-civil-status]');
    const headCivilStatusWrap = root.querySelector('[data-spot-map-head-civil-status-wrap]');
    const dateRegisteredInput = root.querySelector('[data-spot-map-date-registered]');
    const dateRegisteredWrap = root.querySelector('[data-spot-map-date-wrap]');
    const householdNumberText = root.querySelector('[data-household-number-text]');
    const householdNoInput = root.querySelector('[data-spot-map-household-no]');
    const consentWrap = root.querySelector('[data-spot-map-consent]');
    const consentInput = root.querySelector('[data-spot-map-consent-input]');
    const VIEW_HH_BASE = '/household-profiling/';
    const detailsBody = panel.querySelector('[data-spot-map-details-body]');
    const groupSelector = panel.querySelector('[data-spot-map-group-selector]');
    const groupList = panel.querySelector('[data-spot-map-group-list]');
    const panelTitle = panel.querySelector('.lml-spot-map__panel-title');
    const SEX_VALUES = ['Male', 'Female'];
    const CIVIL_STATUS_VALUES = ['Single', 'Married', 'Widowed', 'Separated', 'Live-In'];

    const FIELD_ERROR_MESSAGES = {
        firstName: 'First name is required.',
        lastName: 'Last name is required.',
        birthday: 'Birthday is required and must not be in the future.',
        sex: 'Please select sex.',
        civilStatus: 'Please select civil status.',
        householdType: 'Please select a household type.',
        zone: 'Please select a zone.',
        householdNo: 'Household No. must be exactly 3 digits.',
        consent: 'Consent from the head of household is required before plotting.',
    };

    const fieldControls = {
        firstName: headFirstEl,
        lastName: headLastEl,
        birthday: headBirthdayEl,
        sex: headSexEl,
        civilStatus: headCivilStatusEl,
        householdType: hhTypeSelect,
        zone: zoneSelect,
        householdNo: householdNoInput,
        consent: consentInput,
    };

    if (!mapEl || !plotBtn || !overlay || !panel) {
        return;
    }

    function displayOrDash(value) {
        const text = String(value || '').trim();

        return text !== '' ? text : '—';
    }

    function setHeadNameFields(data, { editable = false } = {}) {
        const first = String(data?.headFirstName ?? '').trim();
        const middle = String(data?.headMiddleName ?? '').trim();
        const last = String(data?.headLastName ?? '').trim();

        if (headFirstEl) {
            headFirstEl.value = editable ? first : displayOrDash(first);
            headFirstEl.readOnly = !editable;
        }
        if (headMiddleEl) {
            headMiddleEl.value = editable ? middle : displayOrDash(middle);
            headMiddleEl.readOnly = !editable;
        }
        if (headLastEl) {
            headLastEl.value = editable ? last : displayOrDash(last);
            headLastEl.readOnly = !editable;
        }
    }

    function persistHeadNameDraft() {
        if (!isEditingActiveTempPlot()) {
            return;
        }

        tempMarker.__lmlData = {
            ...tempMarker.__lmlData,
            headFirstName: (headFirstEl?.value || '').trim(),
            headMiddleName: (headMiddleEl?.value || '').trim(),
            headLastName: (headLastEl?.value || '').trim(),
            birthday: (headBirthdayEl?.value || '').trim(),
            sex: headSexEl?.value || '',
            civilStatus: headCivilStatusEl?.value || '',
            householdType: hhTypeSelect?.value || '',
            dateRegistered: dateRegisteredInput?.value || '',
        };
    }

    let placing = false;
    let locating = false;
    let locateRequestId = 0;
    let selectedId = null;
    let tempMarker = null;
    let panelReturnFocusEl = null;
    let selectedPendingHousehold = null;
    let pendingCandidates = parsePendingCandidates(root).map((row) => ({
        householdNo: String(row.householdNo || row.household_no || '').trim(),
        houseHead: String(row.houseHead || row.house_head || '—').trim() || '—',
        householdType: String(row.householdType || row.household_type || '').trim(),
        zone: normalizeZone(row.zone),
        zoneLabel: String(row.zoneLabel || row.zone_label || '').trim(),
        members: row.members != null ? Number(row.members) : 0,
        headFirstName: String(row.headFirstName || row.head_first_name || '').trim(),
        headMiddleName: String(row.headMiddleName || row.head_middle_name || '').trim(),
        headLastName: String(row.headLastName || row.head_last_name || '').trim(),
    })).filter((row) => row.householdNo !== '');
    const markerById = new Map();
    const groupMarkers = new Map();
    const plotSearchParams = new URLSearchParams(window.location.search);
    const continuePlotHousehold = String(plotSearchParams.get('plot_household') || '').trim();
    const continuePlotMode = plotSearchParams.get('plot') === '1' || continuePlotHousehold !== '';

    function syncPlotExistingButton() {
        if (!plotExistingBtn) {
            return;
        }
        const enabled = Boolean(selectedPendingHousehold) && !locating;
        plotExistingBtn.disabled = !enabled;
        plotExistingBtn.setAttribute('aria-disabled', enabled ? 'false' : 'true');
    }

    function clearPendingSelection({ render = true } = {}) {
        selectedPendingHousehold = null;
        if (render) {
            renderPendingPicker();
        } else {
            syncPlotExistingButton();
        }
    }

    function selectPendingHousehold(householdNo, { startPlot = false } = {}) {
        const match = pendingCandidates.find((row) => row.householdNo === householdNo);
        if (!match) {
            return false;
        }
        selectedPendingHousehold = match;
        renderPendingPicker();
        if (startPlot) {
            startGpsPlot();
        }
        return true;
    }

    function removePendingCandidate(householdNo) {
        const target = String(householdNo || '').trim();
        pendingCandidates = pendingCandidates.filter((row) => row.householdNo !== target);
        if (selectedPendingHousehold?.householdNo === target) {
            selectedPendingHousehold = null;
        }
        renderPendingPicker();
    }

    function renderPendingPicker() {
        if (!pendingSection || !pendingListEl) {
            syncPlotExistingButton();
            return;
        }

        pendingListEl.replaceChildren();

        if (pendingCandidates.length === 0) {
            pendingSection.hidden = true;
            syncPlotExistingButton();
            return;
        }

        pendingSection.hidden = false;

        pendingCandidates.forEach((candidate) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lml-spot-map__pending-item lml-focus-ring';
            button.setAttribute('role', 'option');
            button.setAttribute('data-pending-household-no', candidate.householdNo);
            button.setAttribute('aria-label', `Select pending household ${candidate.householdNo}`);

            const selected = selectedPendingHousehold?.householdNo === candidate.householdNo;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-selected', selected ? 'true' : 'false');

            const numberEl = document.createElement('span');
            numberEl.className = 'lml-spot-map__pending-item-no';
            numberEl.textContent = candidate.householdNo;

            const metaEl = document.createElement('span');
            metaEl.className = 'lml-spot-map__pending-item-meta';
            const head = candidate.houseHead && candidate.houseHead !== '—'
                ? candidate.houseHead
                : 'Head not recorded';
            const zone = candidate.zoneLabel || zoneLabel(candidate.zone);
            metaEl.textContent = zone && zone !== '—' ? `${head} · ${zone}` : head;

            button.append(numberEl, metaEl);
            button.addEventListener('click', () => {
                selectPendingHousehold(candidate.householdNo);
            });
            item.appendChild(button);
            pendingListEl.appendChild(item);
        });

        syncPlotExistingButton();
    }

    function isEditingActiveTempPlot() {
        return Boolean(
            tempMarker
            && tempMarker.__lmlData?.isTemp
            && selectedId
            && selectedId === tempMarker.__lmlData.id,
        );
    }

    const map = createLaMedallaBaseMap(mapEl, { zoom: DEMO_ZOOM });

    let tileOfflineNoted = false;
    const TILES_OFFLINE_MESSAGE =
        'Map imagery needs a connection. Coordinates already captured on this page are kept; you can still plot using that location.';

    map.on('tileerror', () => {
        if (tileOfflineNoted || locating) {
            return;
        }
        if (!isClientOffline()) {
            return;
        }
        tileOfflineNoted = true;
        const note = root.querySelector('[data-spot-map-note]');
        if (note && (note.hidden || !String(note.textContent || '').trim())) {
            note.textContent = TILES_OFFLINE_MESSAGE;
            note.hidden = false;
        }
    });

    function showOverlay(message, { mode = 'info', icon = 'bi-geo-alt-fill' } = {}) {
        overlay.hidden = false;
        overlay.dataset.mode = mode;
        if (overlayIcon) {
            overlayIcon.className = `bi ${icon}`;
        }
        if (overlayText) {
            overlayText.textContent = message;
        }
    }

    function hideOverlay() {
        overlay.hidden = true;
        overlay.dataset.mode = 'info';
    }

    function focusMapCanvas() {
        if (typeof mapEl.focus === 'function') {
            mapEl.focus({ preventScroll: true });
        }
    }

    function setLocating(next) {
        locating = next;
        root.classList.toggle('is-locating', locating);
        plotBtn.setAttribute('aria-busy', locating ? 'true' : 'false');

        if (next) {
            // Move focus before disabling Plot so keyboard focus does not fall to <body>.
            focusMapCanvas();
        }

        plotBtn.disabled = locating;
        syncPlotExistingButton();
    }

    function clearFieldError(fieldKey) {
        const control = fieldControls[fieldKey];
        const errorEl = panel.querySelector(`[data-error-for="${fieldKey}"]`);

        if (control) {
            control.classList.remove('is-invalid');
            control.removeAttribute('aria-invalid');
        }

        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = '';
        }
    }

    function clearAllFieldErrors() {
        Object.keys(FIELD_ERROR_MESSAGES).forEach((key) => clearFieldError(key));
    }

    function setFieldError(fieldKey, message) {
        const control = fieldControls[fieldKey];
        const errorEl = panel.querySelector(`[data-error-for="${fieldKey}"]`);

        if (control) {
            control.classList.add('is-invalid');
            control.setAttribute('aria-invalid', 'true');
        }

        if (errorEl) {
            errorEl.hidden = false;
            errorEl.textContent = message;
        }
    }

    function validatePlotForm() {
        clearAllFieldErrors();

        const isExistingPendingPlot = Boolean(tempMarker?.__lmlData?.plotMode === 'existing');

        if (isExistingPendingPlot) {
            const householdNo = String(tempMarker.__lmlData?.householdNo || '').trim();
            const hasConsent = Boolean(consentInput?.checked);
            const invalid = [];

            if (!householdNo || householdNo === 'HH-NEW') {
                invalid.push(consentInput);
            }

            if (!hasConsent) {
                setFieldError('consent', FIELD_ERROR_MESSAGES.consent);
                invalid.push(consentInput);
            }

            return {
                valid: invalid.length === 0,
                firstInvalid: invalid.find(Boolean) || null,
                values: {
                    householdNo,
                    firstName: '',
                    middleName: '',
                    lastName: '',
                    birthday: '',
                    sex: '',
                    civilStatus: '',
                    householdType: '',
                    zone: normalizeZone(tempMarker.__lmlData?.zone),
                    dateRegistered: '',
                },
            };
        }

        persistHeadNameDraft();

        const firstName = (headFirstEl?.value || '').trim();
        const middleName = (headMiddleEl?.value || '').trim();
        const lastName = (headLastEl?.value || '').trim();
        const birthday = (headBirthdayEl?.value || '').trim();
        const sex = SEX_VALUES.includes(headSexEl?.value) ? headSexEl.value : '';
        const civilStatus = CIVIL_STATUS_VALUES.includes(headCivilStatusEl?.value)
            ? headCivilStatusEl.value
            : '';
        const householdType = canonicalHouseholdType(hhTypeSelect?.value);
        const zone = normalizeZone(zoneSelect?.value);
        const hasConsent = Boolean(consentInput?.checked);
        const dateRegistered = (dateRegisteredInput?.value || '').trim();
        const todayIso = new Date().toISOString().slice(0, 10);

        const invalid = [];

        if (!firstName) {
            setFieldError('firstName', FIELD_ERROR_MESSAGES.firstName);
            invalid.push(headFirstEl);
        }

        if (!lastName) {
            setFieldError('lastName', FIELD_ERROR_MESSAGES.lastName);
            invalid.push(headLastEl);
        }

        if (!birthday) {
            setFieldError('birthday', FIELD_ERROR_MESSAGES.birthday);
            invalid.push(headBirthdayEl);
        } else if (birthday > todayIso) {
            setFieldError('birthday', FIELD_ERROR_MESSAGES.birthday);
            invalid.push(headBirthdayEl);
        }

        if (!sex) {
            setFieldError('sex', FIELD_ERROR_MESSAGES.sex);
            invalid.push(headSexEl);
        }

        if (!civilStatus) {
            setFieldError('civilStatus', FIELD_ERROR_MESSAGES.civilStatus);
            invalid.push(headCivilStatusEl);
        }

        if (householdType !== 'NHTS' && householdType !== 'Non-NHTS') {
            setFieldError('householdType', FIELD_ERROR_MESSAGES.householdType);
            invalid.push(hhTypeSelect);
        }

        if (!zone) {
            setFieldError('zone', FIELD_ERROR_MESSAGES.zone);
            invalid.push(zoneSelect);
        }

        const householdNo = (householdNoInput?.value || '').trim();
        if (!/^[0-9]{3}$/.test(householdNo)) {
            setFieldError('householdNo', FIELD_ERROR_MESSAGES.householdNo);
            invalid.push(householdNoInput);
        }

        if (!hasConsent) {
            setFieldError('consent', FIELD_ERROR_MESSAGES.consent);
            invalid.push(consentInput);
        }

        return {
            valid: invalid.length === 0,
            firstInvalid: invalid.find(Boolean) || null,
            values: {
                firstName,
                middleName,
                lastName,
                birthday,
                sex,
                civilStatus,
                householdType,
                zone,
                householdNo,
                dateRegistered,
            },
        };
    }

    function setPlacing(next, message = null, overlayMode = null) {
        placing = next;
        root.classList.toggle('is-placing', placing);
        plotBtn.setAttribute('aria-pressed', placing ? 'true' : 'false');

        if (placing) {
            closePanel();
            const mode = overlayMode || (message ? 'error' : 'info');
            showOverlay(
                message
                    || 'Click on the map to plot a location.',
                {
                    mode,
                    icon: mode === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-geo-alt-fill',
                },
            );
        } else if (!locating) {
            hideOverlay();
        }
    }

    function setPanelMode(mode) {
        const selector = mode === 'selector';
        if (detailsBody) {
            detailsBody.hidden = selector;
        }
        if (groupSelector) {
            groupSelector.hidden = !selector;
        }
        if (panelTitle) {
            panelTitle.textContent = 'Household Details';
        }
        if (confirmBtn && selector) {
            confirmBtn.hidden = true;
        }
        if (viewHhLink && selector) {
            viewHhLink.hidden = true;
        }
    }

    function fillPanel(data) {
        const fields = {
            members: data.members != null ? String(data.members) : '—',
            statusLabel: STATUS_LABELS[data.status] ?? data.status ?? '—',
            lat: formatCoord(data.lat),
            lng: formatCoord(data.lng),
        };

        setPanelMode('details');

        Object.entries(fields).forEach(([key, value]) => {
            const el = panel.querySelector(`[data-field="${key}"]`);
            if (el) {
                el.textContent = value;
            }
        });

        const zone = normalizeZone(data.zone);
        const isExistingPendingPlot = Boolean(data.isTemp && data.plotMode === 'existing');
        const canAssignZone = Boolean(data.isTemp && !isExistingPendingPlot);

        if (householdNumberText) {
            householdNumberText.hidden = canAssignZone;
            if (!canAssignZone) {
                householdNumberText.textContent = data.householdNo && data.householdNo !== 'HH-NEW'
                    ? data.householdNo
                    : '—';
            }
        }

        if (householdNoInput) {
            householdNoInput.hidden = !canAssignZone;
            householdNoInput.disabled = !canAssignZone;
            householdNoInput.required = canAssignZone;
            if (!canAssignZone) {
                householdNoInput.value = '';
            }
        }

        setHeadNameFields({
            headFirstName: data.headFirstName || '',
            headMiddleName: data.headMiddleName || '',
            headLastName: data.headLastName || '',
            houseHead: data.houseHead || '',
        }, { editable: canAssignZone });

        if (zoneSelect) {
            zoneSelect.hidden = !canAssignZone;
            if (zone) {
                zoneSelect.value = String(zone);
            } else if (canAssignZone) {
                zoneSelect.selectedIndex = 0;
            }
        }

        if (zoneBadge && zoneDot && zoneText) {
            zoneBadge.hidden = canAssignZone;
            zoneText.textContent = data.zoneLabel || zoneLabel(zone);
            zoneDot.className = 'lml-spot-map__zone-dot'
                + (zone ? ` lml-spot-map__zone-dot--${zone}` : '');
        }

        const canonicalType = canonicalHouseholdType(data.householdType);
        const formType = plotFormHouseholdType(canonicalType);

        if (hhTypeSelect && hhTypeText) {
            hhTypeSelect.hidden = !canAssignZone;
            hhTypeText.hidden = canAssignZone;
            if (canAssignZone) {
                if (formType) {
                    hhTypeSelect.value = formType;
                } else {
                    hhTypeSelect.selectedIndex = 0;
                }
            }
            hhTypeText.textContent = canonicalType || '—';
        }

        if (dateRegisteredWrap) {
            dateRegisteredWrap.hidden = !canAssignZone;
        }
        if (dateRegisteredInput && canAssignZone && data.dateRegistered) {
            dateRegisteredInput.value = data.dateRegistered;
        }

        if (headBirthdayWrap) {
            headBirthdayWrap.hidden = !canAssignZone;
        }
        if (headBirthdayEl) {
            headBirthdayEl.disabled = !canAssignZone;
            if (canAssignZone) {
                headBirthdayEl.value = data.birthday || '';
            }
        }

        if (headSexWrap) {
            headSexWrap.hidden = !canAssignZone;
        }
        if (headSexEl) {
            headSexEl.disabled = !canAssignZone;
            if (canAssignZone && SEX_VALUES.includes(data.sex)) {
                headSexEl.value = data.sex;
            } else if (canAssignZone) {
                headSexEl.selectedIndex = 0;
            }
        }

        if (headCivilStatusWrap) {
            headCivilStatusWrap.hidden = !canAssignZone;
        }
        if (headCivilStatusEl) {
            headCivilStatusEl.disabled = !canAssignZone;
            if (canAssignZone && CIVIL_STATUS_VALUES.includes(data.civilStatus)) {
                headCivilStatusEl.value = data.civilStatus;
            } else if (canAssignZone) {
                headCivilStatusEl.selectedIndex = 0;
            }
        }

        if (consentWrap && consentInput) {
            consentWrap.hidden = !data.isTemp;
            if (!data.isTemp) {
                consentInput.checked = false;
            }
        }

        if (noteEl) {
            if (data.isTemp && isExistingPendingPlot && data.source === 'gps') {
                noteEl.textContent = 'Location from device GPS. Confirm consent to save coordinates for this household.';
            } else if (data.isTemp && isExistingPendingPlot) {
                noteEl.textContent = 'Confirm consent to save coordinates for this household.';
            } else if (data.isTemp && data.source === 'gps') {
                noteEl.textContent = 'Location from device GPS. Enter the new household and household-head names, then confirm to register and plot.';
            } else if (data.isTemp) {
                noteEl.textContent = 'Enter the new household and household-head names, then confirm to register and plot.';
            } else {
                noteEl.textContent = 'Mapped household coordinates. Use View Household to open the Profiling record.';
            }
        }

        if (viewHhLink) {
            const canView = !data.isTemp && data.householdNo && data.householdNo !== 'HH-NEW';
            viewHhLink.hidden = !canView;
            if (canView) {
                viewHhLink.href = `${VIEW_HH_BASE}${encodeURIComponent(data.householdKey || data.householdNo)}`;
                viewHhLink.setAttribute('aria-label', `View household ${data.householdNo}`);
            } else {
                viewHhLink.removeAttribute('aria-label');
            }
        }

        if (confirmBtn) {
            confirmBtn.hidden = !data.isTemp;
        }
    }

    function refreshMarkerSelection() {
        markerById.forEach((marker, id) => {
            const data = marker.__lmlData;
            const name = markerAccessibleName(data);
            marker.setIcon(createMarkerIcon(data, id === selectedId, name));
            applyMarkerAccessibleName(marker, name);
        });

        if (tempMarker) {
            const data = tempMarker.__lmlData;
            const name = markerAccessibleName(data);
            tempMarker.setIcon(createMarkerIcon(data, data.id === selectedId, name));
            applyMarkerAccessibleName(tempMarker, name);
        }

        groupMarkers.forEach((marker) => {
            const members = marker.__lmlMembers || [];
            const representative = members[0];
            if (!representative) {
                return;
            }
            const count = members.length;
            const label = groupAriaLabel(count);
            const selected = members.some((member) => member.id === selectedId);
            marker.setIcon(createMarkerIcon(representative, selected, label, count));
            applyMarkerAccessibleName(marker, label);
        });
    }

    function openGroupSelector(members, triggerEl = null) {
        panelReturnFocusEl = triggerEl
            || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        selectedId = null;
        setPanelMode('selector');
        refreshMarkerSelection();

        if (noteEl) {
            noteEl.textContent = 'Multiple households at this location. Select one to view details.';
        }

        if (groupList) {
            groupList.replaceChildren();
            members.forEach((member) => {
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'lml-spot-map__group-item lml-focus-ring';
                button.setAttribute('data-household-no', member.householdNo);
                button.setAttribute('aria-label', `Select household ${member.householdNo}`);

                const numberEl = document.createElement('span');
                numberEl.className = 'lml-spot-map__group-item-no';
                numberEl.textContent = member.householdNo;

                const metaEl = document.createElement('span');
                metaEl.className = 'lml-spot-map__group-item-meta';
                const head = member.houseHead && member.houseHead !== '—'
                    ? member.houseHead
                    : 'Head not recorded';
                const zone = member.zoneLabel || zoneLabel(member.zone);
                metaEl.textContent = zone && zone !== '—' ? `${head} · ${zone}` : head;

                button.append(numberEl, metaEl);
                button.addEventListener('click', () => {
                    openPanel(member, button);
                });
                item.appendChild(button);
                groupList.appendChild(item);
            });
        }

        panel.hidden = false;

        if (closeBtn) {
            closeBtn.focus();
        }

        requestAnimationFrame(() => map.invalidateSize());
    }

    function syncVisualGroups() {
        const items = [];
        markerById.forEach((marker) => {
            const data = marker.__lmlData;
            if (!data || data.isTemp) {
                return;
            }
            items.push(data);
        });

        const groups = buildOverlapGroups(items, {
            getLayerPoint: (lat, lng) => map.latLngToLayerPoint(L.latLng(lat, lng)),
        });
        const plan = planOverlapVisuals(groups);
        const layers = applyOverlapLayerPlan(plan, {
            householdLayers: new Set(markerById.keys()),
            groupLayers: new Set(groupMarkers.keys()),
        });

        const stackedByKey = new Map(plan.stacked.map((group) => [group.key, group]));

        groupMarkers.forEach((marker, key) => {
            if (!layers.groupLayers.has(key)) {
                map.removeLayer(marker);
                groupMarkers.delete(key);
            }
        });

        layers.householdLayers.forEach((id) => {
            const marker = markerById.get(id);
            if (marker && !map.hasLayer(marker)) {
                marker.addTo(map);
            }
        });

        plan.stacked.forEach((group) => {
            group.memberIds.forEach((id) => {
                const marker = markerById.get(id);
                if (marker && map.hasLayer(marker)) {
                    map.removeLayer(marker);
                }
            });

            const members = group.memberIds
                .map((id) => markerById.get(id)?.__lmlData)
                .filter(Boolean);
            const representative = members[0];
            if (!representative) {
                return;
            }

            const count = group.count;
            const label = groupAriaLabel(count);
            const selected = members.some((member) => member.id === selectedId);
            let groupMarker = groupMarkers.get(group.key);

            if (!groupMarker) {
                groupMarker = L.marker([group.lat, group.lng], {
                    icon: createMarkerIcon(representative, selected, label, count),
                    keyboard: true,
                    title: label,
                    alt: label,
                });
                groupMarker.on('add', () => {
                    applyMarkerAccessibleName(
                        groupMarker,
                        groupAriaLabel(groupMarker.__lmlMembers?.length || count),
                    );
                });
                groupMarker.on('click', (event) => {
                    L.DomEvent.stopPropagation(event);
                    if (placing) {
                        setPlacing(false);
                    }
                    const currentMembers = groupMarker.__lmlMembers || [];
                    openGroupSelector(currentMembers, groupMarker.getElement());
                });
                groupMarker.addTo(map);
                groupMarkers.set(group.key, groupMarker);
            } else {
                groupMarker.setLatLng([group.lat, group.lng]);
                groupMarker.setIcon(createMarkerIcon(representative, selected, label, count));
                applyMarkerAccessibleName(groupMarker, label);
            }

            groupMarker.__lmlMembers = members;
            groupMarker.__lmlGroup = stackedByKey.get(group.key);
        });
    }

    function openPanel(data, triggerEl = null) {
        panelReturnFocusEl = triggerEl
            || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        selectedId = data.id;
        fillPanel(data);
        clearAllFieldErrors();
        panel.hidden = false;
        refreshMarkerSelection();

        if (closeBtn) {
            closeBtn.focus();
        }

        requestAnimationFrame(() => map.invalidateSize());
    }

    function closePanel({ removeTemp = false } = {}) {
        selectedId = null;
        setPanelMode('details');
        if (groupList) {
            groupList.replaceChildren();
        }
        panel.hidden = true;
        refreshMarkerSelection();

        if (removeTemp && tempMarker) {
            map.removeLayer(tempMarker);
            tempMarker = null;
        }

        const returnEl = panelReturnFocusEl;
        panelReturnFocusEl = null;
        if (returnEl && typeof returnEl.focus === 'function' && document.contains(returnEl)) {
            returnEl.focus();
        }

        requestAnimationFrame(() => map.invalidateSize());
    }

    function attachMarker(data, { temporary = false } = {}) {
        const accessibleName = markerAccessibleName(data);
        const marker = L.marker([data.lat, data.lng], {
            icon: createMarkerIcon(data, false, accessibleName),
            keyboard: true,
            title: accessibleName,
            alt: accessibleName,
        });

        marker.__lmlData = data;
        marker.on('add', () => {
            applyMarkerAccessibleName(marker, accessibleName);
        });
        marker.on('click', (event) => {
            L.DomEvent.stopPropagation(event);
            if (placing && !temporary) {
                setPlacing(false);
            }
            openPanel(marker.__lmlData, marker.getElement());
        });

        marker.addTo(map);
        applyMarkerAccessibleName(marker, accessibleName);

        if (temporary) {
            tempMarker = marker;
        } else {
            markerById.set(data.id, marker);
        }

        return marker;
    }

    function placeTemporaryMarker(lat, lng, { accuracy = null, source = 'manual' } = {}) {
        if (tempMarker) {
            map.removeLayer(tempMarker);
            tempMarker = null;
        }

        const pending = selectedPendingHousehold;
        const tempData = pending
            ? {
                id: createTempHouseholdId(),
                householdNo: pending.householdNo,
                houseHead: pending.houseHead || '—',
                address: 'Brgy. La Medalla, Iriga City (demo)',
                zone: pending.zone,
                zoneLabel: pending.zoneLabel || zoneLabel(pending.zone),
                headFirstName: pending.headFirstName || '',
                headMiddleName: pending.headMiddleName || '',
                headLastName: pending.headLastName || '',
                birthday: '',
                sex: '',
                civilStatus: '',
                householdType: pending.householdType || '',
                dateRegistered: '',
                members: pending.members != null ? pending.members : '—',
                lat,
                lng,
                accuracy,
                status: 'pending',
                isTemp: true,
                plotMode: 'existing',
                source,
            }
            : {
                id: createTempHouseholdId(),
                householdNo: 'HH-NEW',
                houseHead: '—',
                address: 'Brgy. La Medalla, Iriga City (demo)',
                zone: null,
                zoneLabel: '',
                headFirstName: '',
                headMiddleName: '',
                headLastName: '',
                birthday: '',
                sex: '',
                civilStatus: '',
                householdType: '',
                dateRegistered: dateRegisteredInput?.value || '',
                members: '—',
                lat,
                lng,
                accuracy,
                status: 'new',
                isTemp: true,
                plotMode: 'new',
                source,
            };

        attachMarker(tempData, { temporary: true });
        return tempData;
    }

    function enterManualFallback(message, overlayMode = 'error') {
        setLocating(false);
        setPlacing(true, message, overlayMode);
        focusMapCanvas();
    }

    /**
     * Outside-scope GPS: announce the restriction via setPlacing, then swap to the
     * standard placement cue after 2.8s so demos can continue without re-tapping Plot.
     */
    function enterOutsideScopeFallback(requestId) {
        setLocating(false);
        setPlacing(true, OUTSIDE_SCOPE_MESSAGE);
        focusMapCanvas();

        window.setTimeout(() => {
            if (requestId !== locateRequestId || !placing || locating) {
                return;
            }
            showOverlay('Click on the map to plot a location.', {
                mode: 'info',
                icon: 'bi-geo-alt-fill',
            });
        }, 2800);
    }

    function handleGpsSuccess(position, requestId) {
        if (requestId !== locateRequestId) {
            return;
        }

        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        const accuracy = typeof position.coords.accuracy === 'number'
            ? position.coords.accuracy
            : null;

        // UI-only radius guard — no temp marker / flyTo / panel when outside scope.
        const plan = gpsPlotPlan({
            gpsReceived: true,
            inScope: isWithinDemoScope(lat, lng),
        });
        if (!plan.flyTo) {
            enterOutsideScopeFallback(requestId);
            return;
        }

        setLocating(false);
        placing = false;
        root.classList.remove('is-placing');
        plotBtn.setAttribute('aria-pressed', 'false');

        showOverlay('Location found. Review the household details.', {
            mode: 'success',
            icon: 'bi-check-circle-fill',
        });

        const tempData = placeTemporaryMarker(lat, lng, { accuracy, source: 'gps' });
        map.flyTo([lat, lng], plan.zoom ?? GPS_ZOOM, { animate: true, duration: 0.85 });

        window.setTimeout(() => {
            if (requestId !== locateRequestId) {
                return;
            }
            hideOverlay();
            openPanel(tempData, plotBtn);
            map.invalidateSize();
        }, 700);
    }

    function handleGpsError(error, requestId) {
        if (requestId !== locateRequestId) {
            return;
        }

        enterManualFallback(geolocationErrorMessage(error));
    }

    function startGpsPlot() {
        closePanel();
        locateRequestId += 1;
        const requestId = locateRequestId;

        setPlacing(false);

        const plan = gpsPlotPlan({ offline: isClientOffline() });
        if (!plan.requestGps) {
            enterManualFallback(plan.overlay, plan.overlayMode);
            return;
        }

        setLocating(true);
        showOverlay(plan.overlay, {
            mode: plan.overlayMode,
            icon: 'bi-geo-alt-fill',
        });

        if (!navigator.geolocation) {
            enterManualFallback(
                'Location access is unavailable. Please enable GPS or manually select a location on the map.',
            );
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => handleGpsSuccess(position, requestId),
            (error) => handleGpsError(error, requestId),
            GEO_OPTIONS,
        );
    }

    function applyStats(stats) {
        if (!stats || typeof stats !== 'object') {
            return;
        }

        const totalEl = root.querySelector('[data-stat="total"]');
        const plottedEl = root.querySelector('[data-stat="plotted"]');
        const pendingEl = root.querySelector('[data-stat="pending"]');

        if (totalEl && stats.total != null) {
            totalEl.textContent = String(stats.total);
        }
        if (plottedEl && stats.plotted != null) {
            plottedEl.textContent = String(stats.plotted);
        }
        if (pendingEl && stats.pending != null) {
            pendingEl.textContent = String(stats.pending);
        }
    }

    function normalizeServerMarker(marker) {
        const householdNo = String(marker.householdNo || marker.household_no || '');
        const zone = normalizeZone(marker.zone);

        return {
            id: String(marker.id || `hh-${householdNo}`),
            householdNo,
            householdKey: String(marker.householdKey || ''),
            houseHead: marker.houseHead || marker.house_head || '—',
            householdType: marker.householdType || marker.household_type || '',
            headFirstName: marker.headFirstName || marker.head_first_name || '',
            headMiddleName: marker.headMiddleName ?? marker.head_middle_name ?? '',
            headLastName: marker.headLastName || marker.head_last_name || '',
            zone,
            zoneLabel: marker.zoneLabel || (zone ? ZONE_LABELS[zone] : ''),
            members: marker.members != null ? marker.members : 0,
            lat: Number(marker.lat),
            lng: Number(marker.lng),
            status: 'plotted',
            isTemp: false,
        };
    }

    function upsertMappedMarker(serverMarker) {
        const data = normalizeServerMarker(serverMarker);
        if (!data.householdNo || Number.isNaN(data.lat) || Number.isNaN(data.lng)) {
            return null;
        }

        const existing = markerById.get(data.id);
        if (existing) {
            existing.setLatLng([data.lat, data.lng]);
            existing.__lmlData = data;
            const name = markerAccessibleName(data);
            existing.setIcon(createMarkerIcon(data, data.id === selectedId, name));
            applyMarkerAccessibleName(existing, name);
            syncVisualGroups();
            return existing;
        }

        const created = attachMarker(data);
        syncVisualGroups();
        return created;
    }

    parseServerMarkers(root).forEach((household) => {
        upsertMappedMarker(household);
    });
    syncVisualGroups();

    map.on('zoomend', () => {
        syncVisualGroups();
    });

    plotBtn.addEventListener('click', () => {
        if (locating) {
            return;
        }

        if (placing) {
            setPlacing(false);
            return;
        }

        clearPendingSelection();
        startGpsPlot();
    });

    plotExistingBtn?.addEventListener('click', () => {
        if (locating || !selectedPendingHousehold) {
            return;
        }

        if (placing) {
            setPlacing(false);
            return;
        }

        startGpsPlot();
    });

    map.on('click', (event) => {
        if (!placing || locating) {
            return;
        }

        const { lat, lng } = event.latlng;
        const tempData = placeTemporaryMarker(lat, lng, { source: 'manual' });
        setPlacing(false);
        openPanel(tempData, selectedPendingHousehold ? plotExistingBtn : plotBtn);
    });

    renderPendingPicker();

    if (continuePlotHousehold !== '') {
        const matched = selectPendingHousehold(continuePlotHousehold, { startPlot: true });
        if (!matched && continuePlotMode) {
            clearPendingSelection();
            startGpsPlot();
        }
    } else if (continuePlotMode) {
        clearPendingSelection();
        startGpsPlot();
    }

    function handleDismiss() {
        locateRequestId += 1;
        setLocating(false);
        setPlacing(false);
        hideOverlay();
        clearAllFieldErrors();
        if (consentInput) {
            consentInput.checked = false;
        }
        // Cancel / Close / Escape must never leave an orphan temporary marker.
        closePanel({ removeTemp: true });
    }

    closeBtn?.addEventListener('click', handleDismiss);
    cancelBtn?.addEventListener('click', handleDismiss);

    zoneSelect?.addEventListener('change', () => {
        if (normalizeZone(zoneSelect.value)) {
            clearFieldError('zone');
        }

        if (!isEditingActiveTempPlot()) {
            return;
        }

        const zone = normalizeZone(zoneSelect.value);
        persistHeadNameDraft();
        const data = {
            ...tempMarker.__lmlData,
            zone,
            zoneLabel: zone ? ZONE_LABELS[zone] : '',
        };
        tempMarker.__lmlData = data;
        refreshMarkerSelection();
        fillPanel(data);
    });

    headFirstEl?.addEventListener('input', () => {
        if ((headFirstEl.value || '').trim()) {
            clearFieldError('firstName');
        }
        persistHeadNameDraft();
    });

    headLastEl?.addEventListener('input', () => {
        if ((headLastEl.value || '').trim()) {
            clearFieldError('lastName');
        }
        persistHeadNameDraft();
    });

    headMiddleEl?.addEventListener('input', persistHeadNameDraft);

    headBirthdayEl?.addEventListener('change', () => {
        if ((headBirthdayEl.value || '').trim()) {
            clearFieldError('birthday');
        }
        persistHeadNameDraft();
    });

    headSexEl?.addEventListener('change', () => {
        if (SEX_VALUES.includes(headSexEl.value)) {
            clearFieldError('sex');
        }
        persistHeadNameDraft();
    });

    headCivilStatusEl?.addEventListener('change', () => {
        if (CIVIL_STATUS_VALUES.includes(headCivilStatusEl.value)) {
            clearFieldError('civilStatus');
        }
        persistHeadNameDraft();
    });

    hhTypeSelect?.addEventListener('change', () => {
        if (plotFormHouseholdType(canonicalHouseholdType(hhTypeSelect.value))) {
            clearFieldError('householdType');
        }
        persistHeadNameDraft();
    });

    householdNoInput?.addEventListener('input', () => {
        if (/^[0-9]{3}$/.test((householdNoInput.value || '').trim())) {
            clearFieldError('householdNo');
        }
    });

    consentInput?.addEventListener('change', () => {
        if (consentInput.checked) {
            clearFieldError('consent');
        }
    });

    confirmBtn?.addEventListener('click', async () => {
        /*
         | DB18-D: one request saves coordinates and establishes real-household handoff.
         */
        if (!isEditingActiveTempPlot()) {
            return;
        }

        if (confirmBtn.disabled || confirmBtn.getAttribute('aria-busy') === 'true') {
            return;
        }

        const validation = validatePlotForm();
        if (!validation.valid) {
            const firstInvalid = validation.firstInvalid;
            if (firstInvalid) {
                firstInvalid.focus?.();
                firstInvalid.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            }
            return;
        }

        const confirmedMarker = tempMarker;
        const isExistingPendingPlot = confirmedMarker.__lmlData?.plotMode === 'existing';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const panelNote = root.querySelector('[data-spot-map-note]');

        if (isExistingPendingPlot) {
            const plotUrl = root.getAttribute('data-plot-url') || '/spot-mapping/plot';
            const existingPayload = {
                household_no: validation.values.householdNo,
                lat: confirmedMarker.__lmlData?.lat,
                lng: confirmedMarker.__lmlData?.lng,
                consent: true,
            };

            confirmBtn.disabled = true;
            confirmBtn.setAttribute('aria-busy', 'true');
            if (panelNote) {
                panelNote.textContent = 'Saving household coordinates…';
                panelNote.hidden = false;
            }

            if (isClientOffline()) {
                const message = 'A connection is required to plot an existing pending household.';
                if (panelNote) {
                    panelNote.textContent = message;
                    panelNote.hidden = false;
                }
                showOverlay(message, {
                    mode: 'error',
                    icon: 'bi-exclamation-triangle-fill',
                });
                confirmBtn.disabled = false;
                confirmBtn.removeAttribute('aria-busy');
                return;
            }

            try {
                const response = await fetch(plotUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(existingPayload),
                });

                let body = null;
                try {
                    body = await response.json();
                } catch {
                    body = null;
                }

                if (!response.ok) {
                    const message = typeof body?.message === 'string' && body.message.trim()
                        ? body.message.trim()
                        : GENERIC_PLOT_FAILURE;
                    throw new Error(message);
                }

                const assignedHouseholdNo = typeof body?.household_no === 'string'
                    ? body.household_no.trim()
                    : validation.values.householdNo;
                const markerPayload = body?.marker
                    ? normalizeServerMarker(body.marker)
                    : normalizeServerMarker({
                        householdNo: assignedHouseholdNo,
                        houseHead: confirmedMarker.__lmlData?.houseHead,
                        householdType: confirmedMarker.__lmlData?.householdType,
                        headFirstName: confirmedMarker.__lmlData?.headFirstName,
                        headMiddleName: confirmedMarker.__lmlData?.headMiddleName,
                        headLastName: confirmedMarker.__lmlData?.headLastName,
                        zone: confirmedMarker.__lmlData?.zone,
                        zoneLabel: confirmedMarker.__lmlData?.zoneLabel,
                        members: confirmedMarker.__lmlData?.members,
                        lat: confirmedMarker.__lmlData?.lat,
                        lng: confirmedMarker.__lmlData?.lng,
                    });

                if (tempMarker) {
                    map.removeLayer(tempMarker);
                    tempMarker = null;
                }

                setPlacing(false);
                setLocating(false);
                upsertMappedMarker(markerPayload);
                if (body?.stats) {
                    applyStats(body.stats);
                }
                removePendingCandidate(assignedHouseholdNo);
                clearPendingSelection({ render: false });
                syncPlotExistingButton();
                clearAllFieldErrors();
                if (consentInput) {
                    consentInput.checked = false;
                }

                showOverlay('Household coordinates saved.', {
                    mode: 'success',
                    icon: 'bi-check-circle-fill',
                });
                openPanel(markerPayload, plotExistingBtn || plotBtn);
                confirmBtn.disabled = false;
                confirmBtn.removeAttribute('aria-busy');
            } catch (error) {
                const message = error instanceof Error && error.message
                    ? error.message
                    : GENERIC_PLOT_FAILURE;

                if (panelNote) {
                    panelNote.textContent = message;
                    panelNote.hidden = false;
                }

                showOverlay(message, {
                    mode: 'error',
                    icon: 'bi-exclamation-triangle-fill',
                });

                confirmBtn.disabled = false;
                confirmBtn.removeAttribute('aria-busy');
            }
            return;
        }

        const selectedZone = validation.values.zone;
        const selectedType = validation.values.householdType;
        const plotPayload = {
            first_name: validation.values.firstName,
            middle_name: validation.values.middleName,
            last_name: validation.values.lastName,
            birthday: validation.values.birthday,
            sex: validation.values.sex,
            civil_status: validation.values.civilStatus,
            household_type: selectedType,
            zone: selectedZone,
            date_registered: validation.values.dateRegistered,
            household_no: validation.values.householdNo,
            lat: confirmedMarker.__lmlData?.lat,
            lng: confirmedMarker.__lmlData?.lng,
            consent: true,
            client_marker_id: String(confirmedMarker.__lmlData?.id || ''),
        };

        const createUrl = root.getAttribute('data-plot-new-url') || '/spot-mapping/plot-new';
        const defaultHandoffError = 'Unable to continue because the household plot session is invalid or expired. Please plot the household again.';

        confirmBtn.disabled = true;
        confirmBtn.setAttribute('aria-busy', 'true');
        if (panelNote) {
            panelNote.textContent = 'Registering household…';
            panelNote.hidden = false;
        }

        const finishLocalQueue = async () => {
            const queued = await queuePlotNewHousehold(plotPayload, {
                window,
                root: document.querySelector('[data-lml-offline-root]'),
            });
            if (!queued.ok) {
                throw new Error(
                    'This update could not be stored on this device. Keep this page open and try again when a connection is available.',
                );
            }
            await persistPlotHouseholdReadModel(queued.record.actor_id, queued.record.payload);
            if (panelNote) {
                panelNote.textContent = 'Stored on this device. Waiting to sync.';
                panelNote.hidden = false;
            }
            confirmBtn.disabled = true;
            confirmBtn.setAttribute('data-offline-queued', '1');
            confirmBtn.removeAttribute('aria-busy');
        };

        if (isClientOffline()) {
            try {
                await finishLocalQueue();
            } catch (error) {
                const message = error instanceof Error && error.message
                    ? error.message
                    : GENERIC_PLOT_FAILURE;
                if (panelNote) {
                    panelNote.textContent = message;
                    panelNote.hidden = false;
                }
                showOverlay(message, {
                    mode: 'error',
                    icon: 'bi-exclamation-triangle-fill',
                });
                confirmBtn.disabled = false;
                confirmBtn.removeAttribute('aria-busy');
            }
            return;
        }

        try {
            const response = await fetch(createUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(plotPayload),
            });

            let body = null;
            try {
                body = await response.json();
            } catch {
                body = null;
            }

            if (!response.ok) {
                const message = typeof body?.message === 'string' && body.message.trim()
                    ? body.message.trim()
                    : GENERIC_PLOT_FAILURE;
                throw new Error(message);
            }

            const redirectUrl = typeof body?.redirect_url === 'string' && body.redirect_url.trim()
                ? body.redirect_url.trim()
                : null;
            const handoffToken = typeof body?.handoff_token === 'string' ? body.handoff_token.trim() : '';

            if (!redirectUrl && !handoffToken) {
                throw new Error(defaultHandoffError);
            }

            const assignedHouseholdNo = typeof body?.household_no === 'string'
                ? body.household_no.trim()
                : '';

            try {
                sessionStorage.setItem(
                    'lml_pending_water_supply_household',
                    JSON.stringify({
                        householdNo: assignedHouseholdNo,
                        plottedAt: new Date().toISOString(),
                    })
                );
            } catch {
                // Private browsing may block storage; handoff token carries authority.
            }

            // Navigate immediately into the existing Environmental Health wizard.
            // Do not refresh Spot Mapping UI first — that leaves users on the map panel.
            if (redirectUrl) {
                window.location.replace(redirectUrl);
                return;
            }

            const waterSupplyUrl = new URL(
                '/environmental-health/household-water-supply',
                window.location.origin
            );
            waterSupplyUrl.searchParams.set('handoff', handoffToken);
            window.location.replace(waterSupplyUrl.toString());
            return;
        } catch (error) {
            if (isNetworkFailure(error)) {
                try {
                    await finishLocalQueue();
                    return;
                } catch {
                    // Storage failed; show the existing failure UI below.
                }
            }

            const message = error instanceof Error && error.message
                ? error.message
                : GENERIC_PLOT_FAILURE;

            if (panelNote) {
                panelNote.textContent = message;
                panelNote.hidden = false;
            }

            showOverlay(message, {
                mode: 'error',
                icon: 'bi-exclamation-triangle-fill',
            });

            confirmBtn.disabled = false;
            confirmBtn.removeAttribute('aria-busy');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (!panel.hidden || placing || locating) {
            handleDismiss();
        }
    });

    const mapWrap = root.querySelector('.lml-spot-map__map-wrap');
    if (mapWrap) {
        initSpotMappingExport(root, {
            map,
            markerById,
            getTempMarker: () => tempMarker,
            dismissPlotUi: handleDismiss,
            mapWrap,
            showMessage: (message, options = {}) => showOverlay(message, options),
            hideMessage: hideOverlay,
            onRestoreComplete: () => syncVisualGroups(),
        });
    }

    function readDomStats() {
        return {
            total: Number.parseInt(root.querySelector('[data-stat="total"]')?.textContent, 10) || 0,
            plotted: Number.parseInt(root.querySelector('[data-stat="plotted"]')?.textContent, 10) || 0,
            pending: Number.parseInt(root.querySelector('[data-stat="pending"]')?.textContent, 10) || 0,
        };
    }

    function resetQueuedPlotUi() {
        if (confirmBtn) {
            confirmBtn.removeAttribute('data-offline-queued');
            confirmBtn.disabled = false;
            confirmBtn.removeAttribute('aria-busy');
        }
        if (plotBtn) {
            plotBtn.disabled = false;
            plotBtn.removeAttribute('aria-busy');
        }
        if (noteEl) {
            noteEl.textContent = '';
        }
    }

    function handleOfflineSyncSuccess(event) {
        const detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
        const result = reconcileQueuedSpotPlotAfterSync(
            {
                tempMarker,
                isQueued: confirmBtn?.getAttribute?.('data-offline-queued') === '1',
                householdNo: householdNoInput?.value,
                formValues: {
                    headFirstName: headFirstEl?.value,
                    headMiddleName: headMiddleEl?.value,
                    headLastName: headLastEl?.value,
                    zone: zoneSelect?.value || tempMarker?.__lmlData?.zone,
                    householdType: hhTypeSelect?.value || tempMarker?.__lmlData?.householdType,
                },
                markerById,
                upsertMappedMarker,
                closePanel,
                applyStats,
                readStats: readDomStats,
                resetQueuedUi: resetQueuedPlotUi,
            },
            detail,
        );
        const ehUrl = result?.promoted ? environmentalHealthUrlForHousehold(plotSyncHouseholdNo(detail) || result?.householdNo) : '';
        if (ehUrl) {
            window.location.assign(ehUrl);
        }
    }

    window.addEventListener('lmlinga:sync-success', handleOfflineSyncSuccess);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSpotMapping);
} else {
    initSpotMapping();
}
