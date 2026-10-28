/**
 * Since 1.15: JSON maps in localStorage for per-viewer conveniences (folded filters, Gantt scale, time columns). Every
 * access is guarded: private windows, blocked site data or quota errors read as "nothing saved" and are ignored.
 */
export type StoredMap = Record<string, unknown>;

export function readMap(key: string, storage: Storage | null = safeStorage()): StoredMap {
    try {
        const raw = storage?.getItem(key) ?? null;
        if (raw === null || raw === '') {
            return {};
        }
        const parsed = JSON.parse(raw) as unknown;

        return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as StoredMap) : {};
    } catch {
        return {};
    }
}

export function writeEntry(key: string, id: string, value: unknown, storage: Storage | null = safeStorage()): void {
    if (id === '' || storage === null) {
        return;
    }
    try {
        const map = readMap(key, storage);
        map[id] = value;
        storage.setItem(key, JSON.stringify(map));
    } catch {
        // Quota or private mode: the preference is simply not remembered.
    }
}

export function safeStorage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}
