/**
 * Actor-scoped Cache Storage map of local member IDs to server member numbers.
 * Never stores CSRF tokens, passwords, or queued operation payloads.
 */

import { htmlCacheNameForActor, navigationCacheUrl } from './offline-sw-policy.js';
import { publicPath } from './offline-url-keys.js';

export const IDENTITY_MAP_PATH = '/__lmlinga/member-identity-map';

function actorIdFrom(options = {}) {
    const id = Number(options.actorId || 0);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function mapRequest(origin) {
    return new Request(navigationCacheUrl(origin, IDENTITY_MAP_PATH));
}

export async function readMemberIdentityMap(options = {}) {
    const actorId = actorIdFrom(options);
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cacheName) {
        return {};
    }
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    if (!cachesApi?.open) {
        return {};
    }
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');
    try {
        const cache = await cachesApi.open(cacheName);
        const hit = await cache.match(mapRequest(origin));
        if (!hit) {
            return {};
        }
        const data = await hit.json();
        return data && typeof data === 'object' ? data : {};
    } catch {
        return {};
    }
}

export async function writeMemberIdentityMap(map, options = {}) {
    const actorId = actorIdFrom(options);
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cacheName) {
        return false;
    }
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    if (!cachesApi?.open) {
        return false;
    }
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');
    const body = JSON.stringify(map && typeof map === 'object' ? map : {});
    try {
        const cache = await cachesApi.open(cacheName);
        await cache.put(
            mapRequest(origin),
            new Response(body, {
                headers: { 'Content-Type': 'application/json', 'X-Lmlinga-Offline-Cache': '1' },
            }),
        );
        return true;
    } catch {
        return false;
    }
}

export async function rememberLocalMember(localMemberId, fields, options = {}) {
    const localId = String(localMemberId || '').trim();
    if (!/^MB-L-[A-Za-z0-9]+$/i.test(localId)) {
        return false;
    }
    const map = await readMemberIdentityMap(options);
    map[localId] = {
        household_no: String(fields.household_no || ''),
        member_no: fields.member_no || null,
        first_name: String(fields.first_name || ''),
        last_name: String(fields.last_name || ''),
        middle_name: String(fields.middle_name || ''),
        relation: String(fields.relation || ''),
        relationship_status: String(fields.relationship_status || ''),
        sex: String(fields.sex || ''),
        birthday: String(fields.birthday || ''),
        occupation: String(fields.occupation || fields.occupation_select || ''),
        religion: String(fields.religion || fields.religion_select || ''),
        education: String(fields.education || ''),
        monthly_income: String(fields.monthly_income || ''),
        philhealth: String(fields.philhealth || ''),
        fp_user: String(fields.fp_user || ''),
    };
    return writeMemberIdentityMap(map, options);
}

export async function reconcileLocalMember(localMemberId, serverMemberNo, options = {}) {
    const localId = String(localMemberId || '').trim();
    const serverId = String(serverMemberNo || '').trim();
    if (!localId || !/^MB-\d+$/.test(serverId)) {
        return false;
    }
    const map = await readMemberIdentityMap(options);
    const current = map[localId] && typeof map[localId] === 'object' ? map[localId] : {};
    map[localId] = { ...current, member_no: serverId };
    return writeMemberIdentityMap(map, options);
}

export function resolveMemberHref(href, map, origin = 'http://localhost') {
    if (!href || !map || typeof map !== 'object') {
        return href;
    }
    try {
        const parsed = new URL(String(href), origin);
        const match = parsed.pathname.match(/\/members\/(MB-L-[A-Za-z0-9]+)(\/|$)/i);
        if (!match) {
            return href;
        }
        const localId = match[1];
        const serverId = map[localId]?.member_no;
        if (!serverId || !/^MB-\d+$/.test(String(serverId))) {
            return href;
        }
        parsed.pathname = parsed.pathname.replace(localId, serverId);
        return publicPath(parsed.pathname) + parsed.search;
    } catch {
        return href;
    }
}
