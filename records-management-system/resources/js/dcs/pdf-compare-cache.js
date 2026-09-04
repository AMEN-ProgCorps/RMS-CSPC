const DB_NAME = 'dcs-drr-compare';
const DB_VERSION = 1;
const STORE = 'pairs';
const MAX_PAIRS = 24;
const MAX_BYTES = 200 * 1024 * 1024;

/** Same-tab instant restore (IndexedDB is the durable layer). */
const memoryCache = new Map();

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'key' });
                store.createIndex('createdAt', 'createdAt');
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error || new Error('IndexedDB open failed'));
    });
}

export async function hashString(text) {
    const data = new TextEncoder().encode(String(text || ''));
    const digest = await crypto.subtle.digest('SHA-256', data);
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

export async function hashFile(file) {
    if (!file) {
        return '';
    }
    const buffer = await file.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', buffer);
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function estimateBytes(record) {
    let n = 512;
    for (const blob of [...(record.leftPages || []), ...(record.rightPages || [])]) {
        n += blob?.size || 0;
    }
    try {
        n += JSON.stringify(record.alignment || []).length;
    } catch {
        // ignore
    }
    return n;
}

function isCompleteRecord(record) {
    if (!record || !record.key) return false;
    if ((record.pageOffset || 0) < (record.totalPages || 0)) return false;
    if ((record.totalPages || 0) < 1) return false;
    const left = (record.leftPages || []).filter(Boolean).length;
    const right = (record.rightPages || []).filter(Boolean).length;
    return left > 0 || right > 0;
}

export async function getCompareCache(key) {
    if (!key) {
        return null;
    }

    const mem = memoryCache.get(key);
    if (isCompleteRecord(mem)) {
        return mem;
    }

    try {
        const db = await openDb();
        const row = await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).get(key);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => reject(req.error);
            tx.oncomplete = () => db.close();
            tx.onerror = () => {
                db.close();
                reject(tx.error);
            };
        });
        if (isCompleteRecord(row)) {
            memoryCache.set(key, row);
            return row;
        }
        return null;
    } catch {
        return null;
    }
}

export async function putCompareCache(record) {
    if (!record?.key || !isCompleteRecord(record)) {
        return false;
    }

    const payload = {
        ...record,
        createdAt: Date.now(),
    };

    // Always keep an in-memory copy for instant reopen in this tab.
    memoryCache.set(payload.key, payload);

    try {
        const db = await openDb();

        // Read existing rows in a finished transaction (do not await inside a write tx).
        const existing = await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
            tx.oncomplete = () => {};
            tx.onerror = () => reject(tx.error);
        });

        const others = existing.filter((row) => row.key !== payload.key);
        others.sort((a, b) => (a.createdAt || 0) - (b.createdAt || 0));

        let total = others.reduce((sum, row) => sum + estimateBytes(row), 0) + estimateBytes(payload);
        const toDelete = [];
        while (others.length && (others.length + 1 > MAX_PAIRS || total > MAX_BYTES)) {
            const oldest = others.shift();
            total -= estimateBytes(oldest);
            toDelete.push(oldest.key);
        }

        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            const store = tx.objectStore(STORE);
            toDelete.forEach((key) => store.delete(key));
            store.put(payload);
            tx.oncomplete = () => {
                db.close();
                resolve(true);
            };
            tx.onerror = () => {
                db.close();
                reject(tx.error || new Error('IndexedDB put failed'));
            };
            tx.onabort = () => {
                db.close();
                reject(tx.error || new Error('IndexedDB put aborted'));
            };
        });

        return true;
    } catch (err) {
        console.warn('DRR compare cache save failed; using in-memory cache only.', err);
        return false;
    }
}

export function peekMemoryCompareCache(key) {
    const mem = memoryCache.get(key);
    return isCompleteRecord(mem) ? mem : null;
}

export function clearMemoryCompareCache(key) {
    if (key) memoryCache.delete(key);
    else memoryCache.clear();
}

export async function deleteCompareCache(key) {
    if (!key) {
        return false;
    }
    memoryCache.delete(key);
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).delete(key);
            tx.oncomplete = () => {
                db.close();
                resolve(true);
            };
            tx.onerror = () => {
                db.close();
                reject(tx.error);
            };
        });
        return true;
    } catch {
        return false;
    }
}
