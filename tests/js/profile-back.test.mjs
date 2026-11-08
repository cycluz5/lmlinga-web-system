/**
 * Hybrid Profile Back: same-origin history.back() vs Dashboard href.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(path.resolve('resources/js/pages/profile.js')).href;
const {
    bindProfileBackNavigation,
    shouldHistoryBackFromReferrer,
} = await import(moduleUrl);

const PROFILE = 'http://127.0.0.1:8000/profile';
const DASHBOARD = 'http://127.0.0.1:8000/dashboard';

function clickEvent() {
    let prevented = false;

    return {
        preventDefault() {
            prevented = true;
        },
        wasPrevented() {
            return prevented;
        },
    };
}

function bindFixture(referrer, currentHref = PROFILE) {
    const listeners = [];
    const link = {
        href: DASHBOARD,
        addEventListener(type, fn) {
            if (type === 'click') {
                listeners.push(fn);
            }
        },
        click() {
            const event = clickEvent();
            listeners.forEach((fn) => fn(event));
            return event;
        },
    };

    const root = {
        querySelector(selector) {
            return selector.includes('data-profile-back') ? link : null;
        },
    };

    let historyBackCalls = 0;
    bindProfileBackNavigation(root, {
        getReferrer: () => referrer,
        location: { href: currentHref },
        historyBack() {
            historyBackCalls += 1;
        },
    });

    return {
        link,
        click() {
            return link.click();
        },
        historyBackCount() {
            return historyBackCalls;
        },
    };
}

describe('profile back navigation', () => {
    it('uses history.back for a same-origin module referrer', () => {
        assert.equal(
            shouldHistoryBackFromReferrer('http://127.0.0.1:8000/user-management', { href: PROFILE }),
            true,
        );

        const fixture = bindFixture('http://127.0.0.1:8000/user-management');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), true);
        assert.equal(fixture.historyBackCount(), 1);
        assert.equal(fixture.link.href, DASHBOARD);
    });

    it('falls back to Dashboard when referrer is missing', () => {
        const fixture = bindFixture('');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
        assert.equal(fixture.link.href, DASHBOARD);
    });

    it('falls back to Dashboard for an external-origin referrer', () => {
        const fixture = bindFixture('https://evil.example/phish');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
    });

    it('falls back to Dashboard for /profile referrer', () => {
        const fixture = bindFixture('http://127.0.0.1:8000/profile');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
    });

    it('falls back to Dashboard for /login referrer', () => {
        const fixture = bindFixture('http://127.0.0.1:8000/login');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
    });

    it('falls back to Dashboard for /logout referrer', () => {
        const fixture = bindFixture('http://127.0.0.1:8000/logout');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
    });

    it('falls back to Dashboard for /change-password referrer', () => {
        const fixture = bindFixture('http://127.0.0.1:8000/change-password');
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
    });

    it('falls back to Dashboard for the current Profile URL', () => {
        const fixture = bindFixture('http://127.0.0.1:8000/profile?tab=1', PROFILE);
        const event = fixture.click();

        assert.equal(event.wasPrevented(), false);
        assert.equal(fixture.historyBackCount(), 0);
    });
});
