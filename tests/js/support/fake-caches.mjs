/**
 * In-memory Cache Storage stand-in for OFFLINE-7 runtime tests.
 */

function requestKey(request) {
    if (!request) {
        return '';
    }
    if (typeof request === 'string') {
        return request;
    }
    return String(request.url || '');
}

export function createMemoryCaches() {
    const stores = new Map();

    function storeFor(name) {
        if (!stores.has(name)) {
            stores.set(name, new Map());
        }
        return stores.get(name);
    }

    return {
        async open(name) {
            const store = storeFor(name);
            return {
                async match(request) {
                    const hit = store.get(requestKey(request));
                    return hit ? hit.clone() : undefined;
                },
                async put(request, response) {
                    store.set(requestKey(request), response.clone());
                },
                async delete(request) {
                    return store.delete(requestKey(request));
                },
                async keys() {
                    return [...store.keys()].map((url) => new Request(url));
                },
            };
        },
        async keys() {
            return [...stores.keys()];
        },
        async delete(name) {
            return stores.delete(name);
        },
        async match(request, options = {}) {
            if (options.cacheName) {
                const cache = await this.open(options.cacheName);
                return cache.match(request);
            }
            for (const name of stores.keys()) {
                const cache = await this.open(name);
                const hit = await cache.match(request);
                if (hit) {
                    return hit;
                }
            }
            return undefined;
        },
    };
}
