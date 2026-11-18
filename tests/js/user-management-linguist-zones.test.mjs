/**
 * SR-06: Assigned Zones are hidden (and not validated) while the edited account's role is Linguist.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(path.resolve('resources/js/pages/user-management-edit.js')).href;
const { LINGUIST_ROLE, isLinguistRole, syncLinguistZoneGroup } = await import(moduleUrl);

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const { isNeverCachePath } = await import(policyUrl);

function createRoot(role, { withGroup = true } = {}) {
    const roleSelect = { value: role };
    const group = { hidden: false };
    const error = { textContent: 'Select at least one zone.', hidden: false };

    return {
        roleSelect,
        group,
        error,
        querySelector(selector) {
            if (selector === '[data-hw-role]') {
                return roleSelect;
            }
            if (selector === '[data-hw-zone-group]') {
                return withGroup ? group : null;
            }
            if (selector === '[data-hw-error="assigned_zone"]') {
                return error;
            }
            return null;
        },
        querySelectorAll() {
            return [];
        },
    };
}

describe('Linguist zone group', () => {
    it('detects the Linguist role from the role select', () => {
        assert.equal(LINGUIST_ROLE, 'Linguist');
        assert.equal(isLinguistRole(createRoot('Linguist')), true);
        assert.equal(isLinguistRole(createRoot('BHW')), false);
    });

    it('hides the zone group and clears the zone error for a Linguist', () => {
        const root = createRoot('Linguist');
        syncLinguistZoneGroup(root);
        assert.equal(root.group.hidden, true);
        assert.equal(root.error.textContent, '');
        assert.equal(root.error.hidden, true);
    });

    it('shows the zone group again when the role changes back to a health worker', () => {
        const root = createRoot('Linguist');
        syncLinguistZoneGroup(root);
        root.roleSelect.value = 'BNS';
        syncLinguistZoneGroup(root);
        assert.equal(root.group.hidden, false);
    });

    it('is a no-op on pages without the zone group', () => {
        assert.doesNotThrow(() => syncLinguistZoneGroup(createRoot('Linguist', { withGroup: false })));
    });
});

describe('Language Review offline policy', () => {
    it('never caches Language Review pages', () => {
        assert.equal(isNeverCachePath('/language-review'), true);
        assert.equal(isNeverCachePath('/language-review/anything'), true);
    });
});
