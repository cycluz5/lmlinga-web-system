/**
 * Authenticated authorized-page warmup — page layer.
 */

import assert from 'node:assert/strict';
import path from 'node:path';
import { afterEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const warmupUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-warmup.js')).href;

const { MESSAGE_WARMUP_CORE, WARMUP_PATHS_ADMIN, WARMUP_PATHS_SHARED } = await import(policyUrl);
const {
    canWarmCoreFieldPages,
    confirmSessionAndWarmCorePages,
    resetCoreWarmupForTests,
} = await import(warmupUrl);

afterEach(() => {
    resetCoreWarmupForTests();
});

function statusOk(overrides = {}) {
    return {
        ok: true,
        status: 200,
        json: async () => ({
            ok: true,
            is_active: true,
            csrf_token: 'must-not-be-cached',
            user_id: 7,
            role: 'bhw',
            ...overrides,
        }),
    };
}

describe('offline authorized page warmup page layer', () => {
    it('asks the worker to warm only after /offline/status succeeds and never sends a path list', async () => {
        const messages = [];
        const worker = {
            postMessage(payload) {
                messages.push(payload);
            },
        };
        const urls = [];
        const fetchImpl = async (url, init) => {
            urls.push({ url, init });
            return statusOk();
        };

        const result = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: fetchImpl,
            statusUrl: '/offline/status',
        });

        assert.equal(result.ok, true);
        assert.equal(result.role, 'bhw');
        assert.equal(urls.length, 1);
        assert.equal(urls[0].url, '/offline/status');
        assert.equal(urls[0].init.method, 'GET');
        assert.equal(urls[0].init.headers.Accept, 'application/json');
        assert.equal(messages.length, 1);
        assert.equal(messages[0].type, MESSAGE_WARMUP_CORE);
        assert.equal(messages[0].actorId, 7);
        assert.equal(messages[0].role, 'bhw');
        assert.equal(Object.prototype.hasOwnProperty.call(messages[0], 'paths'), false);
        assert.deepEqual(result.paths, [...WARMUP_PATHS_SHARED]);
        WARMUP_PATHS_ADMIN.forEach((path) => {
            assert.equal(result.paths.includes(path), false);
        });
    });

    it('includes admin indexes in the computed warmup set only for admin', async () => {
        const messages = [];
        const worker = {
            postMessage(payload) {
                messages.push(payload);
            },
        };

        const result = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: async () => statusOk({ role: 'admin' }),
            statusUrl: '/offline/status',
        });

        assert.equal(result.ok, true);
        assert.equal(result.role, 'admin');
        assert.deepEqual(result.paths, [...WARMUP_PATHS_SHARED, ...WARMUP_PATHS_ADMIN]);
        assert.equal(messages[0].role, 'admin');
        assert.equal(Object.prototype.hasOwnProperty.call(messages[0], 'paths'), false);
    });

    it('does not warm until the authenticated session is confirmed', async () => {
        const messages = [];
        const worker = {
            postMessage(payload) {
                messages.push(payload);
            },
        };
        let statusHits = 0;
        const fetchImpl = async (url) => {
            statusHits += 1;
            assert.equal(url, '/offline/status');
            return {
                ok: false,
                status: 401,
                json: async () => ({ ok: false, code: 'SESSION_EXPIRED' }),
            };
        };

        const result = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: fetchImpl,
            statusUrl: '/offline/status',
        });

        assert.equal(result.ok, false);
        assert.equal(result.reason, 'session');
        assert.equal(statusHits, 1);
        assert.equal(messages.length, 0);
    });

    it('rejects a status actor that does not match the page actor', async () => {
        const messages = [];
        const worker = { postMessage(payload) { messages.push(payload); } };
        const result = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: async () => statusOk({ user_id: 99, role: 'admin' }),
            statusUrl: '/offline/status',
        });
        assert.equal(result.ok, false);
        assert.equal(result.reason, 'actor-mismatch');
        assert.equal(messages.length, 0);
    });

    it('does not put login, password, or write routes on the warmup list', async () => {
        const messages = [];
        const worker = {
            postMessage(payload) {
                messages.push(payload);
            },
        };

        const result = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: async () => statusOk({ role: 'bhw' }),
            statusUrl: '/offline/status',
        });

        const payload = JSON.stringify(messages);
        [
            '/login',
            '/change-password',
            '/user-management',
            '/household-requests',
            '/death-requests',
        ].forEach((path) => {
            assert.equal(payload.includes(path), false);
            assert.equal(result.paths.includes(path), false);
        });
        WARMUP_PATHS_SHARED.forEach((path) => {
            assert.equal(result.paths.includes(path), true);
        });
        assert.equal(result.paths.includes('/household-profiling/create'), true);
    });

    it('forwards a managed actor avatar URL after session confirmation', async () => {
        const messages = [];
        const worker = { postMessage(payload) { messages.push(payload); } };
        const avatarUrl = '/storage/health-workers/profile-photos/abc.png';
        const result = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: async () => statusOk(),
            statusUrl: '/offline/status',
            avatarUrl,
        });
        assert.equal(result.ok, true);
        assert.equal(messages[0].avatarUrl, avatarUrl);
    });

    it('skips warmup without an actor, worker, or while navigator is offline', async () => {
        const worker = { postMessage() {} };
        let hits = 0;
        const fetchImpl = async () => {
            hits += 1;
            return statusOk();
        };

        assert.equal(canWarmCoreFieldPages({ actorId: 0, worker, navigator: { onLine: true } }), false);
        assert.equal(canWarmCoreFieldPages({ actorId: 7, worker: null, navigator: { onLine: true } }), false);
        assert.equal(canWarmCoreFieldPages({ actorId: 7, worker, navigator: { onLine: false } }), false);

        const noActor = await confirmSessionAndWarmCorePages({
            actorId: 0,
            worker,
            navigator: { onLine: true },
            fetch: fetchImpl,
        });
        const offline = await confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: false },
            fetch: fetchImpl,
        });

        assert.equal(noActor.ok, false);
        assert.equal(offline.ok, false);
        assert.equal(hits, 0);
    });

    it('shares one in-flight warmup so duplicate callers do not double-probe', async () => {
        let statusHits = 0;
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        const worker = { postMessage() {} };
        const fetchImpl = async () => {
            statusHits += 1;
            await gate;
            return statusOk();
        };

        const first = confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: fetchImpl,
            statusUrl: '/offline/status',
        });
        const second = confirmSessionAndWarmCorePages({
            actorId: 7,
            worker,
            navigator: { onLine: true },
            fetch: fetchImpl,
            statusUrl: '/offline/status',
        });

        release();
        const [a, b] = await Promise.all([first, second]);
        assert.equal(statusHits, 1);
        assert.equal(a.ok, true);
        assert.equal(b.ok, true);
        assert.equal(a, b);
    });
});
