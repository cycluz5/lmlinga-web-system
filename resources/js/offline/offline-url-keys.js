/**
 * Opaque URL keys for the offline layer.
 *
 * The server shows household / member / resident ids in URLs as keyed codes (OpaqueId).
 * Offline code keeps working with the internal ids (HH / MB / resident id) and converts
 * only where a URL is created or matched:
 *
 *   publicPath('/household-profiling/001/members/MB-041')  -> '/household-profiling/hb2v…/members/mhjj…'
 *   legacyPath(publicPath(x))                              -> the internal-id form again
 *
 * Both are idempotent and fall back to the input when no key is known (offline-created
 * households/members have no server key and keep their local ids).
 * The codes come from the preparation payload (url_key / resident_url_key) and are read
 * back from the actor's stored snapshots.
 */

const KINDS = ['h', 'm', 'r'];
const forward = { h: new Map(), m: new Map(), r: new Map() };
const reverse = { h: new Map(), m: new Map(), r: new Map() };

const HP_RESERVED = new Set(['create', 'report', 'export']);

export function resetUrlKeys() {
    KINDS.forEach((kind) => {
        forward[kind].clear();
        reverse[kind].clear();
    });
}

export function registerUrlKey(kind, internal, key) {
    const from = String(internal ?? '').trim();
    const to = String(key ?? '').trim();
    if (!forward[kind] || !from || !to || from === to) {
        return;
    }
    forward[kind].set(from, to);
    reverse[kind].set(to, from);
}

/** Register the keys carried by prepared household / member snapshots. */
export function registerUrlKeysFromSnapshots(households = [], members = []) {
    (Array.isArray(households) ? households : []).forEach((row) => {
        registerUrlKey('h', row?.household_no, row?.url_key);
    });
    (Array.isArray(members) ? members : []).forEach((row) => {
        registerUrlKey('m', row?.member_no, row?.url_key);
        registerUrlKey('r', row?.resident_id, row?.resident_url_key);
    });
}

/** Learn the codes the server just issued for records created/updated by a sync. */
export function registerUrlKeysFromIdentities(identities) {
    if (!identities || typeof identities !== 'object') {
        return;
    }
    registerUrlKey('h', identities.household_no, identities.household_key);
    registerUrlKey('m', identities.member_no, identities.member_key);
    registerUrlKey('r', identities.resident_pk, identities.resident_key);
}

export async function loadUrlKeysForActor(actorId) {
    const store = await import('./offline-hp-store.js');
    const [households, members] = await Promise.all([
        store.listHouseholdSnapshots(actorId),
        store.listMemberSnapshots(actorId),
    ]);
    registerUrlKeysFromSnapshots(households, members);
    return { households: households.length, members: members.length };
}

export function urlKeyFor(kind, internal) {
    const value = String(internal ?? '').trim();
    return forward[kind]?.get(value) || value;
}

export function internalIdFor(kind, key) {
    const value = String(key ?? '').trim();
    return reverse[kind]?.get(value) || value;
}

function transformPath(input, map) {
    const text = String(input ?? '');
    const match = text.match(/^([^?#]*)(.*)$/);
    const segments = match[1].split('/');
    let tail = match[2];

    if (segments[1] === 'household-profiling' && segments[2] && !HP_RESERVED.has(segments[2])) {
        segments[2] = map('h', segments[2]);
        if (segments[3] === 'members' && segments[4] && segments[4] !== 'create') {
            segments[4] = map('m', segments[4]);
        }
    } else if (segments[1] === 'households' && segments[2] && segments[3] === 'residents' && segments[4]) {
        segments[2] = map('h', segments[2]);
        segments[4] = map('r', segments[4]);
    } else if (
        segments[1] === 'environmental-health'
        && segments[2] === 'household-water-supply'
        && segments[3]
    ) {
        segments[3] = map('h', segments[3]);
    }

    tail = tail.replace(/([?&]household=)([^&#]*)/, (whole, prefix, value) => {
        let decoded = value;
        try {
            decoded = decodeURIComponent(value);
        } catch {
            decoded = value;
        }
        return `${prefix}${encodeURIComponent(map('h', decoded))}`;
    });

    return segments.join('/') + tail;
}

/** Internal-id path -> the URL the server (and cache) uses. */
export function publicPath(path) {
    return transformPath(path, urlKeyFor);
}

/** URL path with codes -> internal-id path (what snapshots and queues are keyed by). */
export function legacyPath(path) {
    return transformPath(path, internalIdFor);
}
