/**
 * Actor-scoped local member identity map in Cache Storage.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { createMemoryCaches } from './support/fake-caches.mjs';

const mapUrl = pathToFileURL(path.resolve('resources/js/offline/offline-identity-map.js')).href;
const {
    rememberLocalMember,
    readMemberIdentityMap,
    reconcileLocalMember,
    resolveMemberHref,
} = await import(mapUrl);

const ORIGIN = 'https://lmlinga.test';

describe('offline member identity map', () => {
    it('stores queued member fields per actor and rewrites hrefs after sync', async () => {
        const caches = createMemoryCaches();
        await rememberLocalMember('MB-L-abc123', {
            household_no: 'HH-121',
            first_name: 'Ana',
            last_name: 'Santos',
            relation: 'Spouse',
        }, { actorId: 7, caches, origin: ORIGIN });

        const actorA = await readMemberIdentityMap({ actorId: 7, caches, origin: ORIGIN });
        const actorB = await readMemberIdentityMap({ actorId: 9, caches, origin: ORIGIN });
        assert.equal(actorA['MB-L-abc123'].last_name, 'Santos');
        assert.equal(actorB['MB-L-abc123'], undefined);

        await reconcileLocalMember('MB-L-abc123', 'MB-044', { actorId: 7, caches, origin: ORIGIN });
        const mapped = await readMemberIdentityMap({ actorId: 7, caches, origin: ORIGIN });
        assert.equal(mapped['MB-L-abc123'].member_no, 'MB-044');
        assert.equal(
            resolveMemberHref('/household-profiling/HH-121/members/MB-L-abc123', mapped, ORIGIN),
            '/household-profiling/HH-121/members/MB-044',
        );
        assert.equal(
            resolveMemberHref('/household-profiling/HH-121/members/MB-L-other', mapped, ORIGIN),
            '/household-profiling/HH-121/members/MB-L-other',
        );
    });
});
