/**
 * Minimal IndexedDB fake for Node tests. Shared registry so re-opening the
 * same database name simulates a browser reload.
 */

const databases = new Map();

function later(fn) {
    queueMicrotask(fn);
}

function clone(value) {
    return value == null ? value : JSON.parse(JSON.stringify(value));
}

class FakeIDBRequest {
    constructor() {
        this.result = undefined;
        this.error = null;
        this.onsuccess = null;
        this.onerror = null;
        this.readyState = 'pending';
    }
}

class FakeIDBIndex {
    constructor(store, name, keyPath) {
        this.store = store;
        this.name = name;
        this.keyPath = keyPath;
    }

    get(query) {
        const match = [...this.store.records.values()].find((row) => row[this.keyPath] === query);
        return this.store.tx.requestOf(() => clone(match));
    }
}

class FakeIDBObjectStore {
    constructor(table, tx) {
        this.table = table;
        this.tx = tx;
        this.records = table.records;
        this.keyPath = table.keyPath;
        this.indexRecords = table.indexes;
    }

    createIndex(name, keyPath) {
        this.indexRecords.set(name, { keyPath, unique: false });
        return new FakeIDBIndex(this, name, keyPath);
    }

    index(name) {
        const meta = this.indexRecords.get(name);
        if (!meta) {
            throw new Error(`index ${name} not found`);
        }
        return new FakeIDBIndex(this, name, meta.keyPath);
    }

    put(value) {
        const record = clone(value);
        const key = record[this.keyPath];
        return this.tx.requestOf(() => {
            this.records.set(key, record);
            return record;
        });
    }

    get(key) {
        return this.tx.requestOf(() => clone(this.records.get(key)));
    }

    getAll() {
        return this.tx.requestOf(() => [...this.records.values()].map((row) => clone(row)));
    }

    delete(key) {
        return this.tx.requestOf(() => {
            this.records.delete(key);
            return undefined;
        });
    }
}

class FakeIDBTransaction {
    constructor(db, storeName, mode) {
        this.db = db;
        this.mode = mode;
        this.oncomplete = null;
        this.onerror = null;
        this.onabort = null;
        this.pending = 0;
        this.completed = false;
        this._store = new FakeIDBObjectStore(db.table(storeName), this);
        this._flush = null;
    }

    objectStore(name) {
        if (name && name !== this._store.table.name) {
            this._store = new FakeIDBObjectStore(this.db.table(name), this);
        }
        return this._store;
    }

    requestOf(work) {
        const request = new FakeIDBRequest();
        this.pending += 1;
        later(() => {
            try {
                request.result = work();
                request.readyState = 'done';
                if (typeof request.onsuccess === 'function') {
                    request.onsuccess({ target: request });
                }
            } catch (error) {
                request.error = error;
                request.readyState = 'done';
                if (typeof request.onerror === 'function') {
                    request.onerror({ target: request });
                }
                if (typeof this.onerror === 'function') {
                    this.onerror({ target: this });
                }
            } finally {
                this.pending -= 1;
                this.scheduleComplete();
            }
        });
        return request;
    }

    scheduleComplete() {
        if (this._flush) {
            clearTimeout(this._flush);
        }
        this._flush = setTimeout(() => {
            if (this.pending === 0 && !this.completed) {
                this.completed = true;
                if (typeof this.oncomplete === 'function') {
                    this.oncomplete({ target: this });
                }
            }
        }, 0);
    }
}

class FakeIDBDatabase {
    constructor(name, version, tables) {
        this.name = name;
        this.version = version;
        this._tables = tables;
        this.objectStoreNames = {
            _names: new Set(tables.keys()),
            contains(storeName) {
                return this._names.has(storeName);
            },
        };
    }

    createObjectStore(name, options = {}) {
        const table = this._tables.get(name) || {
            name,
            keyPath: options.keyPath,
            records: new Map(),
            indexes: new Map(),
        };
        table.name = name;
        table.keyPath = options.keyPath || table.keyPath;
        table.records = table.records || new Map();
        table.indexes = table.indexes || new Map();
        this._tables.set(name, table);
        this.objectStoreNames._names.add(name);
        return {
            createIndex(indexName, keyPath) {
                table.indexes.set(indexName, { keyPath, unique: false });
            },
        };
    }

    table(storeName) {
        const table = this._tables.get(storeName);
        if (!table) {
            throw new Error(`store ${storeName} not found`);
        }
        return table;
    }

    transaction(storeName) {
        const name = Array.isArray(storeName) ? storeName[0] : storeName;
        const tx = new FakeIDBTransaction(this, name, 'readwrite');
        tx.scheduleComplete();
        return tx;
    }

    close() {}
}

class FakeIDBOpenDBRequest extends FakeIDBRequest {
    constructor() {
        super();
        this.onupgradeneeded = null;
        this.onblocked = null;
    }
}

export function resetFakeIndexedDB() {
    databases.clear();
}

export function createFakeIndexedDB() {
    return {
        open(name, version = 1) {
            const request = new FakeIDBOpenDBRequest();
            later(() => {
                let entry = databases.get(name);
                const isNew = !entry;
                if (!entry) {
                    entry = {
                        version: 0,
                        tables: new Map(),
                    };
                    databases.set(name, entry);
                }

                const db = new FakeIDBDatabase(name, version, entry.tables);
                request.result = db;

                if (isNew || entry.version < version) {
                    const oldVersion = entry.version;
                    entry.version = version;
                    if (typeof request.onupgradeneeded === 'function') {
                        request.onupgradeneeded({ target: request, oldVersion, newVersion: version });
                    }
                    entry.tables = db._tables;
                }

                if (typeof request.onsuccess === 'function') {
                    request.onsuccess({ target: request });
                }
            });
            return request;
        },
        deleteDatabase(name) {
            databases.delete(name);
            const request = new FakeIDBRequest();
            later(() => {
                if (typeof request.onsuccess === 'function') {
                    request.onsuccess({ target: request });
                }
            });
            return request;
        },
    };
}
