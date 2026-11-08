/**
 * LMLinga durable offline queue — IndexedDB wrapper.
 *
 * Browser IndexedDB only. Never falls back to localStorage or sessionStorage.
 * Does not persist CSRF tokens, AES keys, passwords, or session cookies.
 */

export const OFFLINE_DB_NAME = 'lmlinga_offline';
export const OFFLINE_DB_VERSION = 4;
export const OPERATIONS_STORE = 'operations';
export const HP_HOUSEHOLDS_STORE = 'hp_households';
export const HP_MEMBERS_STORE = 'hp_members';
export const HP_META_STORE = 'hp_meta';
export const HP_HEALTH_STORE = 'hp_health_records';

/** @type {IDBFactory | null | undefined} */
let injectedFactory;

/**
 * Test seam. Production always uses globalThis.indexedDB.
 *
 * @param {IDBFactory | null | undefined} factory
 */
export function setIndexedDBFactory(factory) {
    injectedFactory = factory;
}

export function resetIndexedDBFactory() {
    injectedFactory = undefined;
}

function indexedDBFactory() {
    if (injectedFactory !== undefined) {
        return injectedFactory;
    }

    if (typeof globalThis !== 'undefined' && globalThis.indexedDB) {
        return globalThis.indexedDB;
    }

    return null;
}

function ensureStore(db, name, keyPath) {
    if (!db.objectStoreNames.contains(name)) {
        db.createObjectStore(name, { keyPath });
    }
}

/**
 * @returns {Promise<IDBDatabase>}
 */
export function openOfflineDb() {
    const factory = indexedDBFactory();
    if (!factory || typeof factory.open !== 'function') {
        return Promise.reject(new Error('indexeddb-unavailable'));
    }

    return new Promise((resolve, reject) => {
        let request;
        try {
            request = factory.open(OFFLINE_DB_NAME, OFFLINE_DB_VERSION);
        } catch (error) {
            reject(error);
            return;
        }

        request.onerror = () => {
            reject(request.error || new Error('indexeddb-open-failed'));
        };

        request.onblocked = () => {
            reject(new Error('indexeddb-blocked'));
        };

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(OPERATIONS_STORE)) {
                const store = db.createObjectStore(OPERATIONS_STORE, { keyPath: 'local_id' });
                store.createIndex('operation_id', 'operation_id', { unique: true });
                store.createIndex('actor_id', 'actor_id', { unique: false });
                store.createIndex('status', 'status', { unique: false });
                store.createIndex('created_at_client', 'created_at_client', { unique: false });
            }
            ensureStore(db, HP_HOUSEHOLDS_STORE, 'id');
            ensureStore(db, HP_MEMBERS_STORE, 'id');
            ensureStore(db, HP_META_STORE, 'id');
            ensureStore(db, HP_HEALTH_STORE, 'id');
        };

        request.onsuccess = () => {
            resolve(request.result);
        };
    });
}

/**
 * @param {IDBDatabase} db
 * @param {'readonly' | 'readwrite'} mode
 * @param {(store: IDBObjectStore) => unknown} work
 * @param {string} [storeName]
 */
export async function withStore(db, mode, work, storeName = OPERATIONS_STORE) {
    let tx;
    try {
        tx = db.transaction(storeName, mode);
    } catch (error) {
        throw error;
    }

    const store = tx.objectStore(storeName);
    const txDone = new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error || new Error('indexeddb-transaction-failed'));
        tx.onabort = () => reject(tx.error || new Error('indexeddb-transaction-aborted'));
    });

    const result = await work(store);
    await txDone;
    return result;
}

/**
 * @param {IDBRequest} request
 */
export function requestValue(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('indexeddb-request-failed'));
    });
}

/**
 * Run work against multiple object stores in one transaction.
 *
 * @param {IDBDatabase} db
 * @param {string[]} storeNames
 * @param {'readonly' | 'readwrite'} mode
 * @param {(stores: Record<string, IDBObjectStore>) => unknown} work
 */
export async function withStores(db, storeNames, mode, work) {
    const names = Array.isArray(storeNames) ? storeNames.filter(Boolean) : [];
    if (!names.length) {
        throw new Error('indexeddb-stores-required');
    }
    let tx;
    try {
        tx = db.transaction(names, mode);
    } catch (error) {
        throw error;
    }
    const stores = {};
    names.forEach((name) => {
        stores[name] = tx.objectStore(name);
    });
    const txDone = new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error || new Error('indexeddb-transaction-failed'));
        tx.onabort = () => reject(tx.error || new Error('indexeddb-transaction-aborted'));
    });
    const result = await work(stores);
    await txDone;
    return result;
}
