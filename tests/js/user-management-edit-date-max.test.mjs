/**
 * Cached-page date max refresh for User Management Edit.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(path.resolve('resources/js/pages/user-management-edit.js')).href;
const {
    localCalendarDateIso,
    localYesterdayIso,
    refreshHealthWorkerDateMax,
} = await import(moduleUrl);

function dateInput(attrs) {
    const data = { ...attrs };

    return {
        value: data.value || '',
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(data, name) ? data[name] : null;
        },
        setAttribute(name, value) {
            data[name] = value;
        },
    };
}

function createRoot(dob, appointed) {
    return {
        querySelector(selector) {
            if (selector.includes('hw_dob') || selector.includes('data-hw-dob')) {
                return dob;
            }
            if (selector.includes('hw_date_appointed') || selector.includes('date_appointed')) {
                return appointed;
            }
            return null;
        },
    };
}

describe('user management edit date max refresh', () => {
    it('builds local YYYY-MM-DD without UTC shifting', () => {
        const now = new Date(2026, 8, 11, 0, 30, 0);

        assert.equal(localCalendarDateIso(now), '2026-09-11');
        assert.equal(localYesterdayIso(now), '2026-09-10');
    });

    it('sets DOB max to yesterday and appointed max to today without erasing values', () => {
        const dob = dateInput({ max: '2026-01-01', value: '1990-02-02' });
        const appointed = dateInput({ max: '2026-01-01', value: '2020-01-15' });
        const now = new Date(2026, 8, 11, 15, 0, 0);

        refreshHealthWorkerDateMax(createRoot(dob, appointed), now);

        assert.equal(dob.getAttribute('max'), '2026-09-10');
        assert.equal(appointed.getAttribute('max'), '2026-09-11');
        assert.equal(dob.value, '1990-02-02');
        assert.equal(appointed.value, '2020-01-15');
    });

    it('rolls yesterday across month boundaries', () => {
        const dob = dateInput({ max: '1999-01-01', value: '1988-07-12' });
        const appointed = dateInput({ max: '1999-01-01', value: '2019-06-01' });
        const now = new Date(2026, 0, 1, 8, 0, 0);

        refreshHealthWorkerDateMax(createRoot(dob, appointed), now);

        assert.equal(dob.getAttribute('max'), '2025-12-31');
        assert.equal(appointed.getAttribute('max'), '2026-01-01');
        assert.equal(dob.value, '1988-07-12');
        assert.equal(appointed.value, '2019-06-01');
    });
});
