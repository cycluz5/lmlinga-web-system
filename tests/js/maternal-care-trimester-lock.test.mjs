/**
 * Prenatal future-trimester lock: edit mode must not enable locked visit fields.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const moduleUrl = pathToFileURL(
    path.resolve('resources/js/pages/maternal-care.js')
).href;

const { isPrenatalVisitLocked } = await import(moduleUrl);

function fieldWithClosest(locked) {
    return {
        closest(selector) {
            if (selector !== '[data-mc-visit-locked]') {
                return null;
            }
            if (! locked) {
                return null;
            }
            return {
                getAttribute(name) {
                    return name === 'data-mc-visit-locked' ? 'true' : null;
                },
            };
        },
    };
}

describe('isPrenatalVisitLocked', () => {
    it('treats data-mc-visit-locked=true as locked', () => {
        assert.equal(isPrenatalVisitLocked(fieldWithClosest(true)), true);
    });

    it('allows unlocked prenatal fields', () => {
        assert.equal(isPrenatalVisitLocked(fieldWithClosest(false)), false);
    });
});
