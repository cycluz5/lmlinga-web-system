/**
 * LMLinga OFFLINE-7 service worker.
 *
 * Production URL: /sw.js (bundled, unhashed). Does not replace OFFLINE-6.
 */

import { createServiceWorkerRuntime } from './offline-sw-runtime.js';

const runtime = createServiceWorkerRuntime({
    origin: self.location.origin,
    caches,
    clients,
    fetch: (request, init) => fetch(request, init),
    skipWaiting: () => self.skipWaiting(),
    clientsClaim: () => self.clients.claim(),
});

self.addEventListener('install', (event) => {
    event.waitUntil(runtime.install());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(runtime.activate());
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        runtime.handleFetch(event.request).catch(() => {
            // Navigation may use the offline HTML shell; CSS/JS/fonts must not.
            if (event.request.mode === 'navigate') {
                return runtime.fallbackResponse();
            }
            return runtime.assetUnavailableResponse
                ? runtime.assetUnavailableResponse()
                : new Response('', { status: 504, headers: { 'Cache-Control': 'no-store' } });
        }),
    );
});

self.addEventListener('message', (event) => {
    const data = event.data;
    if (!data || typeof data !== 'object') {
        return;
    }
    event.waitUntil(
        runtime.handleMessage(data, { source: event.source, ports: event.ports }).then((result) => {
            event.ports?.[0]?.postMessage(result && typeof result === 'object' ? { ok: true, ...result } : { ok: true });
        }),
    );
});
