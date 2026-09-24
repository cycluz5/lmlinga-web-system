/**
 * Browser-visible empty-field clearing for resources/js/pages/staff-login.js
 *
 * Runtime: Node.js built-in test runner — no Vitest/Jest/Playwright.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const sourcePath = path.resolve('resources/js/pages/staff-login.js');
const source = readFileSync(sourcePath, 'utf8');
const moduleUrl = pathToFileURL(sourcePath).href;

const {
    clearStaffLoginFields,
    initStaffLoginEmptyFields,
    STAFF_LOGIN_CLEAR_DELAYS_MS,
} = await import(moduleUrl);

function createField(id, name, value = '') {
    const listeners = {};
    return {
        id,
        name,
        value,
        addEventListener(type, fn) {
            listeners[type] = listeners[type] || [];
            listeners[type].push(fn);
        },
        dispatch(type, event) {
            (listeners[type] || []).forEach((fn) => fn(event));
        },
    };
}

function createLoginFixture(initialEmail = '', initialPassword = '') {
    const email = createField('email', 'email', initialEmail);
    const password = createField('password', 'password', initialPassword);
    const formListeners = {};
    const winListeners = {};
    const docListeners = {};

    const form = {
        querySelector(selector) {
            if (selector.includes('email')) {
                return email;
            }
            if (selector.includes('password')) {
                return password;
            }
            return null;
        },
        addEventListener(type, fn) {
            formListeners[type] = formListeners[type] || [];
            formListeners[type].push(fn);
        },
        dispatch(type, event) {
            (formListeners[type] || []).forEach((fn) => fn(event));
        },
    };

    const root = {
        querySelectorAll() {
            return [form];
        },
    };

    const win = {
        addEventListener(type, fn) {
            winListeners[type] = winListeners[type] || [];
            winListeners[type].push(fn);
        },
        dispatch(type, event) {
            (winListeners[type] || []).forEach((fn) => fn(event));
        },
    };

    const doc = {
        addEventListener(type, fn) {
            docListeners[type] = docListeners[type] || [];
            docListeners[type].push(fn);
        },
        dispatch(type, event) {
            (docListeners[type] || []).forEach((fn) => fn(event));
        },
    };

    const tasks = [];
    let nextId = 1;
    let now = 0;
    const clock = {
        setTimeout(fn, delay) {
            const id = nextId++;
            tasks.push({ id, fn, at: now + delay, cancelled: false, ran: false });
            return id;
        },
        clearTimeout(id) {
            const task = tasks.find((item) => item.id === id);
            if (task) {
                task.cancelled = true;
            }
        },
        advance(ms) {
            now += ms;
            tasks
                .filter((task) => !task.cancelled && !task.ran && task.at <= now)
                .forEach((task) => {
                    task.ran = true;
                    task.fn();
                });
        },
    };

    return { email, password, form, root, win, doc, clock };
}

describe('staff-login source contract', () => {
    it('clears on load and pageshow without credential storage APIs', () => {
        assert.match(source, /DOMContentLoaded/);
        assert.match(source, /addEventListener\('load'/);
        assert.match(source, /addEventListener\('pageshow'/);
        assert.match(source, /event\?\.persisted/);
        assert.equal(source.includes('localStorage'), false);
        assert.equal(source.includes('sessionStorage'), false);
        assert.equal(source.includes('document.cookie'), false);
        assert.equal(/setInterval\s*\(/.test(source), false);
    });

    it('uses finite post-render delays rather than an infinite timer', () => {
        assert.deepEqual(STAFF_LOGIN_CLEAR_DELAYS_MS, [0, 50, 150, 400, 800]);
        assert.ok(STAFF_LOGIN_CLEAR_DELAYS_MS.every((delay) => delay <= 800));
    });
});

describe('staff-login browser-visible clearing', () => {
    it('clears identity and password immediately on init', () => {
        const fx = createLoginFixture('maria.santos', 'saved-password');

        initStaffLoginEmptyFields(fx.root, {
            window: fx.win,
            document: fx.doc,
            setTimeout: fx.clock.setTimeout,
            clearTimeout: fx.clock.clearTimeout,
        });

        assert.equal(fx.email.value, '');
        assert.equal(fx.password.value, '');
    });

    it('clears credentials injected before load', () => {
        const fx = createLoginFixture();

        initStaffLoginEmptyFields(fx.root, {
            window: fx.win,
            document: fx.doc,
            setTimeout: fx.clock.setTimeout,
            clearTimeout: fx.clock.clearTimeout,
        });

        fx.email.value = 'maria.santos';
        fx.password.value = 'saved-password';
        fx.doc.dispatch('DOMContentLoaded', {});
        fx.win.dispatch('load', {});

        assert.equal(fx.email.value, '');
        assert.equal(fx.password.value, '');
    });

    it('clears delayed autofill that arrives after paint', () => {
        const fx = createLoginFixture();

        initStaffLoginEmptyFields(fx.root, {
            window: fx.win,
            document: fx.doc,
            setTimeout: fx.clock.setTimeout,
            clearTimeout: fx.clock.clearTimeout,
        });

        fx.email.value = 'maria.santos';
        fx.password.value = 'saved-password';
        fx.clock.advance(50);

        assert.equal(fx.email.value, '');
        assert.equal(fx.password.value, '');
    });

    it('clears pageshow restoration including persisted bfcache', () => {
        const fx = createLoginFixture();

        initStaffLoginEmptyFields(fx.root, {
            window: fx.win,
            document: fx.doc,
            setTimeout: fx.clock.setTimeout,
            clearTimeout: fx.clock.clearTimeout,
        });

        fx.email.value = 'maria.santos';
        fx.password.value = 'saved-password';
        fx.win.dispatch('pageshow', { persisted: true });

        assert.equal(fx.email.value, '');
        assert.equal(fx.password.value, '');
    });

    it('does not keep erasing after the user begins typing', () => {
        const fx = createLoginFixture();

        initStaffLoginEmptyFields(fx.root, {
            window: fx.win,
            document: fx.doc,
            setTimeout: fx.clock.setTimeout,
            clearTimeout: fx.clock.clearTimeout,
        });

        fx.form.dispatch('keydown', { type: 'keydown', key: 'm', isTrusted: true });
        fx.email.value = 'm';
        fx.clock.advance(800);
        fx.win.dispatch('load', {});

        assert.equal(fx.email.value, 'm');
        assert.equal(fx.password.value, '');
    });

    it('clearStaffLoginFields only writes empty strings', () => {
        const email = { value: 'someone' };
        const password = { value: 'secret' };
        const form = {
            querySelector(selector) {
                if (selector.includes('email')) {
                    return email;
                }
                return password;
            },
        };

        clearStaffLoginFields(form);
        assert.equal(email.value, '');
        assert.equal(password.value, '');
    });
});
