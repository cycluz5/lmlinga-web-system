/**
 * In-app offline unavailable panel — keeps dashboard sidebar/topbar,
 * replaces only #main-content. Prefer this over the full-document SW fallback
 * for recognized LMLinga module navigations.
 */

/**
 * @param {{ kind?: string, module?: string, support?: string }} classification
 */
export function unavailableCopy(classification = {}) {
    const moduleName = String(classification.module || 'This page').trim() || 'This page';
    const kind = classification.kind || 'unknown';

    if (kind === 'online-only') {
        return {
            title: `${moduleName} isn’t available while you’re offline.`,
            body: String(classification.support || 'Reconnect to the internet to access this feature.'),
            reason: 'online-only',
        };
    }

    if (kind === 'offline-capable') {
        return {
            title: 'This page isn’t available offline yet.',
            body: String(
                classification.support
                    || 'Reconnect to the internet and open this page once to make it available for offline use.',
            ),
            reason: 'not-cached',
        };
    }

    return {
        title: `${moduleName} isn’t available while you’re offline.`,
        body: String(classification.support || 'Reconnect to the internet to open this area of LMLinga.'),
        reason: 'unknown',
    };
}

/**
 * @param {ParentNode|Document|null} doc
 * @param {{ kind?: string, module?: string, support?: string }} classification
 * @returns {boolean}
 */
export function showOfflineUnavailableInShell(doc, classification = {}) {
    const root = doc && typeof doc.querySelector === 'function' ? doc : (typeof document !== 'undefined' ? document : null);
    if (!root) {
        return false;
    }

    const main = root.querySelector('#main-content') || root.querySelector('.lml-dashboard__content');
    if (!main) {
        return false;
    }

    const copy = unavailableCopy(classification);
    const title = escapeHtml(copy.title);
    const body = escapeHtml(copy.body);

    main.innerHTML = `
<section class="lml-offline-unavailable" data-lml-offline-unavailable data-reason="${escapeHtml(copy.reason)}" aria-live="polite">
    <div class="lml-offline-unavailable__card">
        <i class="bi bi-wifi-off lml-offline-unavailable__icon" aria-hidden="true"></i>
        <h1 class="lml-offline-unavailable__title">${title}</h1>
        <p class="lml-offline-unavailable__body">${body}</p>
        <button type="button" class="btn btn-primary lml-focus-ring lml-offline-unavailable__retry" data-lml-offline-unavailable-retry>
            Try Again
        </button>
    </div>
</section>`;

    const retry = main.querySelector('[data-lml-offline-unavailable-retry]');
    retry?.addEventListener('click', () => {
        if (typeof window !== 'undefined' && window.navigator && window.navigator.onLine === false) {
            retry.blur();
            return;
        }
        if (typeof window !== 'undefined' && typeof window.location?.reload === 'function') {
            window.location.reload();
        }
    });

    return true;
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
