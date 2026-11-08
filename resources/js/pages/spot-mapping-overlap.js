/**
 * Spot Mapping FR-05 — presentation-only overlap grouping.
 * Never mutates household latitude/longitude.
 */

export const MARKER_OVERLAP_PX = 24;

/**
 * @param {number} lat
 * @param {number} lng
 * @returns {string}
 */
export function coordinateKey(lat, lng) {
    return `${Number(lat)}|${Number(lng)}`;
}

/**
 * @param {{ id: string, lat: number, lng: number }[]} items
 * @param {{
 *   getLayerPoint?: (lat: number, lng: number) => { x: number, y: number },
 *   threshold?: number,
 * }} [options]
 */
export function buildOverlapGroups(items, options = {}) {
    const list = Array.isArray(items) ? items : [];
    const getLayerPoint = options.getLayerPoint;
    const threshold = options.threshold ?? MARKER_OVERLAP_PX;
    const parent = list.map((_, index) => index);

    function find(index) {
        let current = index;
        while (parent[current] !== current) {
            parent[current] = parent[parent[current]];
            current = parent[current];
        }
        return current;
    }

    function union(a, b) {
        const rootA = find(a);
        const rootB = find(b);
        if (rootA === rootB) {
            return;
        }
        if (String(list[rootA].id) <= String(list[rootB].id)) {
            parent[rootB] = rootA;
        } else {
            parent[rootA] = rootB;
        }
    }

    const byExact = new Map();
    list.forEach((item, index) => {
        const key = coordinateKey(item.lat, item.lng);
        if (byExact.has(key)) {
            union(byExact.get(key), index);
        } else {
            byExact.set(key, index);
        }
    });

    if (typeof getLayerPoint === 'function' && list.length > 1) {
        const points = list.map((item) => getLayerPoint(item.lat, item.lng));
        for (let i = 0; i < list.length; i += 1) {
            for (let j = i + 1; j < list.length; j += 1) {
                if (find(i) === find(j)) {
                    continue;
                }
                const dx = points[i].x - points[j].x;
                const dy = points[i].y - points[j].y;
                if (Math.hypot(dx, dy) < threshold) {
                    union(i, j);
                }
            }
        }
    }

    const buckets = new Map();
    list.forEach((item, index) => {
        const root = find(index);
        if (!buckets.has(root)) {
            buckets.set(root, []);
        }
        buckets.get(root).push(item);
    });

    return [...buckets.values()]
        .map((members) => {
            const sorted = [...members].sort((a, b) => String(a.id).localeCompare(String(b.id)));
            return {
                key: sorted.map((member) => String(member.id)).join('|'),
                members: sorted,
                count: sorted.length,
                lat: sorted[0].lat,
                lng: sorted[0].lng,
            };
        })
        .sort((a, b) => a.key.localeCompare(b.key));
}

/**
 * @param {ReturnType<typeof buildOverlapGroups>} groups
 */
export function planOverlapVisuals(groups) {
    const individuals = [];
    const stacked = [];
    const memberToVisual = new Map();

    (groups || []).forEach((group) => {
        if (group.count <= 1) {
            const id = String(group.members[0].id);
            individuals.push(id);
            memberToVisual.set(id, { type: 'individual', id });
            return;
        }

        const memberIds = group.members.map((member) => String(member.id));
        stacked.push({
            key: group.key,
            memberIds,
            count: group.count,
            lat: group.lat,
            lng: group.lng,
        });
        memberIds.forEach((id) => {
            memberToVisual.set(id, { type: 'group', key: group.key });
        });
    });

    return { individuals, stacked, memberToVisual };
}

/**
 * Ensures each household is represented once: individual layer XOR group layer.
 *
 * @param {ReturnType<typeof planOverlapVisuals>} plan
 * @param {{ householdLayers?: Set<string>, groupLayers?: Set<string> }} [previous]
 */
export function applyOverlapLayerPlan(plan, previous = {}) {
    const householdLayers = new Set(plan.individuals);
    const groupLayers = new Set(plan.stacked.map((group) => group.key));
    const duplicateVisuals = [];

    householdLayers.forEach((id) => {
        plan.stacked.forEach((group) => {
            if (group.memberIds.includes(id)) {
                duplicateVisuals.push(id);
            }
        });
    });

    return {
        householdLayers,
        groupLayers,
        duplicateVisuals,
        groupKeyCollision: groupLayers.size !== plan.stacked.length,
        householdCount: householdLayers.size,
        groupCount: groupLayers.size,
        previousHouseholdCount: previous.householdLayers?.size ?? 0,
        previousGroupCount: previous.groupLayers?.size ?? 0,
    };
}

/**
 * @param {number} count
 * @returns {string}
 */
export function groupAriaLabel(count) {
    return `${count} households at this location`;
}

/**
 * @param {{ id: string, householdNo: string, houseHead?: string, zoneLabel?: string, zone?: number|null }[]} members
 */
export function buildSelectorEntries(members) {
    return (members || []).map((member) => ({
        id: member.id,
        householdNo: member.householdNo,
        houseHead: member.houseHead || '',
        zoneLabel: member.zoneLabel || '',
        lat: member.lat,
        lng: member.lng,
    }));
}

/**
 * @param {{ householdNo: string }[]} entries
 * @param {string} householdNo
 */
export function chooseSelectorHousehold(entries, householdNo) {
    return (entries || []).find((entry) => entry.householdNo === householdNo) || null;
}

/**
 * @param {{ householdNo: string }[]} members
 */
export function createGroupSelectorState(members) {
    return {
        heading: 'Multiple households at this location',
        entries: buildSelectorEntries(members),
    };
}
