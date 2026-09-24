/**
 * Online Household Profiling index: one JSON bootstrap, then hydrate shells.
 */

import { OFFLINE_EVENTS } from './offline-status.js';
import { publicPath, registerUrlKeysFromSnapshots } from './offline-url-keys.js';
import { readPageActorId } from './offline-nav-guard.js';
import {
    listHouseholdSnapshots,
    listMemberSnapshots,
    membersForHousehold,
    getHouseholdSnapshot,
    putMeta,
    replaceHouseholdProfilingSnapshots,
} from './offline-hp-store.js';
import {
    cacheHydratedHouseholdPages,
    rememberShells,
    SUPPORTED_HEALTH_MODULES,
    healthModulePath,
    cachePreparedHealthPage,
    hasCanonicalHealthShells,
    NUTRITION_CREATE_SHELL_KEY,
} from './offline-hp-hydrate.js';
import {
    cacheEhWizardPages,
    prepareEnvironmentalHealthShells,
} from './offline-eh-hydrate.js';

export const HP_BOOTSTRAP_EVENTS = {
    PROGRESS: 'lmlinga:hp-offline-progress',
};

let inFlight = null;

export function resetHpBootstrapForTests() {
    inFlight = null;
}

function readBootstrapUrl(doc, overrideUrl) {
    if (overrideUrl) {
        return String(overrideUrl);
    }
    const host = doc?.querySelector?.('[data-lml-hh-profiling]');
    return host?.getAttribute?.('data-offline-hp-bootstrap-url')
        || '/offline/household-profiling-bootstrap';
}

function emitProgress(win, detail) {
    const payload = { ...detail };
    if (win?.LmlingaOffline?.emit) {
        win.LmlingaOffline.emit(HP_BOOTSTRAP_EVENTS.PROGRESS, payload);
    }
    if (typeof win?.dispatchEvent === 'function') {
        const event = typeof CustomEvent === 'function'
            ? new CustomEvent(HP_BOOTSTRAP_EVENTS.PROGRESS, { detail: payload })
            : { type: HP_BOOTSTRAP_EVENTS.PROGRESS, detail: payload };
        win.dispatchEvent(event);
    }
}

function updateStatusNode(doc, detail, options = {}) {
    if (options.updatePageStatus === false) {
        return;
    }
    const node = doc?.querySelector?.('[data-hp-offline-ready]');
    if (!node) {
        return;
    }
    node.hidden = false;
    if (detail.state === 'preparing') {
        node.textContent = `Preparing offline data… ${detail.ready} / ${detail.total} households`;
        node.setAttribute('data-hp-offline-state', 'preparing');
        return;
    }
    if (detail.state === 'ready') {
        node.textContent = `Offline Ready — ${detail.total} households`;
        node.setAttribute('data-hp-offline-state', 'ready');
        return;
    }
    if (detail.state === 'partial') {
        node.textContent = `${detail.ready} / ${detail.total} ready — Retry`;
        node.setAttribute('data-hp-offline-state', 'partial');
        return;
    }
    node.textContent = 'Could not prepare offline data. Retry';
    node.setAttribute('data-hp-offline-state', 'error');
}

async function fetchHtml(fetchImpl, url) {
    const response = await fetchImpl(url, {
        credentials: 'same-origin',
        headers: { Accept: 'text/html' },
        redirect: 'follow',
    });
    if (!response || !response.ok) {
        return '';
    }
    const text = await response.text();
    // Reject tiny / non-app responses (redirects to login, bare errors).
    if (!text || text.length < 200) {
        return '';
    }
    if (!/lml-dashboard|lml-sidebar|lml-hh-|data-lml-|data-lml-offline-root|data-household-no/i.test(text)) {
        return '';
    }
    return text;
}

function pickHealthShellDonor(payload, prefer = {}) {
    const members = Array.isArray(payload.members) ? payload.members : [];
    if (!members.length) {
        return null;
    }
    if (prefer.withoutRecords) {
        // Prefer a donor with no saved records so the shell never carries record rows.
        const empty = members.find(
            (row) => row.health?.has_records?.[prefer.withoutRecords] === false,
        );
        if (empty) {
            return empty;
        }
    }
    if (prefer.female) {
        const female = members.find((row) => /^female$/i.test(String(row.sex || '')));
        if (female) {
            return female;
        }
    }
    if (prefer.adult) {
        const adult = members.find((row) => Number(row.age) >= 19 || Number(row.health?.member_age) >= 19);
        if (adult) {
            return adult;
        }
    }
    if (prefer.child) {
        const child = members.find((row) => {
            const age = Number(row.age ?? row.health?.member_age);
            return Number.isFinite(age) && age < 18;
        });
        if (child) {
            return child;
        }
    }
    return members[0];
}

async function collectHealthShells(fetchImpl, origin, payload, fallbackShell = '') {
    const health = {};
    const donors = {
        'child-immunization': pickHealthShellDonor(payload, { child: true }) || pickHealthShellDonor(payload),
        'school-based-immunization': pickHealthShellDonor(payload, { child: true }) || pickHealthShellDonor(payload),
        'child-nutrition': pickHealthShellDonor(payload, { child: true }) || pickHealthShellDonor(payload),
        'nutritional-status': pickHealthShellDonor(payload, { withoutRecords: 'nutritional-status' }),
        deworming: pickHealthShellDonor(payload),
        'risk-assessment': pickHealthShellDonor(payload, { adult: true }) || pickHealthShellDonor(payload),
        'family-planning': pickHealthShellDonor(payload, { female: true }) || pickHealthShellDonor(payload),
        'maternal-care': pickHealthShellDonor(payload, { female: true }) || pickHealthShellDonor(payload),
    };

    let shellHouseholdNo = '';
    let shellMemberId = '';
    let templateRequests = 0;
    let templateOk = 0;
    console.time('[PREP PERF] health-pages');
    for (const key of SUPPORTED_HEALTH_MODULES) {
        const donor = donors[key];
        let html = '';
        if (donor) {
            templateRequests += 1;
            html = await fetchHtml(
                fetchImpl,
                `${origin}${publicPath(healthModulePath(donor.household_no, donor.member_no, key))}`,
            );
            if (html) {
                templateOk += 1;
            }
            if (html && !shellHouseholdNo) {
                shellHouseholdNo = donor.household_no;
                shellMemberId = donor.member_no;
            }
        }
        // Prefer a real module page; fall back to member-view shell so prep can still succeed.
        health[key] = html || fallbackShell || '';
    }
    // Add Measurement form shell (separate page from the Nutritional Status index shell).
    const createDonor = donors['nutritional-status'];
    health[NUTRITION_CREATE_SHELL_KEY] = '';
    if (createDonor) {
        try {
            health[NUTRITION_CREATE_SHELL_KEY] = await fetchHtml(
                fetchImpl,
                `${origin}${publicPath(healthModulePath(createDonor.household_no, createDonor.member_no, 'nutritional-status', 'create'))}`,
            );
        } catch {
            health[NUTRITION_CREATE_SHELL_KEY] = '';
        }
    }
    console.timeEnd('[PREP PERF] health-pages');
    console.log('[PREP PERF] health templates', {
        requests: templateRequests,
        ok: templateOk,
        modules: SUPPORTED_HEALTH_MODULES.length,
    });

    health.householdNo = shellHouseholdNo || payload.households?.[0]?.household_no || '';
    health.memberId = shellMemberId || payload.members?.[0]?.member_no || '';
    return health;
}

async function collectShells(fetchImpl, origin, payload) {
    const first = payload.households[0];
    if (!first) {
        return {};
    }
    const hh = first.household_no;
    const member = payload.members.find((row) => row.household_no === hh);
    const [view, create, amenities, memberView, memberEdit] = await Promise.all([
        fetchHtml(fetchImpl, `${origin}${publicPath(`/household-profiling/${hh}`)}`),
        fetchHtml(fetchImpl, `${origin}${publicPath(`/household-profiling/${hh}/members/create`)}`),
        fetchHtml(fetchImpl, `${origin}${publicPath(`/household-profiling/${hh}/amenities`)}`).catch(() => ''),
        member
            ? fetchHtml(fetchImpl, `${origin}${publicPath(`/household-profiling/${hh}/members/${member.member_no}`)}`)
            : Promise.resolve(''),
        member
            ? fetchHtml(fetchImpl, `${origin}${publicPath(`/household-profiling/${hh}/members/${member.member_no}/edit`)}`)
            : Promise.resolve(''),
    ]);
    const health = await collectHealthShells(fetchImpl, origin, payload, memberView);
    return {
        view,
        create,
        amenities,
        memberView,
        memberEdit,
        householdNo: hh,
        memberId: member?.member_no || '',
        health,
    };
}

/**
 * Fetch real server health pages for members that already have records so
 * offline VIEW works without manually opening each module first.
 */
async function warmExistingHealthPages(fetchImpl, origin, actorId, payload, options = {}) {
    const members = Array.isArray(payload.members) ? payload.members : [];
    let warmed = 0;
    let requests = 0;
    console.time('[PREP PERF] health-existing');
    for (const member of members) {
        const warmList = Array.isArray(member.health?.warm_modules) ? member.health.warm_modules : [];
        for (const moduleKey of warmList) {
            if (!SUPPORTED_HEALTH_MODULES.includes(moduleKey)) {
                continue;
            }
            const path = healthModulePath(member.household_no, member.member_no, moduleKey);
            requests += 1;
            const html = await fetchHtml(fetchImpl, `${origin}${publicPath(path)}`);
            if (!html) {
                continue;
            }
            await cachePreparedHealthPage(actorId, path, html, options);
            warmed += 1;
        }
    }
    console.timeEnd('[PREP PERF] health-existing');
    console.log('[PREP PERF] health existing-record', {
        requests,
        completed: warmed,
        total: requests,
    });
    return warmed;
}

export async function prepareHouseholdProfilingOffline(options = {}) {
    if (inFlight) {
        return inFlight;
    }

    inFlight = (async () => {
        const win = options.window || (typeof window !== 'undefined' ? window : null);
        const doc = options.document || (typeof document !== 'undefined' ? document : null);
        const nav = options.navigator || win?.navigator || { onLine: true };
        const fetchImpl = options.fetch || win?.fetch;
        const actorId = Number(options.actorId || readPageActorId(doc) || 0);
        const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');

        try {
            if (!Number.isInteger(actorId) || actorId <= 0) {
                return { ok: false, reason: 'actor' };
            }
            if (nav.onLine === false) {
                return { ok: false, reason: 'offline' };
            }
            if (typeof fetchImpl !== 'function') {
                return { ok: false, reason: 'no-fetch' };
            }

            const onProgress = typeof options.onProgress === 'function' ? options.onProgress : null;
            emitProgress(win, { state: 'preparing', ready: 0, total: 0 });
            updateStatusNode(doc, { state: 'preparing', ready: 0, total: 0 }, options);
            onProgress?.({ state: 'preparing', ready: 0, total: 0 });

            console.time('[PREP PERF] bootstrap');
            const response = await fetchImpl(readBootstrapUrl(doc, options.bootstrapUrl), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response || !response.ok) {
                console.timeEnd('[PREP PERF] bootstrap');
                updateStatusNode(doc, { state: 'error', ready: 0, total: 0 }, options);
                return { ok: false, reason: 'http' };
            }
            const body = await response.json();
            console.timeEnd('[PREP PERF] bootstrap');
            if (!body?.ok || Number(body.actor_id) !== actorId) {
                updateStatusNode(doc, { state: 'error', ready: 0, total: 0 }, options);
                return { ok: false, reason: 'actor-mismatch' };
            }

            const payload = body.payload || { households: [], members: [], catalogs: {} };
            registerUrlKeysFromSnapshots(payload.households, payload.members);
            const total = payload.households.length;
            const memberTotal = Array.isArray(payload.members) ? payload.members.length : 0;
            console.time('[PREP PERF] indexeddb');
            await replaceHouseholdProfilingSnapshots(actorId, payload);
            console.timeEnd('[PREP PERF] indexeddb');

            console.time('[PREP PERF] household-pages');
            const shells = options.shells || await collectShells(fetchImpl, origin, payload);
            await rememberShells(actorId, shells);
            console.timeEnd('[PREP PERF] household-pages');

            if (payload.households.length > 0) {
                const healthOk = SUPPORTED_HEALTH_MODULES.every((key) => {
                    const html = shells.health?.[key];
                    return html && String(html).length > 200;
                });
                if (!healthOk && options.requireHealthShells !== false) {
                    updateStatusNode(doc, { state: 'error', ready: 0, total }, options);
                    return { ok: false, reason: 'health-shells' };
                }
            }

            if (options.prepareEh !== false) {
                console.time('[PREP PERF] eh-pages');
                const ehResult = await prepareEnvironmentalHealthShells({
                    ...options,
                    window: win,
                    document: doc,
                    actorId,
                    force: Boolean(options.forceEh),
                });
                console.timeEnd('[PREP PERF] eh-pages');
                if (!ehResult?.ok) {
                    updateStatusNode(doc, { state: 'error', ready: 0, total }, options);
                    return { ok: false, reason: 'eh-shells', detail: ehResult };
                }
            }

            let ready = 0;
            console.time('[PREP PERF] member-pages');
            for (const household of payload.households) {
                const snap = await getHouseholdSnapshot(actorId, household.household_no) || household;
                const members = await membersForHousehold(actorId, household.household_no);
                const shellBundle = {
                    view: shells.view,
                    create: shells.create,
                    memberView: shells.memberView,
                    memberEdit: shells.memberEdit,
                    amenities: shells.amenities,
                    shellHouseholdNo: shells.householdNo,
                    shellMemberId: shells.memberId,
                    health: shells.health,
                };
                await cacheHydratedHouseholdPages(actorId, snap, members, {
                    ...options,
                    actorId,
                    origin,
                    shells: shellBundle,
                    healthShells: shells.health,
                });
                if (options.prepareEh !== false) {
                    await cacheEhWizardPages(actorId, snap, {
                        ...options,
                        actorId,
                        origin,
                        shellHouseholdNo: shells.householdNo,
                    });
                }
                ready += 1;
                emitProgress(win, { state: 'preparing', ready, total });
                updateStatusNode(doc, { state: 'preparing', ready, total }, options);
                onProgress?.({ state: 'preparing', ready, total, memberTotal });
            }
            console.timeEnd('[PREP PERF] member-pages');

            const healthWarmed = await warmExistingHealthPages(
                fetchImpl,
                origin,
                actorId,
                payload,
                { ...options, actorId, origin },
            );

            const localMembers = await listMemberSnapshots(actorId);
            const queuedLocals = localMembers.filter((row) => /^MB-L-/i.test(String(row.member_no || '')));
            for (const local of queuedLocals) {
                const household = (await getHouseholdSnapshot(actorId, local.household_no))
                    || payload.households.find((row) => row.household_no === local.household_no);
                if (!household) {
                    continue;
                }
                const members = await membersForHousehold(actorId, local.household_no);
                await cacheHydratedHouseholdPages(actorId, { ...household, member_count: members.length }, members, {
                    ...options,
                    actorId,
                    origin,
                    shells: {
                        ...shells,
                        shellHouseholdNo: shells.householdNo,
                        shellMemberId: shells.memberId,
                        health: shells.health,
                    },
                    healthShells: shells.health,
                });
            }

            if (payload.households.length > 0 && options.requireHealthShells !== false) {
                const canonical = await hasCanonicalHealthShells(actorId);
                if (!canonical) {
                    updateStatusNode(doc, { state: 'error', ready, total }, options);
                    return { ok: false, reason: 'health-shells-verify' };
                }
            }

            const state = total === 0 || ready === total ? 'ready' : 'partial';
            await putMeta(actorId, 'status', {
                ready,
                total,
                memberTotal,
                healthWarmed,
                generated_at: payload.generated_at,
                state,
            });
            emitProgress(win, { state, ready, total, memberTotal, healthWarmed });
            updateStatusNode(doc, { state, ready, total }, options);
            onProgress?.({ state, ready, total, memberTotal, healthWarmed });
            return {
                ok: state === 'ready',
                ready,
                total,
                memberCount: memberTotal,
                healthWarmed,
                generated_at: payload.generated_at || null,
                reason: state === 'ready' ? 'ready' : 'partial',
            };
        } catch {
            const existing = await listHouseholdSnapshots(actorId).catch(() => []);
            const total = existing.length;
            updateStatusNode(doc, { state: total ? 'partial' : 'error', ready: total, total }, options);
            emitProgress(win, { state: total ? 'partial' : 'error', ready: total, total });
            return { ok: false, reason: 'error' };
        } finally {
            inFlight = null;
        }
    })();

    return inFlight;
}

function bindRetry(doc) {
    const node = doc?.querySelector?.('[data-hp-offline-ready]');
    if (!node || node.getAttribute('data-hp-retry-bound') === '1') {
        return;
    }
    node.setAttribute('data-hp-retry-bound', '1');
    node.addEventListener('click', () => {
        const state = node.getAttribute('data-hp-offline-state');
        if (state === 'partial' || state === 'error') {
            void prepareHouseholdProfilingOffline();
        }
    });
}

function boot() {
    if (typeof document === 'undefined') {
        return;
    }
    const host = document.querySelector('[data-lml-hh-profiling]');
    if (!host) {
        return;
    }
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        return;
    }
    bindRetry(document);
    void prepareHouseholdProfilingOffline();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}

void OFFLINE_EVENTS;
