/**
 * Local queued member view cache + redirect.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { createMemoryCaches } from './support/fake-caches.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const localUrl = pathToFileURL(path.resolve('resources/js/offline/offline-local-member.js')).href;
const { handleResidentCreateQueued, localMemberViewPath, fillLocalMemberViewRoot } = await import(localUrl);

const ORIGIN = 'https://lmlinga.test';

describe('offline local member view', () => {
    it('caches a view page for the minted local id and redirects there', async () => {
        const caches = createMemoryCaches();
        const assigned = [];
        const result = await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-abc12',
                first_name: 'Ana',
                last_name: 'Santos',
                relation: 'Spouse',
            },
            parent_server: { household_no: 'HH-121' },
        }, {
            actorId: 7,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
        });

        assert.equal(result.ok, true);
        assert.equal(assigned[0], '/household-profiling/HH-121/members/MB-L-abc12');
        const cache = await caches.open(htmlCacheNameForActor(7));
        const hit = await cache.match(new Request(
            navigationCacheUrl(ORIGIN, localMemberViewPath('HH-121', 'MB-L-abc12')),
        ));
        assert.equal(Boolean(hit), true);
        const html = await hit.text();
        assert.match(html, /Ana Santos/);
        assert.doesNotMatch(html, /csrf-token/);
    });

    it('hydrates local-field nodes from queued payload', () => {
        const fields = {};
        const heading = { textContent: '' };
        const root = {
            setAttribute() {},
            querySelector(selector) {
                return selector === '.lml-hh-member-view__name' ? heading : null;
            },
            querySelectorAll(selector) {
                if (selector === '.lml-hh-member-view__item') {
                    return [];
                }
                return [
                    { getAttribute: () => 'name', set textContent(value) { fields.name = value; } },
                    { getAttribute: () => 'last_name', set textContent(value) { fields.last_name = value; } },
                ];
            },
        };
        fillLocalMemberViewRoot(root, { first_name: 'Ana', last_name: 'Santos' }, 'HH-121', 'MB-L1');
        assert.equal(fields.name, 'Ana Santos');
        assert.equal(fields.last_name, 'Santos');
        assert.equal(heading.textContent, 'Ana Santos');
    });
});
