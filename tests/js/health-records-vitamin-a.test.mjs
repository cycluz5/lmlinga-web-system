/**
 * Vitamin A Health Records zone navigation (server remains coverage authority).
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(path.resolve('resources/js/pages/health-records-vitamin-a.js')).href;
const { vitaminAMonitoringUrl } = await import(moduleUrl);

describe('health records vitamin A zone URL', () => {
    it('adds a zone query for a specific zone', () => {
        assert.equal(
            vitaminAMonitoringUrl('/health-records/child-care/vitamin-a', 'Zone 1'),
            '/health-records/child-care/vitamin-a?zone=Zone+1',
        );
    });

    it('clears zone for All Zones', () => {
        assert.equal(
            vitaminAMonitoringUrl('/health-records/child-care/vitamin-a?zone=Zone+1', 'all'),
            '/health-records/child-care/vitamin-a',
        );
    });

    it('adds year and month queries alongside zone', () => {
        const url = vitaminAMonitoringUrl(
            '/health-records/child-care/vitamin-a',
            'Zone 1',
            'http://127.0.0.1',
            '2026',
            '09',
        );
        assert.equal(url, '/health-records/child-care/vitamin-a?zone=Zone+1&year=2026&month=09');
    });

    it('clears year and month for All Years / All Months', () => {
        const url = vitaminAMonitoringUrl(
            '/health-records/child-care/vitamin-a?year=2026&month=09',
            'all',
            'http://127.0.0.1',
            'all',
            'all',
        );
        assert.equal(url, '/health-records/child-care/vitamin-a');
    });
});
