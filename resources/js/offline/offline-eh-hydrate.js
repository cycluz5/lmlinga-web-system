/**
 * Cache Environmental Health wizard pages for a locally plotted household
 * and continue Spot Mapping → EH Steps 1–4 → Household Profiling offline.
 *
 * Does not mint server PKs or Laravel handoff tokens. Identity is household_no.
 */

import {
    documentHasSwCacheMarker,
    htmlCacheNameForActor,
    navigationCacheUrl,
    sanitizeCachedHtml,
} from './offline-sw-policy.js';
import { isClientOffline, OPERATION_TYPES } from './offline-forms.js';
import {
    cacheHydratedHouseholdPages,
} from './offline-hp-hydrate.js';
import {
    getHouseholdSnapshot,
    getMeta,
    membersForHousehold,
    persistPlotHouseholdReadModel,
    householdSnapshotFromPlot,
    putHouseholdSnapshot,
    putMeta,
    promoteLocalMemberIdentity,
} from './offline-hp-store.js';
import { rememberLocalMember, reconcileLocalMember } from './offline-identity-map.js';
import { applyHouseholdIdentityToDependents } from './offline-queue.js';
import { initHouseholdWaterSupply } from '../pages/household-water-supply.js';
import { legacyPath, publicPath, urlKeyFor } from './offline-url-keys.js';

const PENDING_KEY = 'lml_pending_water_supply_household';
const HOUSEHOLD_NO = '(?:HH-[0-9]+|[0-9]{3}|h[a-z2-7]{12,})';
export const EH_SHELL_PLACEHOLDER = 'LML-EH';
export const EH_SHELL_STEPS = [1, 2, 3, 4];

let ehShellWarmupInFlight = null;

export function resetEhShellWarmupForTests() {
    ehShellWarmupInFlight = null;
}

export function ehStep1Url(householdNo) {
    const no = String(householdNo || '').trim();
    return no ? publicPath(`/environmental-health/household-water-supply?household=${encodeURIComponent(no)}`) : '';
}

export function ehStepUrl(householdNo, step) {
    const no = String(householdNo || '').trim();
    const n = Number(step);
    if (!no) {
        return '';
    }
    if (n <= 1) {
        return ehStep1Url(no);
    }
    if (n === 2 || n === 3 || n === 4) {
        return publicPath(`/environmental-health/household-water-supply/${no}/step-${n}`);
    }
    return publicPath(`/household-profiling/${no}`);
}

export function nextEhUrl(householdNo, currentStep) {
    const step = Number(currentStep);
    if (step === 1) {
        return ehStepUrl(householdNo, 2);
    }
    if (step === 2) {
        return ehStepUrl(householdNo, 3);
    }
    if (step === 3) {
        return ehStepUrl(householdNo, 4);
    }
    return publicPath(`/household-profiling/${String(householdNo || '').trim()}`);
}

export function parseEnvironmentalHealthPath(pathname, search = '') {
    const internal = legacyPath(`${String(pathname || '').replace(/\/+$/, '') || '/'}${search ? `${String(search).startsWith('?') ? '' : '?'}${search}` : ''}`);
    const cut = internal.indexOf('?');
    const path = cut === -1 ? internal : internal.slice(0, cut);
    search = cut === -1 ? '' : internal.slice(cut);
    if (path === '/environmental-health/household-water-supply') {
        const params = new URLSearchParams(String(search || '').replace(/^\?/, ''));
        const householdNo = String(params.get('household') || '').trim();
        if (!householdNo) {
            return null;
        }
        return { step: 1, householdNo };
    }
    const match = path.match(new RegExp(`^/environmental-health/household-water-supply/(${HOUSEHOLD_NO})/step-([234])$`, 'i'));
    if (!match) {
        return null;
    }
    return { step: Number(match[2]), householdNo: match[1] };
}

export { householdSnapshotFromPlot } from './offline-hp-store.js';

export function applyEhPayloadToHousehold(household, payload = {}) {
    const step = Number(payload._eh_step || 0);
    const current = household && typeof household === 'object' ? household : {};
    const completed = Math.max(Number(current.eh?.completed_step || 0), Number.isInteger(step) ? step : 0);
    const next = {
        ...current,
        eh: { ...(current.eh || {}), completed_step: completed },
        water: { ...(current.water || {}) },
        sanitation: { ...(current.sanitation || {}) },
    };

    if (step === 1) {
        next.water = {
            ...next.water,
            status: payload.water_supply_status || next.water.status || '',
            specify: payload.specify_water_source || next.water.specify || '',
            location: payload.water_source_location || next.water.location || '',
            availability: payload.water_availability || next.water.availability || '',
            level: waterLevelLabel(payload.water_supply_status || next.water.status),
        };
    }
    if (step === 2) {
        next.water = {
            ...next.water,
            microbiological_test_date: payload.microbiological_test_date || '',
            microbiological_result: payload.microbiological_result || '',
            physicochemical_test_date: payload.physicochemical_test_date || '',
            physicochemical_result: payload.physicochemical_result || '',
        };
    }
    if (step === 3) {
        next.sanitation = {
            ...next.sanitation,
            toilet_type: payload.toilet_type || '',
            open_defecation_practiced: payload.open_defecation_practiced || '',
            shared_toilet: payload.shared_toilet || '',
            sewage_disposal_method: payload.sewage_disposal_method || '',
            facility: toiletLabel(payload.toilet_type),
        };
    }
    if (step === 4) {
        const practices = Array.isArray(payload.solid_waste_practices)
            ? payload.solid_waste_practices
            : [];
        next.sanitation = {
            ...next.sanitation,
            solid_waste_practices: practices,
            status: practices.length ? 'Good practice' : next.sanitation.status || '',
        };
    }
    return next;
}

export function ehShellPath(step) {
    return `/offline/environmental-health-shell/${Number(step)}`;
}

export function isCanonicalEhShellHtml(html, step) {
    return looksLikeEhWizardUi(html, step)
        && hasMatchingHwsStep(html, step)
        && hasLoadableAppScript(html);
}

export function looksLikeEhWizardUi(html, step) {
    const text = String(html || '');
    if (
        !text.includes('lml-dashboard')
        || !text.includes('data-lml-hws')
        || !text.includes('lml-hws__form')
        || !text.includes('lml-hws__program-title')
        || !text.includes('Environmental Sanitation')
    ) {
        return false;
    }
    const n = Number(step);
    if (n === 1) {
        return text.includes('lml-hws__level-grid')
            && text.includes('lml-hws__level-card')
            && text.includes('name="water_supply_status"')
            && text.includes('data-hws-level')
            && text.includes('data-hws-form')
            && text.includes('data-hws-specify')
            && text.includes('data-hws-specify-input')
            && text.includes('data-hws-location')
            && text.includes('data-hws-availability')
            && text.includes('data-hws-safe-water-badge')
            && text.includes('data-hws-next')
            && text.includes('Specify Water Source')
            && text.includes('Water Supply Status')
            && text.includes('Household Water Supply Information');
    }
    if (n === 2) {
        return text.includes('data-hws-step2-form')
            && text.includes('name="microbiological_test_date"')
            && text.includes('lml-hws__test-grid')
            && text.includes('Validation / Random Sampling / Testing');
    }
    if (n === 3) {
        return text.includes('data-hws-step3-form')
            && text.includes('name="toilet_type"')
            && text.includes('pour_flush_with_septic_tank')
            && text.includes('Basic Sanitation Facility');
    }
    if (n === 4) {
        return text.includes('data-hws-step4-form')
            && text.includes('solid_waste_practices[]')
            && text.includes('waste_segregation')
            && text.includes('Solid Waste Management');
    }
    return false;
}

export function isPlainEhFallbackHtml(html) {
    const text = String(html || '');
    if (!text) {
        return false;
    }
    const missingLayout = !text.includes('lml-dashboard') || !text.includes('lml-hws__program-title');
    const looksLikeOldFallback = text.includes('data-hws-level') && !text.includes('lml-hws__level-grid');
    return missingLayout && looksLikeOldFallback;
}

export async function hasCanonicalEhShells(actorId) {
    for (const step of EH_SHELL_STEPS) {
        const html = await getMeta(actorId, `shell:eh-step-${step}`);
        if (!isCanonicalEhShellHtml(html, step) || /\sdata-hws-bound=/i.test(String(html || ''))) {
            return false;
        }
    }
    return true;
}

export async function prepareEnvironmentalHealthShells(options = {}) {
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false, reason: 'actor' };
    }
    if (isClientOffline({
        window: options.window,
        navigator: options.navigator,
        root: options.root || doc,
    })) {
        return { ok: false, reason: 'offline' };
    }
    if (!options.force && await hasCanonicalEhShells(actorId)) {
        return { ok: true, cached: true };
    }
    if (ehShellWarmupInFlight) {
        return ehShellWarmupInFlight;
    }

    ehShellWarmupInFlight = (async () => {
        const fetchImpl = options.fetch || (typeof fetch === 'function' ? fetch : null);
        if (typeof fetchImpl !== 'function') {
            return { ok: false, reason: 'fetch' };
        }
        const base = readEhShellBase(doc);
        const stored = {};
        for (const step of EH_SHELL_STEPS) {
            const html = await fetchHtml(fetchImpl, `${base}/${step}`);
            if (!isCanonicalEhShellHtml(html, step)) {
                return { ok: false, reason: 'invalid-shell', step };
            }
            stored[step] = html;
        }
        for (const step of EH_SHELL_STEPS) {
            await putMeta(actorId, `shell:eh-step-${step}`, stored[step]);
        }
        await putMeta(actorId, 'shell:eh-household_no', EH_SHELL_PLACEHOLDER);
        return { ok: true, cached: false };
    })();

    try {
        return await ehShellWarmupInFlight;
    } finally {
        ehShellWarmupInFlight = null;
    }
}

export async function cacheEhWizardPages(actorId, household, options = {}) {
    const no = String(household?.household_no || '').trim();
    if (!no || !Number.isInteger(Number(actorId)) || Number(actorId) <= 0) {
        return false;
    }
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');
    const fromNo = options.shellHouseholdNo
        || (await getMeta(actorId, 'shell:eh-household_no'))
        || EH_SHELL_PLACEHOLDER;

    for (const step of EH_SHELL_STEPS) {
        const shell = options.shells?.[step] || (await getMeta(actorId, `shell:eh-step-${step}`));
        if (!looksLikeEhWizardUi(shell, step)) {
            return false;
        }
        const html = rewriteEhHouseholdHtml(shell, fromNo, household, {
            step,
            document: options.document,
        });
        if (!isCanonicalEhShellHtml(html, step) || isPlainEhFallbackHtml(html)) {
            return false;
        }
        await putEhHtml(cachesApi, actorId, origin, ehStepUrl(no, step), html);
    }
    return true;
}

export async function ensureEhNavFromSnapshot(href, options = {}) {
    const actorId = Number(options.actorId || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    let parsed;
    try {
        parsed = new URL(
            String(href || ''),
            options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost'),
        );
    } catch {
        return false;
    }
    const info = parseEnvironmentalHealthPath(parsed.pathname, parsed.search);
    if (!info) {
        return false;
    }
    const household = await getHouseholdSnapshot(actorId, info.householdNo);
    if (!household) {
        return false;
    }
    try {
        return await cacheEhWizardPages(actorId, household, options);
    } catch {
        return false;
    }
}

export async function handlePlotHouseholdQueued(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD) {
        return { ok: false };
    }
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const householdNo = String(payload.household_no || '').trim();
    if (!householdNo) {
        return { ok: false };
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false };
    }

    const snapshot = householdSnapshotFromPlot(payload);
    const persisted = await persistPlotHouseholdReadModel(actorId, payload);
    const members = await membersForHousehold(actorId, householdNo);
    const head = members.find((row) => String(row.relation || row.relationship || '').toLowerCase() === 'head')
        || persisted.member;
    if (head?.member_no) {
        snapshot.head_member_no = head.member_no;
        snapshot.member_count = members.length || 1;
        Object.assign(snapshot, persisted.household);
        try {
            await rememberLocalMember(head.member_no, {
                ...payload,
                household_no: householdNo,
                relation: 'Head',
            }, {
                actorId,
                caches: options.caches,
                origin: options.origin,
            });
        } catch {
            // Identity map is best-effort for View/Edit after sync.
        }
    }
    rememberPendingHousehold(householdNo, options.window);
    if (!options.shells && !(await hasCanonicalEhShells(actorId))) {
        await prepareEnvironmentalHealthShells(options);
    }
    const cached = await cacheEhWizardPages(actorId, snapshot, options);
    if (!cached) {
        return { ok: false, reason: 'eh-shell-missing', householdNo };
    }
    try {
        await cacheHydratedHouseholdPages(actorId, snapshot, members, options);
    } catch {
        // HP cache is best-effort; EH continuation must still proceed.
    }

    const path = ehStep1Url(householdNo);
    if (options.navigate === false) {
        return { ok: true, path, householdNo };
    }
    assignLocation(path, options);
    return { ok: true, path, householdNo };
}

export async function handleEnvironmentalStepQueued(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE) {
        return { ok: false };
    }
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const householdNo = String(
        payload.household_no || detail.parent_server?.household_no || '',
    ).trim();
    const step = Number(payload._eh_step || 0);
    if (!householdNo || !Number.isInteger(step) || step < 1) {
        return { ok: false };
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false };
    }

    const existing = await getHouseholdSnapshot(actorId, householdNo);
    const next = applyEhPayloadToHousehold(existing || { household_no: householdNo }, payload);
    next.household_no = householdNo;
    await putHouseholdSnapshot(actorId, next);
    try {
        if (!options.shells && !(await hasCanonicalEhShells(actorId))) {
            await prepareEnvironmentalHealthShells(options);
        }
        const cached = await cacheEhWizardPages(actorId, next, options);
        if (!cached && options.requireEhShell !== false) {
            // Snapshot already persisted; shell cache is best-effort for navigate:false tests.
            if (options.navigate !== false) {
                return { ok: false, reason: 'eh-shell-missing', householdNo, step };
            }
        }
    } catch {
        if (options.navigate !== false) {
            return { ok: false, reason: 'eh-shell-missing', householdNo, step };
        }
    }
    try {
        const members = await membersForHousehold(actorId, householdNo);
        await cacheHydratedHouseholdPages(actorId, next, members, options);
    } catch {
        // Ignore HP cache failures; EH next-step navigation still continues.
    }

    // Online EH Step 4 from Household Profiling create consumes return context
    // and redirects to household-profiling.view. Offline nextEhUrl already targets
    // that page; clear the remembered household when Step 4 completes.
    if (step === 4) {
        try {
            const remembered = String(await getMeta(actorId, 'eh:return_to_household') || '').trim();
            if (remembered && remembered === householdNo) {
                await putMeta(actorId, 'eh:return_to_household', '');
            }
        } catch {
            // Best-effort.
        }
    }

    const path = nextEhUrl(householdNo, step);
    if (options.navigate === false) {
        return { ok: true, path, householdNo, step };
    }
    assignLocation(path, options);
    return { ok: true, path, householdNo, step };
}

export async function handlePlotHouseholdSynced(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD) {
        return false;
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readActorId(options.root || doc) || 0);
    const householdNo = String(
        detail.identities?.household_no
        || detail.body?.household?.household_no
        || detail.payload?.household_no
        || '',
    ).trim();
    if (!householdNo || !Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    const existing = await getHouseholdSnapshot(actorId, householdNo);
    if (!existing) {
        return false;
    }
    const pk = Number(detail.identities?.household_pk || detail.body?.household?.id || 0);
    const serverMemberNo = String(
        detail.identities?.member_no
        || detail.body?.resident?.member_no
        || '',
    ).trim();
    const residentPk = Number(
        detail.identities?.resident_pk
        || detail.body?.resident?.id
        || 0,
    );
    const localHeadId = String(existing.head_member_no || '').trim();
    if (localHeadId && /^MB-L-/i.test(localHeadId) && /^MB-\d+$/i.test(serverMemberNo)) {
        try {
            await promoteLocalMemberIdentity(
                actorId,
                householdNo,
                localHeadId,
                serverMemberNo,
                Number.isInteger(residentPk) && residentPk > 0 ? residentPk : null,
            );
            await reconcileLocalMember(localHeadId, serverMemberNo, {
                actorId,
                caches: options.caches,
                origin: options.origin,
            });
        } catch {
            // Promotion is best-effort; bootstrap merge also drops orphan plot heads.
        }
    }
    const nextHousehold = {
        ...existing,
        household_id: Number.isInteger(pk) && pk > 0 ? pk : existing.household_id,
        head_member_no: /^MB-\d+$/i.test(serverMemberNo) ? serverMemberNo : existing.head_member_no,
        local: false,
        pending_sync: false,
    };
    await putHouseholdSnapshot(actorId, nextHousehold);
    if (Number.isInteger(pk) && pk > 0) {
        try {
            await applyHouseholdIdentityToDependents(actorId, householdNo, pk);
        } catch {
            // Dependent rebind is best-effort.
        }
    }
    try {
        const members = await membersForHousehold(actorId, householdNo);
        await cacheHydratedHouseholdPages(actorId, nextHousehold, members, options);
    } catch {
        // Recache is best-effort after identity promotion.
    }
    return true;
}

export async function rememberEhShellFromDocument(doc, options = {}) {
    const root = doc?.querySelector?.('[data-lml-hws]');
    if (!root || !root.querySelector?.('[data-hws-form]')) {
        return false;
    }
    const step = Number(root.getAttribute('data-hws-step') || 0);
    const householdNo = String(root.getAttribute('data-household-no') || '').trim();
    const actorId = Number(options.actorId || readActorId(doc) || 0);
    if (!Number.isInteger(step) || step < 1 || step > 4 || !Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    if (householdNo && householdNo !== EH_SHELL_PLACEHOLDER) {
        return false;
    }
    if (documentHasSwCacheMarker(doc)) {
        return false;
    }
    const html = sanitizeCachedHtml(`<!DOCTYPE html>${doc.documentElement.outerHTML}`);
    if (!isCanonicalEhShellHtml(html, step)) {
        return false;
    }
    await putMeta(actorId, `shell:eh-step-${step}`, html);
    await putMeta(actorId, 'shell:eh-household_no', EH_SHELL_PLACEHOLDER);
    return true;
}

export function fillEhFormFromSnapshot(root, household) {
    if (!root || !household) {
        return false;
    }
    const step = Number(root.getAttribute('data-hws-step') || 0);
    const water = household.water || {};
    const sanitation = household.sanitation || {};
    if (step === 1) {
        checkNamed(root, 'water_supply_status', water.status);
        setNamed(root, 'specify_water_source', water.specify);
        checkNamed(root, 'water_source_location', water.location);
        checkNamed(root, 'water_availability', water.availability);
    }
    if (step === 2) {
        setNamed(root, 'microbiological_test_date', water.microbiological_test_date);
        checkNamed(root, 'microbiological_result', water.microbiological_result);
        setNamed(root, 'physicochemical_test_date', water.physicochemical_test_date);
        checkNamed(root, 'physicochemical_result', water.physicochemical_result);
    }
    if (step === 3) {
        setNamed(root, 'toilet_type', sanitation.toilet_type);
        checkNamed(root, 'open_defecation_practiced', sanitation.open_defecation_practiced);
        checkNamed(root, 'shared_toilet', sanitation.shared_toilet);
        checkNamed(root, 'sewage_disposal_method', sanitation.sewage_disposal_method);
    }
    if (step === 4 && Array.isArray(sanitation.solid_waste_practices)) {
        sanitation.solid_waste_practices.forEach((value) => {
            const input = root.querySelector(`[name="solid_waste_practices[]"][value="${cssEscape(value)}"]`)
                || root.querySelector(`[name="solid_waste_practices"][value="${cssEscape(value)}"]`);
            if (input) {
                input.checked = true;
            }
        });
    }
    return true;
}

function readActorId(root) {
    const host =
        root?.closest?.('[data-lml-offline-root]')
        || (typeof root?.querySelector === 'function' ? root.querySelector('[data-offline-actor-id]') : null)
        || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);
    const id = Number.parseInt(String(host?.getAttribute?.('data-offline-actor-id') || ''), 10);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function waterLevelLabel(status) {
    if (status === 'level_i') {
        return 'Level I';
    }
    if (status === 'level_ii') {
        return 'Level II';
    }
    if (status === 'level_iii') {
        return 'Level III';
    }
    if (status === 'others') {
        return 'Others';
    }
    return '';
}

function toiletLabel(type) {
    return String(type || '').replace(/_/g, ' ') || '';
}

function rememberPendingHousehold(householdNo, win) {
    const store = win?.sessionStorage || (typeof sessionStorage !== 'undefined' ? sessionStorage : null);
    if (!store || typeof store.setItem !== 'function') {
        return;
    }
    try {
        store.setItem(PENDING_KEY, JSON.stringify({
            householdNo,
            plottedAt: new Date().toISOString(),
        }));
    } catch {
        // Private browsing may block storage; the EH URL still carries household_no.
    }
}

function assignLocation(path, options) {
    const assign = options.assign || ((url) => {
        const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
        if (typeof win.location?.assign === 'function') {
            win.location.assign(url);
        } else if (typeof win.location?.replace === 'function') {
            win.location.replace(url);
        }
    });
    assign(path);
}

async function putEhHtml(cachesApi, actorId, origin, href, html) {
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cacheName || !cachesApi?.open || !href) {
        return false;
    }
    const parsed = new URL(publicPath(href), origin);
    const cache = await cachesApi.open(cacheName);
    await cache.put(
        new Request(navigationCacheUrl(origin, parsed.pathname, parsed.search)),
        new Response(sanitizeCachedHtml(html), {
            status: 200,
            headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-Lmlinga-Offline-Cache': '1' },
        }),
    );
    return true;
}

function rewriteEhHouseholdHtml(shellHtml, fromNo, household, options = {}) {
    const toNo = String(household.household_no || '');
    const step = Number(options.step || 0);
    const localPending = Boolean(
        household.local
        || household.pending_sync
        || !Number(household.household_id || 0),
    );
    let html = String(shellHtml || '');
    if (html.includes(EH_SHELL_PLACEHOLDER) && EH_SHELL_PLACEHOLDER !== toNo) {
        html = html.split(EH_SHELL_PLACEHOLDER).join(toNo);
    }
    html = canonicalizeEhHouseholdRefs(html, fromNo, toNo);
    // Server-rendered shells carry the placeholder's opaque URL key: point them at this household.
    const targetKey = urlKeyFor('h', toNo);
    html = html
        .replace(/(\/household-water-supply\/)h[a-z2-7]{12,}(?=\/step-)/g, `$1${targetKey}`)
        .replace(/([?&]household=)h[a-z2-7]{12,}/g, `$1${encodeURIComponent(targetKey)}`)
        .replace(/(\/household-profiling\/)h[a-z2-7]{12,}(?=[/"'?#])/g, `$1${targetKey}`);
    html = html.replace(/\sdata-offline-parent-household-id="[^"]*"/gi, '');
    html = html.replace(
        /(data-offline-parent-household-no=")[^"]*/gi,
        `$1${escapeHtml(toNo)}`,
    );
    html = html.replace(
        /(data-household-no=")[^"]*/gi,
        `$1${escapeHtml(toNo)}`,
    );
    html = html.replace(
        /(<input[^>]*name="household_no"[^>]*value=")[^"]*/gi,
        `$1${escapeHtml(toNo)}`,
    );
    if (localPending) {
        // Mark forms so online submits still queue until HOUSEHOLD_CREATE syncs.
        if (!/data-offline-local-household="1"/i.test(html)) {
            html = html.replace(
                /(<form\b[^>]*data-offline-operation="ENVIRONMENTAL_WATER_SUPPLY_UPDATE")/gi,
                '$1 data-offline-local-household="1"',
            );
        }
        if (!/\sdata-offline-local-household="1"/i.test(html)) {
            html = html.replace(
                /(\sdata-lml-hws)(?=[\s>])/i,
                '$1 data-offline-local-household="1"',
            );
        }
    }
    html = stripCheckedControls(html);
    html = html.replace(/\sdata-hws-bound(?:=(?:"[^"]*"|'[^']*'|[^\s>]+))?/gi, '');
    html = restoreViteAssetUrls(html, options.document);
    if (step >= 1 && step <= 4) {
        html = forceHwsStep(html, step);
    }
    return sanitizeCachedHtml(html);
}

function forceHwsStep(html, step) {
    const n = String(Number(step));
    if (/\sdata-hws-step="/i.test(html)) {
        return html.replace(/(\sdata-hws-step=")[^"]*(")/i, `$1${n}$2`);
    }
    return html.replace(/(\sdata-lml-hws)(?=[\s>])/i, `$1 data-hws-step="${n}"`);
}

function restoreViteAssetUrls(html, doc) {
    let next = String(html || '').replace(
        /(?:https?:\/\/[^"'/\s>]+)?(\/build\/assets\/(?:app(?:-[A-Za-z0-9_-]+)?)\.(?:js|css))/gi,
        '$1',
    );
    const live = readDocumentViteAssets(doc);
    if (live.js) {
        next = next.replace(/\/build\/assets\/app(?:-[A-Za-z0-9_-]+)?\.js/g, live.js);
    }
    if (live.css.length) {
        let index = 0;
        next = next.replace(/\/build\/assets\/app(?:-[A-Za-z0-9_-]+)?\.css/g, () => {
            const href = live.css[index] || live.css[live.css.length - 1];
            index += 1;
            return href;
        });
    }
    return next;
}

function readDocumentViteAssets(doc) {
    const js = [];
    const css = [];
    if (!doc?.querySelectorAll) {
        return { js: '', css };
    }
    Array.from(doc.querySelectorAll('script[src]')).forEach((el) => {
        const path = toBuildAssetPath(el.getAttribute?.('src') || el.src || '');
        if (/\/build\/assets\/app(?:-[A-Za-z0-9_-]+)?\.js$/.test(path)) {
            js.push(path);
        }
    });
    Array.from(doc.querySelectorAll('link[href]')).forEach((el) => {
        const path = toBuildAssetPath(el.getAttribute?.('href') || el.href || '');
        if (/\/build\/assets\/app(?:-[A-Za-z0-9_-]+)?\.css$/.test(path)) {
            css.push(path);
        }
    });
    return { js: js[0] || '', css };
}

function toBuildAssetPath(src) {
    const match = String(src || '').match(/\/build\/assets\/[^"'?\s]+/);
    return match ? match[0].replace(/\?.*$/, '') : '';
}

function hasMatchingHwsStep(html, step) {
    const n = Number(step);
    if (!Number.isInteger(n) || n < 1 || n > 4) {
        return false;
    }
    return new RegExp(`\\sdata-hws-step="${n}"`, 'i').test(String(html || ''));
}

function hasLoadableAppScript(html) {
    const text = String(html || '');
    const matches = [...text.matchAll(/<script\b[^>]*\bsrc=["']([^"']+)["']/gi)];
    return matches.some((row) => isLoadableAppScriptSrc(row[1]));
}

function isLoadableAppScriptSrc(src) {
    const path = toBuildAssetPath(src);
    if (!/\/build\/assets\/app(?:-[A-Za-z0-9_-]+)?\.js$/.test(path)) {
        return false;
    }
    const raw = String(src || '').trim();
    if (raw.startsWith('/build/assets/')) {
        return true;
    }
    try {
        const parsed = new URL(raw, 'http://localhost');
        return parsed.pathname.startsWith('/build/assets/')
            && /^(localhost|127\.0\.0\.1)$/i.test(parsed.hostname);
    } catch {
        return false;
    }
}

function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function canonicalizeEhHouseholdRefs(html, fromNo, toNo) {
    const from = String(fromNo || '');
    const to = escapeHtml(toNo);
    if (!from || from === to || from === EH_SHELL_PLACEHOLDER) {
        return html;
    }
    const token = escapeRegExp(from);
    return html
        .replace(new RegExp(`([?&]household=)${token}(?![0-9A-Za-z-])`, 'gi'), `$1${to}`)
        .replace(new RegExp(`(/household-water-supply/)${token}(?=/|"|'|\\?|#)`, 'gi'), `$1${to}`)
        .replace(new RegExp(`(/household-profiling/)${token}(?=/|"|'|\\?|#)`, 'gi'), `$1${to}`);
}

function stripCheckedControls(html) {
    return String(html || '')
        .replace(/\schecked(?:=(?:"checked"|'checked'|checked))?/gi, '')
        .replace(/\bis-selected\b/g, '');
}

function readEhShellBase(doc) {
    const host = doc?.querySelector?.('[data-lml-offline-root]')
        || doc?.closest?.('[data-lml-offline-root]')
        || doc;
    const raw = host?.getAttribute?.('data-offline-eh-shell-base')
        || '/offline/environmental-health-shell';
    return String(raw).replace(/\/+$/, '');
}

async function fetchHtml(fetchImpl, url) {
    const response = await fetchImpl(url, {
        credentials: 'same-origin',
        headers: { Accept: 'text/html' },
    });
    if (!response || !response.ok) {
        return '';
    }
    return await response.text();
}

function checkNamed(root, name, value) {
    if (value == null || value === '') {
        return;
    }
    const match = root.querySelector(`[name="${name}"][value="${cssEscape(String(value))}"]`);
    if (match) {
        match.checked = true;
    }
}

function setNamed(root, name, value) {
    const field = root.querySelector(`[name="${name}"]`);
    if (!field || value == null) {
        return;
    }
    field.value = String(value);
}

function cssEscape(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function bootEhLive() {
    if (typeof document === 'undefined') {
        return;
    }
    const root = document.querySelector('[data-lml-offline-root]');
    if (!root) {
        return;
    }
    try {
        await prepareEnvironmentalHealthShells({ document, window, root });
    } catch {
        // Canonical shell warmup is best-effort while online.
    }
    const ehRoot = document.querySelector('[data-lml-hws]');
    if (!ehRoot) {
        return;
    }
    try {
        await rememberEhShellFromDocument(document);
    } catch {
        // Shell capture is best-effort.
    }
    const actorId = readActorId(document);
    const householdNo = String(ehRoot.getAttribute('data-household-no') || '').trim();
    if (actorId && householdNo) {
        try {
            const household = await getHouseholdSnapshot(actorId, householdNo);
            if (household) {
                fillEhFormFromSnapshot(ehRoot, household);
            }
        } catch {
            // Live fill is best-effort.
        }
    }
    initHouseholdWaterSupply(document.querySelector('[data-lml-hws]') || ehRoot);
}

export { bootEhLive, rewriteEhHouseholdHtml };

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            void bootEhLive();
        });
    } else {
        void bootEhLive();
    }
}
