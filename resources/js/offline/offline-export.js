/**
 * Block export/download while offline. Online HTTP and Spot Mapping Save Map
 * stay on their existing handlers. Never reads IndexedDB / the mutation queue.
 */

import { isClientOffline } from './offline-forms.js';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from './offline-status.js';

export const EXPORT_UNAVAILABLE_MESSAGE = 'Export is available when online.';

const TRIGGER_SPECS = [
    { attr: 'data-hh-export', kind: 'household-profiling' },
    { attr: 'data-eh-export', kind: 'environmental-health' },
    { attr: 'data-eh-export-toggle', kind: 'environmental-health' },
    { attr: 'data-hr-cc-export', kind: 'child-care' },
    { attr: 'data-hr-ra-export', kind: 'risk-assessment' },
    { attr: 'data-hr-fp-export', kind: 'family-planning' },
    { attr: 'data-hr-fp-nr-export', kind: 'family-planning-nr' },
    { attr: 'data-hr-mc-export', kind: 'maternal' },
    { attr: 'data-hr-death-export', kind: 'death' },
    { attr: 'data-hr-dw-export', kind: 'deworming' },
    { attr: 'data-hr-va-export', kind: 'vitamin-a' },
    { attr: 'data-hr-ot-export', kind: 'operation-timbang' },
    { attr: 'data-death-certificate-download', kind: 'certificate' },
    { attr: 'data-spot-map-export', kind: 'spot-mapping' },
    { attr: 'data-spot-map-export-toggle', kind: 'spot-mapping' },
    { attr: 'data-spot-map-export-zone-image', kind: 'spot-mapping' },
    { attr: 'data-spot-map-export-zone-pdf', kind: 'spot-mapping' },
];

const LOCK_ATTR = 'data-offline-export-locked';

/**
 * @param {EventTarget | null | undefined} target
 * @returns {{ el: Element, kind: string } | null}
 */
export function findExportTrigger(target) {
    if (!target || typeof target.closest !== 'function') {
        return null;
    }

    for (const spec of TRIGGER_SPECS) {
        const el = target.closest(`[${spec.attr}]`);
        if (el) {
            return { el, kind: spec.kind };
        }
    }

    const link = target.closest('a');
    const href = String(link?.getAttribute?.('href') || link?.href || '');
    if (link && /\/certificate(?:\/|\?|$)/i.test(href)) {
        return { el: link, kind: 'certificate' };
    }

    return null;
}

function emitNotice(message, options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const detail = { message, source: 'export' };
    if (win?.LmlingaOffline && typeof win.LmlingaOffline.emit === 'function') {
        win.LmlingaOffline.emit(OFFLINE_EVENTS.NOTICE, detail);
        return;
    }
    if (typeof win?.dispatchEvent === 'function') {
        const event =
            typeof CustomEvent === 'function'
                ? new CustomEvent(OFFLINE_EVENTS.NOTICE, { detail })
                : { type: OFFLINE_EVENTS.NOTICE, detail };
        win.dispatchEvent(event);
    }
}

export function listExportControls(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return [];
    }

    const seen = new Set();
    const controls = [];
    TRIGGER_SPECS.forEach((spec) => {
        const found = root.querySelectorAll(`[${spec.attr}]`);
        [...found].forEach((el) => {
            if (!seen.has(el)) {
                seen.add(el);
                controls.push(el);
            }
        });
    });
    return controls;
}

function isAnchor(el) {
    return String(el?.tagName || '').toLowerCase() === 'a';
}

/**
 * @param {ParentNode | null | undefined} root
 * @param {boolean} offline
 */
export function syncExportControls(root, offline) {
    listExportControls(root).forEach((el) => {
        if (offline) {
            if (el.getAttribute(LOCK_ATTR) === '1') {
                return;
            }
            if (!isAnchor(el) && el.disabled) {
                return;
            }
            el.setAttribute(LOCK_ATTR, '1');
            el.setAttribute('aria-disabled', 'true');
            if (isAnchor(el)) {
                el.setAttribute('tabindex', '-1');
            } else {
                el.disabled = true;
            }
            return;
        }

        if (el.getAttribute(LOCK_ATTR) !== '1') {
            return;
        }
        el.removeAttribute(LOCK_ATTR);
        el.removeAttribute('aria-disabled');
        if (isAnchor(el)) {
            if (el.getAttribute('tabindex') === '-1') {
                el.removeAttribute('tabindex');
            }
        } else {
            el.disabled = false;
        }
    });
}

/**
 * @param {Event} event
 * @param {object} [options]
 */
export function handleOfflineExportClick(event, options = {}) {
    const trigger = findExportTrigger(event?.target);
    if (!trigger) {
        return { intercepted: false };
    }

    if (!isClientOffline(options)) {
        return { intercepted: false, trigger };
    }

    event.preventDefault?.();
    event.stopImmediatePropagation?.();
    event.stopPropagation?.();

    emitNotice(OFFLINE_MESSAGES.exportUnavailable, options);
    return {
        intercepted: true,
        trigger,
        downloaded: false,
        message: OFFLINE_MESSAGES.exportUnavailable,
    };
}

/**
 * @param {Document | ParentNode} doc
 * @param {object} [options]
 */
export function initOfflineExports(doc = typeof document !== 'undefined' ? document : null, options = {}) {
    if (!doc || typeof doc.addEventListener !== 'function') {
        return;
    }
    if (doc.__lmlingaOfflineExportBound) {
        return;
    }
    doc.__lmlingaOfflineExportBound = true;

    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);

    const refresh = () => {
        syncExportControls(doc, isClientOffline({ ...options, document: doc, window: win }));
    };

    doc.addEventListener(
        'click',
        (event) => {
            handleOfflineExportClick(event, { ...options, document: doc, window: win });
        },
        true,
    );

    win.addEventListener?.('offline', refresh);
    win.addEventListener?.('online', refresh);
    win.addEventListener?.(OFFLINE_EVENTS.OFFLINE, refresh);
    win.addEventListener?.(OFFLINE_EVENTS.ONLINE, refresh);

    refresh();
}

if (typeof document !== 'undefined') {
    initOfflineExports(document);
}
